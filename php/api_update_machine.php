<?php
/**
 * Endpoint interne — appelé par serial_reader.ps1
 * GET /php/api_update_machine.php?etat=OCCUPEE&valeur=24&team=G9E&machine=1
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$etat    = ($_GET['etat'] ?? '') === 'OCCUPEE' ? 'OCCUPEE' : 'LIBRE';
$valeur  = (int)($_GET['valeur']  ?? 0);
$teamId  = preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['team']    ?? 'G9E'));
$mid     = (int)($_GET['machine'] ?? MACHINE_ID);

try {
    $db = getDB();

    $stmt = $db->prepare('SELECT statut, depuis FROM machine_status WHERE machine_id = ?');
    $stmt->execute([$mid]);
    $courant = $stmt->fetch();

    // Toujours mettre à jour valeur_brute et last_update
    $db->prepare("UPDATE machine_status SET valeur_brute = ?, last_update = NOW() WHERE machine_id = ?")
       ->execute([$valeur, $mid]);

    if ($courant && $courant['statut'] !== $etat) {
        $db->beginTransaction();
        try {
            // Fin d'occupation → enregistrer la session
            if ($courant['statut'] === 'OCCUPEE' && $etat === 'LIBRE') {
                $db->prepare("
                    INSERT INTO sessions_occupation (machine_id, debut, fin, duree_sec)
                    VALUES (?, ?, NOW(), TIMESTAMPDIFF(SECOND, ?, NOW()))
                ")->execute([$mid, $courant['depuis'], $courant['depuis']]);
            }

            $db->prepare("
                UPDATE machine_status SET statut = ?, valeur_brute = ?, depuis = NOW(), last_update = NOW()
                WHERE machine_id = ?
            ")->execute([$etat, $valeur, $mid]);

            $db->prepare("INSERT INTO machine_log (machine_id, statut, valeur_brute) VALUES (?, ?, ?)")
               ->execute([$mid, $etat, $valeur]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack(); throw $e;
        }
    }

    // Mettre à jour sensor_current
    $db->prepare("
        INSERT INTO sensor_current (sensor_type, machine_id, team_id, valeur, unite)
        VALUES ('PROXIMITE', ?, ?, ?, 'ADC')
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), team_id = VALUES(team_id), last_update = NOW()
    ")->execute([$mid, $teamId, $valeur]);

    echo json_encode(['ok' => true, 'etat' => $etat, 'valeur' => $valeur]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erreur' => $e->getMessage()]);
}
