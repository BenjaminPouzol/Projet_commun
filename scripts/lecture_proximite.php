<?php
/**
 * Daemon CLI — Lecture du capteur de proximité via port série
 * Carte TIVA connectée en USB/COM, extension PHP Direct IO (dio_*).
 *
 * Lancement (toujours en CLI, JAMAIS en web) :
 *   php scripts/lecture_proximite.php
 *
 * Prérequis :
 *   - Extension php_dio activée dans php.ini (décommenter extension=php_dio)
 *   - XAMPP PHP CLI : C:\xampp\php\php.exe
 *   - Adapter COM_PORT ci-dessous au port de la TIVA (Gestionnaire de périphériques)
 */

if (PHP_SAPI !== 'cli') {
    die("Ce script doit être lancé en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/db.php';

// ── Configuration ──────────────────────────────────────────────────────────
const COM_PORT       = 'COM22';     // ← Adapter au port de la TIVA
const BAUD_RATE      = 9600;
const CAPTEUR_ID     = 4;           // ← ID du capteur de proximité dans la BDD (seed.php)
const DELAI_LECTURE  = 1;           // secondes entre deux lectures (éco-conception)
const TRAME_PATTERN  = '/(\d+(?:\.\d+)?)/'; // extrait le premier nombre de la trame
// ───────────────────────────────────────────────────────────────────────────

// Vérifier l'extension Direct IO
if (!function_exists('dio_open')) {
    fwrite(STDERR,
        "ERREUR : l'extension PHP Direct IO (php_dio) n'est pas chargée.\n" .
        "Décommentez 'extension=php_dio' dans votre php.ini et redémarrez.\n"
    );
    exit(1);
}

// Ouvrir le port série
$mode = sprintf(
    '%s: baud=%d parity=n data=8 stop=1 to=5000',
    COM_PORT, BAUD_RATE
);

echo "[" . date('H:i:s') . "] Ouverture du port " . COM_PORT . " à " . BAUD_RATE . " bauds...\n";

$port = @dio_open(COM_PORT, O_RDWR | O_NOCTTY);

if ($port === false) {
    fwrite(STDERR,
        "ERREUR : impossible d'ouvrir " . COM_PORT . ".\n" .
        "Vérifiez :\n" .
        "  1. La TIVA est branchée et le port COM est correct.\n" .
        "  2. Le port n'est pas déjà utilisé (Gestionnaire de périphériques).\n"
    );
    exit(1);
}

// Configurer le port série (Windows)
dio_fcntl($port, F_SETFL, 0);

echo "[" . date('H:i:s') . "] Port ouvert. En attente de trames...\n";
echo "  Appuyez sur Ctrl+C pour arrêter.\n\n";

// Préparer la requête d'insertion (réutilisée à chaque itération)
$insStmt = getDB()->prepare(
    'INSERT INTO mesures (capteur_id, valeur, horodatage) VALUES (:cid, :val, NOW())'
);

$bufferLigne = '';

// ── Boucle principale ──────────────────────────────────────────────────────
while (true) {
    $octets = @dio_read($port, 128);

    if ($octets === false || $octets === '') {
        // Aucune donnée disponible, on attend
        sleep(DELAI_LECTURE);
        continue;
    }

    // Accumuler les octets dans le buffer ligne par ligne
    $bufferLigne .= $octets;

    // Traiter les lignes complètes (terminées par \n)
    while (($pos = strpos($bufferLigne, "\n")) !== false) {
        $ligne       = trim(substr($bufferLigne, 0, $pos));
        $bufferLigne = substr($bufferLigne, $pos + 1);

        if ($ligne === '') continue;

        // Extraire la valeur numérique de la trame
        if (preg_match(TRAME_PATTERN, $ligne, $matches)) {
            $valeur = (float)$matches[1];

            try {
                $insStmt->execute([':cid' => CAPTEUR_ID, ':val' => $valeur]);
                echo "[" . date('H:i:s') . "] Trame reçue : \"$ligne\" → " . $valeur . " cm (inséré en BDD)\n";
            } catch (PDOException $e) {
                fwrite(STDERR, "ERREUR BDD : " . $e->getMessage() . "\n");
            }
        } else {
            echo "[" . date('H:i:s') . "] Trame non reconnue : \"$ligne\"\n";
        }
    }

    // Pause pour ne pas saturer le port ni la base (éco-conception)
    sleep(DELAI_LECTURE);
}

// Fermeture propre (atteint seulement si la boucle est interrompue)
dio_close($port);
echo "\nPort fermé. Arrêt.\n";
