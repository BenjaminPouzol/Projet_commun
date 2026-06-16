<?php
/**
 * Daemon PHP – Lecture série générique multi-capteurs
 *
 * Usage : php php/sensor_daemon.php [--port=COM3] [--team=G9A] [--machine=1]
 *
 * Protocoles supportés (Tiva → PHP) :
 *   TEMPERATURE:23.5        →  °C      (G9B)
 *   HUMIDITE:65.2           →  %       (G9B)
 *   TEMP_HUM:23.5:65.2      →  °C + %  (G9B, trame combinée)
 *   CO2:850                 →  ppm     (G9D)
 *   LUX:400                 →  lux     (G9C)
 *   SON:72.5                →  dB      (G9A)
 *   PROXIMITE:1200          →  ADC     (G9E – met aussi à jour machine_status)
 *
 * Anti-rebond :
 *   – Capteurs continus (temp, hum, CO2, lux, son) : moyenne glissante sur
 *     WINDOW_SIZE lectures, écriture BDD toutes les MIN_INTERVAL secondes.
 *   – Proximité : fenêtre DEBOUNCE_PROX lectures consécutives identiques
 *     (même logique que occupancy_counter.php).
 *
 * Alertes : créées en BDD si une valeur dépasse un seuil, avec anti-spam
 *   (pas de doublon si alerte active < ALERT_COOLDOWN secondes).
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// ── Paramètres CLI ────────────────────────────────────────────────────────────
$cliOpts      = getopt('', ['port::', 'team::', 'machine::']);
$COM_PORT_ARG = $cliOpts['port']    ?? 'COM3';
$TEAM_ID_ARG  = $cliOpts['team']    ?? 'G9X';
$MID_ARG      = (int)($cliOpts['machine'] ?? MACHINE_ID);

const BAUD_RATE      = 9600;
const WINDOW_SIZE    = 3;     // Nb lectures pour la moyenne glissante
const MIN_INTERVAL   = 10;    // Secondes min entre deux écritures BDD par capteur
const DEBOUNCE_PROX  = 5;     // Lectures consécutives pour valider un état proximité
const ALERT_COOLDOWN = 300;   // Secondes avant de recréer la même alerte (5 min)

// ── Seuils d'alerte par type de capteur ──────────────────────────────────────
const SEUILS_CAPTEURS = [
    'TEMPERATURE' => ['min' => 15.0, 'max' => 30.0,   'unite' => '°C'],
    'HUMIDITE'    => ['min' => 30.0, 'max' => 75.0,   'unite' => '%'],
    'CO2'         => ['min' => null,  'max' => 1000.0, 'unite' => 'ppm'],
    'LUX'         => ['min' => 100.0, 'max' => null,   'unite' => 'lux'],
    'SON'         => ['min' => null,  'max' => 90.0,   'unite' => 'dB'],
];

const UNITES = [
    'TEMPERATURE' => '°C',
    'HUMIDITE'    => '%',
    'CO2'         => 'ppm',
    'LUX'         => 'lux',
    'SON'         => 'dB',
    'PROXIMITE'   => 'ADC',
];

// ── État interne du daemon ────────────────────────────────────────────────────
$buffers        = [];   // [type => float[]]  fenêtres de lissage
$derniereEcr    = [];   // [type => int]      timestamps dernières écritures
$derniereAlerte = [];   // [type+dir => int]  timestamps dernières alertes créées
$bufferProx     = [];   // bool[]             fenêtre anti-rebond proximité

// ── Ouverture du port série ───────────────────────────────────────────────────
function ouvrirPort(string $port): mixed
{
    $chemin = (PHP_OS_FAMILY === 'Windows') ? '\\\\.\\'  . $port : $port;
    $fd     = @dio_open($chemin, O_RDONLY | O_NOCTTY);
    if ($fd === false) {
        throw new RuntimeException(
            "Impossible d'ouvrir $port — vérifiez le câble USB et que php_dio est activé dans php.ini"
        );
    }
    dio_tcsetattr($fd, ['baud' => BAUD_RATE, 'bits' => 8, 'stop' => 1, 'parity' => 0]);
    return $fd;
}

// ── Enregistrement d'un capteur continu ──────────────────────────────────────
function enregistrerCapteur(string $type, float $valeur, string $unite, int $machineId, string $teamId): void
{
    global $buffers, $derniereEcr;

    // Moyenne glissante
    $buffers[$type][] = $valeur;
    if (count($buffers[$type]) > WINDOW_SIZE) array_shift($buffers[$type]);
    $moyenne = array_sum($buffers[$type]) / count($buffers[$type]);

    $now = time();
    if (isset($derniereEcr[$type]) && ($now - $derniereEcr[$type]) < MIN_INTERVAL) {
        return; // Trop tôt, on attend
    }
    $derniereEcr[$type] = $now;

    $db = getDB();

    // Mettre à jour la valeur courante (INSERT ... ON DUPLICATE KEY UPDATE)
    $db->prepare("
        INSERT INTO sensor_current (sensor_type, machine_id, team_id, valeur, unite)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur),
                                team_id = VALUES(team_id),
                                unite   = VALUES(unite),
                                last_update = NOW()
    ")->execute([$type, $machineId, $teamId, round($moyenne, 2), $unite]);

    // Ajouter à l'historique
    $db->prepare("
        INSERT INTO sensor_readings (sensor_type, machine_id, team_id, valeur, unite)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$type, $machineId, $teamId, round($moyenne, 2), $unite]);

    verifierAlertes($type, $moyenne, $machineId);

    printf("[%s] %s : %.2f %s\n", date('H:i:s'), $type, $moyenne, $unite);
}

// ── Vérification et création des alertes ─────────────────────────────────────
function verifierAlertes(string $type, float $valeur, int $machineId): void
{
    global $derniereAlerte;

    $seuils = SEUILS_CAPTEURS[$type] ?? null;
    if ($seuils === null) return;

    $checks = [
        'MIN' => ['seuil' => $seuils['min'], 'condition' => $seuils['min'] !== null && $valeur < $seuils['min'],
                  'msg' => fn($s) => "$type trop bas : {$valeur} {$seuils['unite']} (seuil min : $s {$seuils['unite']})"],
        'MAX' => ['seuil' => $seuils['max'], 'condition' => $seuils['max'] !== null && $valeur > $seuils['max'],
                  'msg' => fn($s) => "$type trop élevé : {$valeur} {$seuils['unite']} (seuil max : $s {$seuils['unite']})"],
    ];

    foreach ($checks as $dir => $c) {
        if (!$c['condition']) continue;

        $cle = $type . '_' . $dir;
        $now = time();
        if (isset($derniereAlerte[$cle]) && ($now - $derniereAlerte[$cle]) < ALERT_COOLDOWN) {
            continue; // Anti-spam
        }
        $derniereAlerte[$cle] = $now;

        $msg = ($c['msg'])($c['seuil']);
        getDB()->prepare("
            INSERT INTO sensor_alerts (sensor_type, machine_id, valeur, seuil, direction, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$type, $machineId, $valeur, $c['seuil'], $dir, $msg]);

        echo "  ⚠️  ALERTE : $msg\n";
    }
}

// ── Gestion de la proximité (compatible avec machine_status / machine_log) ───
function traiterProximite(int $valeur, int $machineId, string $teamId): void
{
    global $bufferProx;

    // Zone ambiguë : valeur entre SEUIL_BAS et SEUIL = capteur trop proche.
    // On ignore la lecture pour ne pas basculer vers LIBRE par erreur.
    if ($valeur >= SEUIL_BAS && $valeur < SEUIL) {
        return;
    }

    $occupe = ($valeur >= SEUIL);

    $bufferProx[] = $occupe;
    if (count($bufferProx) > DEBOUNCE_PROX) array_shift($bufferProx);

    // Agir seulement si toutes les lectures du buffer sont identiques
    if (count($bufferProx) < DEBOUNCE_PROX || count(array_unique($bufferProx)) !== 1) {
        return;
    }

    $nouveauStatut = $occupe ? 'OCCUPEE' : 'LIBRE';
    $db = getDB();

    $stmt = $db->prepare('SELECT statut, depuis FROM machine_status WHERE machine_id = ?');
    $stmt->execute([$machineId]);
    $courant = $stmt->fetch();

    if (!$courant || $courant['statut'] === $nouveauStatut) return;

    $db->beginTransaction();
    try {
        // Fin d'occupation → enregistrer la session avec sa durée
        if ($courant['statut'] === 'OCCUPEE' && $nouveauStatut === 'LIBRE') {
            $db->prepare("
                INSERT INTO sessions_occupation (machine_id, debut, fin, duree_sec)
                VALUES (?, ?, NOW(), TIMESTAMPDIFF(SECOND, ?, NOW()))
            ")->execute([$machineId, $courant['depuis'], $courant['depuis']]);
        }

        $db->prepare("
            UPDATE machine_status
            SET statut = ?, valeur_brute = ?, depuis = NOW(), last_update = NOW()
            WHERE machine_id = ?
        ")->execute([$nouveauStatut, $valeur, $machineId]);

        $db->prepare("
            INSERT INTO machine_log (machine_id, statut, valeur_brute) VALUES (?, ?, ?)
        ")->execute([$machineId, $nouveauStatut, $valeur]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Aussi dans sensor_current / sensor_readings
    $db->prepare("
        INSERT INTO sensor_current (sensor_type, machine_id, team_id, valeur, unite)
        VALUES ('PROXIMITE', ?, ?, ?, 'ADC')
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), team_id = VALUES(team_id), last_update = NOW()
    ")->execute([$machineId, $teamId, $valeur]);

    $db->prepare("
        INSERT INTO sensor_readings (sensor_type, machine_id, team_id, valeur, unite)
        VALUES ('PROXIMITE', ?, ?, ?, 'ADC')
    ")->execute([$machineId, $teamId, $valeur]);

    $icone = $occupe ? '🔴 OCCUPÉE' : '🟢 LIBRE  ';
    printf("[%s] %s  (capteur : %d)\n", date('H:i:s'), $icone, $valeur);
}

// ── Traitement d'une trame reçue ──────────────────────────────────────────────
function traiterTrame(string $trame, int $machineId, string $teamId): void
{
    // TEMP_HUM:23.5:65.2
    if (preg_match('/^TEMP_HUM:([\d.]+):([\d.]+)$/', $trame, $m)) {
        enregistrerCapteur('TEMPERATURE', (float)$m[1], '°C',  $machineId, $teamId);
        enregistrerCapteur('HUMIDITE',    (float)$m[2], '%',   $machineId, $teamId);
        return;
    }

    // Types scalaires : TEMPERATURE | HUMIDITE | CO2 | LUX | SON
    if (preg_match('/^(TEMPERATURE|HUMIDITE|CO2|LUX|SON):([\d.]+)$/', $trame, $m)) {
        enregistrerCapteur($m[1], (float)$m[2], UNITES[$m[1]], $machineId, $teamId);
        return;
    }

    // Proximité (met aussi à jour machine_status pour rétro-compatibilité)
    if (preg_match('/^PROXIMITE:(\d+)$/', $trame, $m)) {
        traiterProximite((int)$m[1], $machineId, $teamId);
        return;
    }
}

// ── Point d'entrée ────────────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════════╗\n";
printf("║  Daemon multi-capteurs — %s @ %d baud                  ║\n", $COM_PORT_ARG, BAUD_RATE);
printf("║  Équipe : %-6s  Machine ID : %-2d  Seuil proximité : %-4d  ║\n", $TEAM_ID_ARG, $MID_ARG, SEUIL);
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

while (true) {
    try {
        $port  = ouvrirPort($COM_PORT_ARG);
        $ligne = '';
        echo "Port $COM_PORT_ARG ouvert. En attente de données...\n";

        while (true) {
            $octet = @dio_read($port, 1);
            if ($octet === false || $octet === '') { usleep(10_000); continue; }

            if ($octet === "\n") {
                $trame = trim($ligne);
                if ($trame !== '') {
                    try {
                        traiterTrame($trame, $MID_ARG, $TEAM_ID_ARG);
                    } catch (Throwable $e) {
                        echo '[TRAME ERREUR] ' . $e->getMessage() . "\n";
                    }
                }
                $ligne = '';
            } else {
                $ligne .= $octet;
                // Trame trop longue = bruit/corruption → réinitialiser
                if (strlen($ligne) > 64) $ligne = '';
            }
        }

        dio_close($port);

    } catch (Throwable $e) {
        echo '[ERREUR] ' . $e->getMessage() . "\nReconnexion dans 5 s...\n\n";
        if (isset($port) && $port !== false) @dio_close($port);
        sleep(5);
    }
}
