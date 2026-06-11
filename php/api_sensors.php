<?php
/**
 * API REST — Données capteurs IoT salle de sport
 *
 * Endpoints :
 *   GET  /php/api_sensors.php/current           → valeurs courantes de tous les capteurs
 *   GET  /php/api_sensors.php/history?type=CO2&hours=1  → historique d'un capteur
 *   GET  /php/api_sensors.php/alerts            → alertes non acquittées
 *   POST /php/api_sensors.php/alerts/ack        → acquitter une alerte (body JSON: {"id":42})
 *
 * Réponse JSON, CORS activé, cache désactivé.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Routage ───────────────────────────────────────────────────────────────────
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$segments = explode('/', $path);
$endpoint = end($segments);   // dernier segment : current | history | alerts

try {
    $db = getDB();

    match (true) {
        $endpoint === 'current'             => handleCurrent($db),
        $endpoint === 'history'             => handleHistory($db),
        $endpoint === 'alerts' && $_SERVER['REQUEST_METHOD'] === 'GET'  => handleAlerts($db),
        $endpoint === 'ack'    && $_SERVER['REQUEST_METHOD'] === 'POST' => handleAck($db),
        default                             => notFound("Endpoint inconnu : $endpoint"),
    };

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ── /current ─────────────────────────────────────────────────────────────────
function handleCurrent(PDO $db): void
{
    // Statut machine (proximité)
    $machine = $db->prepare("
        SELECT statut, valeur_brute,
               TIMESTAMPDIFF(SECOND, depuis, NOW()) AS secondes_depuis,
               last_update
        FROM machine_status WHERE machine_id = ?
    ");
    $machine->execute([MACHINE_ID]);
    $machineRow = $machine->fetch() ?: null;

    // Valeurs courantes de tous les capteurs
    $stmt = $db->prepare("
        SELECT sensor_type, valeur, unite, team_id, last_update
        FROM sensor_current
        WHERE machine_id = ?
        ORDER BY sensor_type
    ");
    $stmt->execute([MACHINE_ID]);
    $capteurs = [];
    foreach ($stmt->fetchAll() as $row) {
        $capteurs[$row['sensor_type']] = [
            'valeur'      => (float) $row['valeur'],
            'unite'       => $row['unite'],
            'team_id'     => $row['team_id'],
            'last_update' => $row['last_update'],
            'etat'        => etatCapteur($row['sensor_type'], (float)$row['valeur']),
        ];
    }

    // Nombre d'alertes actives
    $nbAlertes = (int) $db->query(
        "SELECT COUNT(*) FROM sensor_alerts WHERE acquittee = 0"
    )->fetchColumn();

    echo json_encode([
        'machine'    => $machineRow,
        'capteurs'   => $capteurs,
        'nb_alertes' => $nbAlertes,
        'timestamp'  => date('c'),
    ], JSON_UNESCAPED_UNICODE);
}

// ── /history ──────────────────────────────────────────────────────────────────
function handleHistory(PDO $db): void
{
    $type  = strtoupper(trim($_GET['type'] ?? ''));
    $hours = max(1, min(168, (int)($_GET['hours'] ?? 1)));
    $limit = max(1, min(2000, (int)($_GET['limit'] ?? 500)));

    $typesValides = ['TEMPERATURE', 'HUMIDITE', 'CO2', 'LUX', 'SON', 'PROXIMITE'];
    if ($type !== '' && !in_array($type, $typesValides, true)) {
        http_response_code(400);
        echo json_encode(['erreur' => "Type invalide. Valeurs possibles : " . implode(', ', $typesValides)]);
        return;
    }

    $sql = "
        SELECT sensor_type, valeur, unite, team_id, timestamp
        FROM sensor_readings
        WHERE machine_id = ?
          AND timestamp >= NOW() - INTERVAL ? HOUR
    ";
    $params = [MACHINE_ID, $hours];

    if ($type !== '') {
        $sql    .= ' AND sensor_type = ?';
        $params[] = $type;
    }

    $sql .= " ORDER BY timestamp DESC LIMIT $limit";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'type'    => $type ?: 'ALL',
        'hours'   => $hours,
        'count'   => count($rows),
        'data'    => $rows,
    ], JSON_UNESCAPED_UNICODE);
}

// ── /alerts ───────────────────────────────────────────────────────────────────
function handleAlerts(PDO $db): void
{
    $inclureAcq = filter_var($_GET['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $limit      = max(1, min(200, (int)($_GET['limit'] ?? 50)));

    $where = $inclureAcq ? '' : 'WHERE acquittee = 0';

    $stmt = $db->query("
        SELECT id, sensor_type, machine_id, valeur, seuil, direction, message, acquittee, timestamp
        FROM sensor_alerts
        $where
        ORDER BY timestamp DESC
        LIMIT $limit
    ");

    $alertes = $stmt->fetchAll();
    foreach ($alertes as &$a) {
        $a['id']        = (int) $a['id'];
        $a['machine_id'] = (int) $a['machine_id'];
        $a['valeur']    = (float) $a['valeur'];
        $a['seuil']     = (float) $a['seuil'];
        $a['acquittee'] = (bool) $a['acquittee'];
    }
    unset($a);

    echo json_encode([
        'count'   => count($alertes),
        'alertes' => $alertes,
    ], JSON_UNESCAPED_UNICODE);
}

// ── /alerts/ack ───────────────────────────────────────────────────────────────
function handleAck(PDO $db): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = isset($body['id']) ? (int)$body['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'id requis']);
        return;
    }

    $nb = $db->prepare("UPDATE sensor_alerts SET acquittee = 1 WHERE id = ?");
    $nb->execute([$id]);

    echo json_encode(['ok' => true, 'id' => $id, 'modifiees' => $nb->rowCount()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function etatCapteur(string $type, float $valeur): string
{
    $seuils = [
        'TEMPERATURE' => ['min' => 15.0,   'max' => 30.0],
        'HUMIDITE'    => ['min' => 30.0,   'max' => 75.0],
        'CO2'         => ['min' => null,   'max' => 1000.0],
        'LUX'         => ['min' => 100.0,  'max' => null],
        'SON'         => ['min' => null,   'max' => 90.0],
    ];

    if (!isset($seuils[$type])) return 'info';

    $s = $seuils[$type];
    if ($s['min'] !== null && $valeur < $s['min']) return 'alerte';
    if ($s['max'] !== null && $valeur > $s['max']) return 'alerte';

    // Zone de vigilance : 10 % au-delà du seuil min / en dessous du seuil max
    if ($s['min'] !== null && $valeur < $s['min'] * 1.15) return 'avertissement';
    if ($s['max'] !== null && $valeur > $s['max'] * 0.88) return 'avertissement';

    return 'ok';
}

function notFound(string $msg): void
{
    http_response_code(404);
    echo json_encode(['erreur' => $msg], JSON_UNESCAPED_UNICODE);
}
