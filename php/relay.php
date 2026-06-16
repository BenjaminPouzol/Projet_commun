<?php
/**
 * Relai BDD — À déployer sur le serveur de l'école (dash.novhs.fr)
 *
 * Ce script tourne côté serveur, où 172.20.0.5 est accessible.
 * Il reçoit des requêtes HTTP de notre app locale et les exécute sur MySQL.
 *
 * Déploiement : uploader ce fichier sur dash.novhs.fr et noter son URL,
 * puis la renseigner dans RELAY_URL dans config.php local.
 *
 * Sécurité : chaque requête doit porter l'en-tête X-Relay-Key avec le bon secret.
 */

define('RELAY_SECRET', 'G9E_relay_s3cr3t_2026');
define('DB_HOST_LOCAL', '172.20.0.5');
define('DB_NAME_LOCAL', 'salledesportintelligente_G9');
define('DB_USER_LOCAL', 'salledesportintelligente_G9');
define('DB_PASS_LOCAL', 'iYU_M.Awgn!mhKW5');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Relay-Key');
header('Cache-Control: no-cache, no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Authentification ──────────────────────────────────────────────────────────
$key = $_SERVER['HTTP_X_RELAY_KEY'] ?? '';
if (!hash_equals(RELAY_SECRET, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erreur' => 'Clé invalide']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST_LOCAL . ';dbname=' . DB_NAME_LOCAL . ';charset=utf8mb4',
        DB_USER_LOCAL,
        DB_PASS_LOCAL,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? 'query';
    $sql    = $body['sql']    ?? '';
    $params = $body['params'] ?? [];

    // ── Action : init_schema ──────────────────────────────────────────────────
    // Crée toutes les tables d'un coup (appelé une seule fois au démarrage)
    if ($action === 'init_schema') {
        $sqls = $body['sqls'] ?? [];
        foreach ($sqls as $s) {
            $pdo->exec($s);
        }
        echo json_encode(['ok' => true, 'action' => 'init_schema']);
        exit;
    }

    if (empty($sql)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erreur' => 'SQL manquant']);
        exit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = match($action) {
        'all'    => $stmt->fetchAll(),
        'one'    => $stmt->fetch() ?: null,
        'column' => $stmt->fetchColumn(),
        default  => null,   // exec / insert / update
    };

    echo json_encode([
        'ok'       => true,
        'rows'     => $rows,
        'rowCount' => $stmt->rowCount(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
