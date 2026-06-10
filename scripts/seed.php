<?php
/**
 * Seed — peuple la base avec les capteurs/actionneurs du projet
 * et des mesures de démonstration réalistes pour une salle de sport.
 *
 * Lancement (CLI) :
 *   php scripts/seed.php
 *
 * Ou via le navigateur (XAMPP) :
 *   http://localhost/Projet%20commun/scripts/seed.php
 */

// Sécurité minimale : bloquer si pas CLI et pas en localhost
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Accès refusé : ce script ne doit tourner qu\'en local ou en CLI.');
}

require_once __DIR__ . '/../config/db.php';

$db = getDB();

echo "=== Seed IoT Salle de sport — Groupe G9 ===\n\n";

// ---------------------------------------------------------
// 1. Utilisateurs de test
// ---------------------------------------------------------
$users = [
    ['nom' => 'Admin ISEP',  'email' => 'admin@isep.fr',    'role' => 'admin',       'mdp' => 'admin1234'],
    ['nom' => 'Étudiant G9', 'email' => 'etudiant@isep.fr', 'role' => 'utilisateur', 'mdp' => 'pass1234'],
];

foreach ($users as $u) {
    $stmt = $db->prepare('SELECT id FROM utilisateurs WHERE email = :e');
    $stmt->execute([':e' => $u['email']]);
    if (!$stmt->fetch()) {
        $db->prepare('INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (:n,:e,:h,:r)')
           ->execute([
               ':n' => $u['nom'],
               ':e' => $u['email'],
               ':h' => password_hash($u['mdp'], PASSWORD_DEFAULT),
               ':r' => $u['role'],
           ]);
        echo "  [OK] Utilisateur créé : {$u['email']}\n";
    } else {
        echo "  [--] Utilisateur déjà existant : {$u['email']}\n";
    }
}

// ---------------------------------------------------------
// 2. Capteurs de l'équipe G9E (proximité)
// ---------------------------------------------------------
$capteurs = [
    ['nom' => 'Compteur entrée salle',  'type' => 'proximite', 'unite' => 'pers', 'groupe' => 'G9', 'equipe' => 'G9E', 'emplacement' => 'Entrée principale'],
    ['nom' => 'Occupation zone cardio', 'type' => 'proximite', 'unite' => 'pers', 'groupe' => 'G9', 'equipe' => 'G9E', 'emplacement' => 'Zone cardio'],
];

$idsCapteurs = [];
foreach ($capteurs as $c) {
    $stmt = $db->prepare('SELECT id FROM capteurs WHERE nom = :n AND equipe = :e');
    $stmt->execute([':n' => $c['nom'], ':e' => $c['equipe']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $idsCapteurs[$c['nom']] = (int)$existing['id'];
        echo "  [--] Capteur déjà existant : {$c['nom']}\n";
    } else {
        $db->prepare(
            'INSERT INTO capteurs (nom, type, unite, groupe, equipe, emplacement) VALUES (:n,:t,:u,:g,:eq,:em)'
        )->execute([
            ':n' => $c['nom'], ':t' => $c['type'], ':u' => $c['unite'],
            ':g' => $c['groupe'], ':eq' => $c['equipe'], ':em' => $c['emplacement'],
        ]);
        $idsCapteurs[$c['nom']] = (int)$db->lastInsertId();
        echo "  [OK] Capteur créé : {$c['nom']} (id={$idsCapteurs[$c['nom']]})\n";
    }
}

// ---------------------------------------------------------
// 3. Actionneurs de l'équipe G9E
// ---------------------------------------------------------
$actionneurs = [
    ['nom' => 'LED poste 1', 'type' => 'led',  'groupe' => 'G9', 'equipe' => 'G9E'],
    ['nom' => 'LED poste 2', 'type' => 'led',  'groupe' => 'G9', 'equipe' => 'G9E'],
    ['nom' => 'LED poste 3', 'type' => 'led',  'groupe' => 'G9', 'equipe' => 'G9E'],
    ['nom' => 'Écran OLED',  'type' => 'oled', 'groupe' => 'G9', 'equipe' => 'G9E'],
];

foreach ($actionneurs as $a) {
    $stmt = $db->prepare('SELECT id FROM actionneurs WHERE nom = :n AND equipe = :e');
    $stmt->execute([':n' => $a['nom'], ':e' => $a['equipe']]);
    if (!$stmt->fetch()) {
        $db->prepare('INSERT INTO actionneurs (nom, type, groupe, equipe) VALUES (:n,:t,:g,:e)')
           ->execute([':n' => $a['nom'], ':t' => $a['type'], ':g' => $a['groupe'], ':e' => $a['equipe']]);
        echo "  [OK] Actionneur créé : {$a['nom']}\n";
    } else {
        echo "  [--] Actionneur déjà existant : {$a['nom']}\n";
    }
}

// ---------------------------------------------------------
// 4. Alerte sur le capteur de proximité (salle pleine si > 50)
// ---------------------------------------------------------
$alertesDef = [
    'Compteur entrée salle' => ['min' => null, 'max' => 50, 'email' => 'admin@isep.fr'],
];
foreach ($alertesDef as $nom => $a) {
    if (!isset($idsCapteurs[$nom])) continue;
    $cid = $idsCapteurs[$nom];
    $stmt = $db->prepare('SELECT id FROM alertes WHERE capteur_id = :c');
    $stmt->execute([':c' => $cid]);
    if (!$stmt->fetch()) {
        $db->prepare('INSERT INTO alertes (capteur_id, seuil_min, seuil_max, email) VALUES (:c,:min,:max,:e)')
           ->execute([':c' => $cid, ':min' => $a['min'], ':max' => $a['max'], ':e' => $a['email']]);
        echo "  [OK] Alerte configurée : $nom\n";
    }
}

// ---------------------------------------------------------
// 5. Mesures de démonstration — 7 jours, mesures toutes les 15 min
// ---------------------------------------------------------
echo "\n  Génération des mesures (7 jours × toutes les 15 min)...\n";

$db->exec('START TRANSACTION');

$insStmt = $db->prepare(
    'INSERT INTO mesures (capteur_id, valeur, horodatage) VALUES (:cid, :val, :ts)'
);

$now       = time();
$debut     = $now - 7 * 86400;
$intervalle = 15 * 60; // 15 minutes
$nbInsere  = 0;

for ($ts = $debut; $ts <= $now; $ts += $intervalle) {
    $heure  = (int)date('G', $ts);
    $minute = (int)date('i', $ts);
    $frac   = $heure + $minute / 60; // heure décimale

    // Coefficient d'affluence (salle de sport) : 0 la nuit, pics matin/midi/soir
    $affluence = affluentCoefficient($frac);

    foreach ($idsCapteurs as $nom => $cid) {
        // Proximité : nombre de personnes selon l'affluence de la salle
        if (str_contains($nom, 'Compteur')) {
            $valeur = max(0, (int)round($affluence * 40 + gaussRand(0, 2)));
        } elseif (str_contains($nom, 'Occupation zone')) {
            $valeur = max(0, (int)round($affluence * 12 + gaussRand(0, 1)));
        } else {
            continue;
        }

        if ($valeur !== null) {
            $insStmt->execute([
                ':cid' => $cid,
                ':val' => $valeur,
                ':ts'  => date('Y-m-d H:i:s', $ts),
            ]);
            $nbInsere++;
        }
    }
}

$db->exec('COMMIT');

echo "  [OK] {$nbInsere} mesures insérées.\n";
echo "\n=== Seed terminé. ===\n";
echo "    Compte admin : admin@isep.fr / admin1234\n";
echo "    Compte test  : etudiant@isep.fr / pass1234\n";

// ----- Fonctions locales -----

/**
 * Coefficient d'affluence (0.0 à 1.0) selon l'heure de la journée.
 * Simule les habitudes d'une salle de sport.
 */
function affluentCoefficient(float $heure): float
{
    // Fermé la nuit
    if ($heure < 6 || $heure >= 22) return 0.0;

    // Pic matin 7h–9h
    if ($heure >= 7 && $heure < 9) {
        return 0.3 + sin(($heure - 7) / 2 * M_PI) * 0.5;
    }
    // Creux matinée 9h–11h
    if ($heure >= 9 && $heure < 11) return 0.15 + gaussRand(0, 0.05);
    // Pic midi 11h–14h
    if ($heure >= 11 && $heure < 14) {
        return 0.5 + sin(($heure - 11) / 3 * M_PI) * 0.45;
    }
    // Creux après-midi 14h–17h
    if ($heure >= 14 && $heure < 17) return 0.2 + gaussRand(0, 0.05);
    // Pic soir 17h–21h
    if ($heure >= 17 && $heure < 21) {
        return 0.6 + sin(($heure - 17) / 4 * M_PI) * 0.38;
    }
    // Fin de soirée 21h–22h
    return max(0, 0.3 - ($heure - 21) * 0.3);
}

/** Distribution gaussienne simple (Box-Muller) */
function gaussRand(float $moy = 0, float $sigma = 1): float
{
    static $spare = null;
    if ($spare !== null) { $v = $spare; $spare = null; return $moy + $sigma * $v; }
    do { $u = mt_rand() / mt_getrandmax() * 2 - 1; $v2 = mt_rand() / mt_getrandmax() * 2 - 1; $s = $u * $u + $v2 * $v2; }
    while ($s >= 1 || $s == 0);
    $mul = sqrt(-2 * log($s) / $s);
    $spare = $v2 * $mul;
    return $moy + $sigma * $u * $mul;
}
