<?php
/**
 * API REST – Statut machine (lecture capteur de proximité)
 *
 * GET /php/api_occupancy.php/status
 *     → { machine_id, statut, valeur_brute, depuis, last_update, team_id }
 *
 * GET /php/api_occupancy.php/history?hours=1
 *     → [ { id, statut, valeur_brute, timestamp }, … ]
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store');

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    $db = getDB();

    if (str_ends_with($path, '/status')) {
        $stmt = $db->prepare('SELECT * FROM machine_status WHERE machine_id = ?');
        $stmt->execute([MACHINE_ID]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Aucune donnée']); exit; }
        echo json_encode($row, JSON_UNESCAPED_UNICODE);

    } elseif (str_ends_with($path, '/history')) {
        $hours = max(1, min(24, (int)($_GET['hours'] ?? 1)));
        $stmt  = $db->prepare("
            SELECT id, statut, valeur_brute, timestamp
            FROM machine_log
            WHERE machine_id = ? AND timestamp >= NOW() - INTERVAL ? HOUR
            ORDER BY timestamp DESC LIMIT 500
        ");
        $stmt->execute([MACHINE_ID, $hours]);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(404);
        echo json_encode([
            'endpoints' => [
                'GET /php/api_occupancy.php/status',
                'GET /php/api_occupancy.php/history?hours=1',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
