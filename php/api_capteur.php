<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

exigerConnexion();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$heartbeatFile = __DIR__ . '/../capteur_heartbeat.txt';
$pidFile       = __DIR__ . '/../capteur_pid.txt';
$disabledFile  = __DIR__ . '/../capteur_disabled.txt';
$batFile       = realpath(__DIR__ . '/../demarrer_capteur.bat') ?: '';

function capteurActif(string $hbFile): bool
{
    return file_exists($hbFile) && (time() - filemtime($hbFile)) < 15;
}

function lancerProcessusDetache(string $batFile): void
{
    // WScript.Shell : Run(..., 0, false) retourne immédiatement sans bloquer PHP
    if (class_exists('COM')) {
        try {
            $wsh = new COM('WScript.Shell');
            $wsh->Run('"' . $batFile . '"', 0, false);
            return;
        } catch (\Throwable) {}
    }
    // Fallback : proc_open sans attendre la fin du processus
    $desc = [];
    $pipes = [];
    $proc = @proc_open('cmd /c start "" /B "' . $batFile . '" >NUL 2>&1', $desc, $pipes);
    if (is_resource($proc)) proc_close($proc);
}

$actif  = capteurActif($heartbeatFile);
$hbAge  = file_exists($heartbeatFile) ? (time() - filemtime($heartbeatFile)) : null;
$pid    = file_exists($pidFile) ? (int) trim(file_get_contents($pidFile) ?: '0') : null;
$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

// ── Lecture seule ─────────────────────────────────────────────────────────────
if ($action === 'status') {
    echo json_encode(['actif' => $actif, 'age' => $hbAge, 'pid' => $pid], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actions d'écriture (POST uniquement) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token CSRF invalide.']);
    exit;
}

if ($action === 'demarrer') {
    if ($actif) {
        echo json_encode(['ok' => false, 'actif' => true, 'message' => 'Le capteur est déjà actif.']);
        exit;
    }
    if (!$batFile) {
        echo json_encode(['ok' => false, 'actif' => false, 'message' => 'Fichier demarrer_capteur.bat introuvable.']);
        exit;
    }

    @unlink($disabledFile);
    lancerProcessusDetache($batFile);
    echo json_encode(['ok' => true, 'actif' => true, 'message' => 'Capteur en cours de démarrage…'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'arreter') {
    // Créer le drapeau "désactivé" pour empêcher le .bat de relancer le script
    file_put_contents($disabledFile, date('Y-m-d H:i:s'));

    $killed = false;
    if ($pid && $pid > 0) {
        shell_exec('taskkill /F /PID ' . $pid . ' 2>NUL');
        $killed = true;
    }
    @unlink($heartbeatFile);
    @unlink($pidFile);
    echo json_encode([
        'ok'      => true,
        'actif'   => false,
        'message' => $killed ? 'Capteur arrêté.' : 'Capteur arrêté (aucun PID actif trouvé).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Action inconnue.']);
