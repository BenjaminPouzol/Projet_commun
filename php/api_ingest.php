<?php
/**
 * API d'ingestion — Réception des données capteurs des équipes
 *
 * Permet à chaque groupe d'envoyer ses lectures depuis leur microcontrôleur
 * (Arduino, Tiva, ESP32, Raspberry Pi…) via HTTP GET ou POST.
 *
 * Endpoints :
 *   POST /php/api_ingest.php
 *        body JSON : {"team_id":"G9B","sensor_type":"TEMPERATURE","valeur":23.5}
 *
 *   GET  /php/api_ingest.php?team=G9B&type=TEMPERATURE&value=23.5
 *        (forme simplifiée, idéale pour tests ou petits microcontrôleurs)
 *
 *   GET  /php/api_ingest.php?action=status
 *        → statut de toutes les équipes (dernière donnée, en ligne ?)
 *
 *   GET  /php/api_ingest.php
 *        → documentation JSON de l'API
 *
 * Réponse JSON. CORS activé (toutes origines).
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Valeurs acceptées ─────────────────────────────────────────────────────────
const TYPES_CAPTEURS = ['TEMPERATURE', 'HUMIDITE', 'CO2', 'LUX', 'SON', 'PROXIMITE'];

const UNITES_PAR_DEFAUT = [
    'TEMPERATURE' => '°C',
    'HUMIDITE'    => '%',
    'CO2'         => 'ppm',
    'LUX'         => 'lux',
    'SON'         => 'dB',
    'PROXIMITE'   => 'ADC',
];

const SEUILS_CAPTEURS = [
    'TEMPERATURE' => ['min' => 15.0,  'max' => 30.0],
    'HUMIDITE'    => ['min' => 30.0,  'max' => 75.0],
    'CO2'         => ['min' => null,  'max' => 1000.0],
    'LUX'         => ['min' => 100.0, 'max' => null],
    'SON'         => ['min' => null,  'max' => 90.0],
];

const ALERTE_COOLDOWN = 300; // secondes entre deux alertes du même type

// ── Routage ───────────────────────────────────────────────────────────────────
try {
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? null;

    if ($action === 'status') {
        handleStatus($db);
    } elseif ($method === 'POST') {
        handlePost($db);
    } elseif ($method === 'GET' && isset($_GET['type'], $_GET['value'])) {
        handleGet($db);
    } else {
        handleDocs();
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ── Endpoint : statut des équipes ─────────────────────────────────────────────
function handleStatus(PDO $db): void
{
    $stmt = $db->query("
        SELECT team_id,
               MAX(last_update)            AS last_seen,
               GROUP_CONCAT(sensor_type ORDER BY sensor_type SEPARATOR ',') AS capteurs
        FROM sensor_current
        WHERE team_id != ''
        GROUP BY team_id
        ORDER BY team_id
    ");
    $rows = $stmt->fetchAll();

    $equipes = [];
    foreach ($rows as $row) {
        $age = time() - strtotime($row['last_seen']);
        if ($age < 120)       $statut = 'en_ligne';
        elseif ($age < 600)   $statut = 'inactif';
        else                  $statut = 'hors_ligne';

        $equipes[] = [
            'team_id'   => $row['team_id'],
            'statut'    => $statut,
            'capteurs'  => $row['capteurs'] ? explode(',', $row['capteurs']) : [],
            'last_seen' => $row['last_seen'],
            'age_sec'   => $age,
        ];
    }

    echo json_encode([
        'equipes'   => $equipes,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
}

// ── Endpoint : soumission via GET (microcontrôleurs simples) ──────────────────
function handleGet(PDO $db): void
{
    $data = [
        'team_id'     => strtoupper(trim($_GET['team']  ?? '')),
        'sensor_type' => strtoupper(trim($_GET['type']  ?? '')),
        'valeur'      => $_GET['value'] ?? null,
        'unite'       => $_GET['unite'] ?? null,
        'machine_id'  => (int)($_GET['machine'] ?? MACHINE_ID),
    ];
    processSensorData($db, $data);
}

// ── Endpoint : soumission via POST JSON ───────────────────────────────────────
function handlePost(PDO $db): void
{
    $raw  = file_get_contents('php://input');
    $body = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];

    $data = [
        'team_id'     => strtoupper(trim((string)($body['team_id']     ?? $_POST['team_id']     ?? ''))),
        'sensor_type' => strtoupper(trim((string)($body['sensor_type'] ?? $_POST['sensor_type'] ?? ''))),
        'valeur'      => $body['valeur']     ?? $_POST['valeur']     ?? null,
        'unite'       => $body['unite']      ?? $_POST['unite']      ?? null,
        'machine_id'  => (int)($body['machine_id'] ?? $_POST['machine_id'] ?? MACHINE_ID),
    ];
    processSensorData($db, $data);
}

// ── Traitement d'une lecture capteur ──────────────────────────────────────────
function processSensorData(PDO $db, array $data): void
{
    $teamId     = $data['team_id'];
    $sensorType = $data['sensor_type'];
    $valeur     = $data['valeur'];
    $machineId  = max(1, $data['machine_id']);

    // ── Validation ────────────────────────────────────────────────────────────
    $erreurs = [];

    if ($teamId === '' || !preg_match('/^[A-Z0-9]{2,10}$/i', $teamId)) {
        $erreurs[] = "team_id invalide ou manquant (ex : G9B)";
    }
    if (!in_array($sensorType, TYPES_CAPTEURS, true)) {
        $erreurs[] = "sensor_type invalide. Valeurs possibles : " . implode(', ', TYPES_CAPTEURS);
    }
    if ($valeur === null || !is_numeric($valeur)) {
        $erreurs[] = "valeur manquante ou non numérique";
    }

    if (!empty($erreurs)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erreurs' => $erreurs], JSON_UNESCAPED_UNICODE);
        return;
    }

    $valeur = (float)$valeur;
    $unite  = (string)($data['unite'] ?? (UNITES_PAR_DEFAUT[$sensorType] ?? ''));

    // ── Historique ────────────────────────────────────────────────────────────
    $db->prepare("
        INSERT INTO sensor_readings (sensor_type, machine_id, team_id, valeur, unite)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$sensorType, $machineId, $teamId, $valeur, $unite]);

    // ── Valeur courante ───────────────────────────────────────────────────────
    $db->prepare("
        INSERT INTO sensor_current (sensor_type, machine_id, team_id, valeur, unite)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            team_id     = VALUES(team_id),
            valeur      = VALUES(valeur),
            unite       = VALUES(unite),
            last_update = CURRENT_TIMESTAMP
    ")->execute([$sensorType, $machineId, $teamId, $valeur, $unite]);

    // ── Gestion spéciale PROXIMITE → machine_status + table partagée ─────────
    $statutMachine = null;
    if ($sensorType === 'PROXIMITE') {
        $statutMachine = updateMachineStatus($db, $machineId, $teamId, $valeur);
        $db->prepare("
            INSERT INTO `G9E_Proximité` (valeur, statut) VALUES (?, ?)
        ")->execute([(int)$valeur, $statutMachine]);
    }

    // ── Vérification des seuils → alertes ────────────────────────────────────
    $alerte = verifierSeuil($db, $sensorType, $machineId, $valeur, $unite);

    echo json_encode([
        'ok'             => true,
        'sensor_type'    => $sensorType,
        'team_id'        => $teamId,
        'valeur'         => $valeur,
        'unite'          => $unite,
        'statut_machine' => $statutMachine,
        'alerte'         => $alerte,
        'timestamp'      => date('c'),
    ], JSON_UNESCAPED_UNICODE);
}

// ── Mise à jour machine_status (capteur de proximité) ────────────────────────
function updateMachineStatus(PDO $db, int $machineId, string $teamId, float $valeur): string
{
    $newStatut = $valeur >= SEUIL ? 'OCCUPEE' : 'LIBRE';

    $curr = $db->prepare("SELECT statut, depuis FROM machine_status WHERE machine_id = ?");
    $curr->execute([$machineId]);
    $old = $curr->fetch();

    if (!$old) {
        $db->prepare("
            INSERT INTO machine_status (machine_id, statut, valeur_brute, depuis, team_id)
            VALUES (?, ?, ?, NOW(), ?)
        ")->execute([$machineId, $newStatut, (int)$valeur, $teamId]);
        $db->prepare("
            INSERT INTO machine_log (machine_id, statut, valeur_brute) VALUES (?, ?, ?)
        ")->execute([$machineId, $newStatut, (int)$valeur]);

    } elseif ($old['statut'] !== $newStatut) {
        // Fin d'occupation → enregistrer la session
        if ($old['statut'] === 'OCCUPEE' && $newStatut === 'LIBRE') {
            $duree = max(0, (int)(time() - strtotime($old['depuis'])));
            if ($duree > 0) {
                $db->prepare("
                    INSERT INTO sessions_occupation (machine_id, debut, fin, duree_sec)
                    VALUES (?, ?, NOW(), ?)
                ")->execute([$machineId, $old['depuis'], $duree]);
            }
        }
        $db->prepare("
            UPDATE machine_status
            SET statut = ?, valeur_brute = ?, depuis = NOW(), team_id = ?
            WHERE machine_id = ?
        ")->execute([$newStatut, (int)$valeur, $teamId, $machineId]);
        $db->prepare("
            INSERT INTO machine_log (machine_id, statut, valeur_brute) VALUES (?, ?, ?)
        ")->execute([$machineId, $newStatut, (int)$valeur]);

    } else {
        $db->prepare("
            UPDATE machine_status SET valeur_brute = ?, last_update = NOW() WHERE machine_id = ?
        ")->execute([(int)$valeur, $machineId]);
    }

    return $newStatut;
}

// ── Vérification des seuils et création d'alertes ────────────────────────────
function verifierSeuil(PDO $db, string $sensorType, int $machineId, float $valeur, string $unite): ?array
{
    if (!isset(SEUILS_CAPTEURS[$sensorType])) return null;

    $s         = SEUILS_CAPTEURS[$sensorType];
    $direction = null;
    $seuil     = null;
    $message   = null;

    if ($s['min'] !== null && $valeur < $s['min']) {
        $direction = 'MIN';
        $seuil     = $s['min'];
        $message   = "$sensorType trop bas : $valeur $unite (min $seuil)";
    } elseif ($s['max'] !== null && $valeur > $s['max']) {
        $direction = 'MAX';
        $seuil     = $s['max'];
        $message   = "$sensorType trop élevé : $valeur $unite (max $seuil)";
    }

    if ($direction === null) return null;

    // Anti-spam : une alerte max toutes les ALERTE_COOLDOWN secondes
    $recent = $db->prepare("
        SELECT id FROM sensor_alerts
        WHERE sensor_type = ? AND machine_id = ? AND direction = ?
          AND acquittee = 0
          AND timestamp >= NOW() - INTERVAL ? SECOND
        LIMIT 1
    ");
    $recent->execute([$sensorType, $machineId, $direction, ALERTE_COOLDOWN]);
    if ($recent->fetch()) return null;

    $db->prepare("
        INSERT INTO sensor_alerts (sensor_type, machine_id, valeur, seuil, direction, message)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$sensorType, $machineId, $valeur, $seuil, $direction, $message]);

    return ['direction' => $direction, 'seuil' => $seuil, 'message' => $message];
}

// ── Documentation JSON ────────────────────────────────────────────────────────
function handleDocs(): void
{
    $base = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/Projet_commun/php/api_ingest.php';
    echo json_encode([
        'api'         => 'API d\'ingestion capteurs — Salle de sport IoT ISEP',
        'description' => 'Endpoint pour que chaque équipe envoie ses données capteurs',
        'endpoints'   => [
            'POST /'             => 'Soumettre une lecture (JSON body)',
            'GET /?action=status' => 'Statut de toutes les équipes',
            'GET /?type=X&value=Y&team=G9X' => 'Soumettre via GET (pour microcontrôleurs simples)',
        ],
        'champs_post' => [
            'team_id (obligatoire)'     => 'Ex : G9A, G9B, G9C, G9D, G9E',
            'sensor_type (obligatoire)' => implode(' | ', TYPES_CAPTEURS),
            'valeur (obligatoire)'      => 'Valeur numérique du capteur',
            'unite (optionnel)'         => 'Unité (déduite automatiquement si absent)',
            'machine_id (optionnel)'    => 'ID machine, défaut : 1',
        ],
        'exemples' => [
            'curl POST' => "curl -X POST $base -H \"Content-Type: application/json\" -d '{\"team_id\":\"G9B\",\"sensor_type\":\"TEMPERATURE\",\"valeur\":23.5}'",
            'GET simple' => "$base?team=G9B&type=TEMPERATURE&value=23.5",
            'Status'     => "$base?action=status",
        ],
        'capteurs_par_equipe' => [
            'G9A' => 'SON (dB)',
            'G9B' => 'TEMPERATURE (°C), HUMIDITE (%)',
            'G9C' => 'LUX (lux)',
            'G9D' => 'CO2 (ppm)',
            'G9E' => 'PROXIMITE (ADC)',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
