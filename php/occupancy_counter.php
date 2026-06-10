<?php
/**
 * Daemon PHP – Lecture du capteur de proximité et mise à jour du statut machine
 *
 * Usage     : php php/occupancy_counter.php
 * Prérequis : extension php_dio activée dans php.ini (XAMPP)
 *
 * Protocole Tiva : "PROXIMITE:XXXX\n"
 *   >= SEUIL → OCCUPÉE  (quelqu'un devant la machine)
 *   <  SEUIL → LIBRE
 *
 * Anti-rebond : 5 lectures consécutives cohérentes avant de changer le statut.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

const COM_PORT  = 'COM22';
const BAUD_RATE = 9600;
const DEBOUNCE  = 5;     // Lectures consécutives requises avant changement

// ── Port série ────────────────────────────────────────────────────────────────

function ouvrirPort(): mixed
{
    $chemin = (PHP_OS_FAMILY === 'Windows') ? '\\\\.\\'  . COM_PORT : COM_PORT;
    $port   = @dio_open($chemin, O_RDONLY | O_NOCTTY);
    if ($port === false) {
        throw new RuntimeException(
            "Impossible d'ouvrir " . COM_PORT .
            " — vérifiez le câble USB et que php_dio est activé dans php.ini"
        );
    }
    dio_tcsetattr($port, ['baud' => BAUD_RATE, 'bits' => 8, 'stop' => 1, 'parity' => 0]);
    return $port;
}

// ── Mise à jour du statut (seulement si changement) ──────────────────────────

function mettreAJourStatut(string $nouveauStatut, int $valeur): void
{
    $db = getDB();

    // Lire statut actuel
    $stmt = $db->prepare('SELECT statut FROM machine_status WHERE machine_id = ?');
    $stmt->execute([MACHINE_ID]);
    $statutActuel = $stmt->fetchColumn();

    if ($statutActuel === $nouveauStatut) return;   // Pas de changement → rien à faire

    $db->beginTransaction();
    try {
        // Mettre à jour le statut courant + remettre 'depuis' à maintenant
        $db->prepare("
            UPDATE machine_status
            SET statut = ?, valeur_brute = ?, depuis = NOW(), last_update = NOW()
            WHERE machine_id = ?
        ")->execute([$nouveauStatut, $valeur, MACHINE_ID]);

        // Logger le changement
        $db->prepare("
            INSERT INTO machine_log (machine_id, statut, valeur_brute)
            VALUES (?, ?, ?)
        ")->execute([MACHINE_ID, $nouveauStatut, $valeur]);

        $db->commit();

        $icone = $nouveauStatut === 'OCCUPEE' ? '🔴 OCCUPÉE' : '🟢 LIBRE  ';
        printf("[%s] %s  (valeur capteur : %d)\n", date('H:i:s'), $icone, $valeur);

    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Boucle principale ─────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  Surveillance machine – " . COM_PORT . " @ " . BAUD_RATE . " baud       ║\n";
echo "║  Seuil : " . SEUIL . "  |  Anti-rebond : " . DEBOUNCE . " lectures          ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

while (true) {
    try {
        $port    = ouvrirPort();
        $ligne   = '';
        $buffer  = [];   // Fenêtre glissante pour l'anti-rebond
        echo "Port " . COM_PORT . " ouvert. Surveillance en cours...\n";

        while (true) {
            $octet = @dio_read($port, 1);
            if ($octet === false || $octet === '') { usleep(10_000); continue; }

            if ($octet === "\n") {
                $trame = trim($ligne);

                if (preg_match('/^PROXIMITE:(\d+)$/', $trame, $m)) {
                    $valeur   = (int) $m[1];
                    $occupe   = ($valeur >= SEUIL);

                    // Anti-rebond : fenêtre glissante de DEBOUNCE lectures
                    $buffer[] = $occupe;
                    if (count($buffer) > DEBOUNCE) array_shift($buffer);

                    // Agir seulement si toutes les lectures sont identiques
                    if (count($buffer) === DEBOUNCE && count(array_unique($buffer)) === 1) {
                        $statut = $occupe ? 'OCCUPEE' : 'LIBRE';
                        mettreAJourStatut($statut, $valeur);
                    }
                }

                $ligne = '';
            } else {
                $ligne .= $octet;
            }
        }

        dio_close($port);

    } catch (Throwable $e) {
        echo '[ERREUR] ' . $e->getMessage() . "\nReconnexion dans 5 s...\n\n";
        sleep(5);
    }
}
