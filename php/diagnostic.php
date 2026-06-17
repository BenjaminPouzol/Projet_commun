<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

exigerConnexion();

// ── Contrôles (démarrer / arrêter) ───────────────────────────────────────────
$flashMsg = '';
$flashType = '';

$batFile       = realpath(__DIR__ . '/../demarrer_capteur.bat') ?: '';
$heartbeatFile = __DIR__ . '/../capteur_heartbeat.txt';
$pidFile       = __DIR__ . '/../capteur_pid.txt';
$disabledFile  = __DIR__ . '/../capteur_disabled.txt';

function capteurActif(string $hbFile): bool
{
    return file_exists($hbFile) && (time() - filemtime($hbFile)) < 15;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'demarrer') {
        if (capteurActif($heartbeatFile)) {
            $flashMsg  = 'Le script est déjà actif.';
            $flashType = 'info';
        } elseif (!$batFile) {
            $flashMsg  = 'Fichier demarrer_capteur.bat introuvable.';
            $flashType = 'erreur';
        } else {
            @unlink($disabledFile);
            shell_exec('start "" /B "' . $batFile . '"');
            $flashMsg  = 'Démarrage en cours… patientez 5 secondes puis rechargez la page.';
            $flashType = 'succes';
        }
    }

    if ($action === 'arreter') {
        // Créer le drapeau pour bloquer le redémarrage automatique du .bat
        file_put_contents($disabledFile, date('Y-m-d H:i:s'));

        $killed = false;
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile) ?: '0');
            if ($pid > 0) {
                shell_exec('taskkill /F /PID ' . $pid . ' 2>NUL');
                $killed = true;
            }
        }
        @unlink($heartbeatFile);
        @unlink($pidFile);
        $flashMsg  = $killed ? 'Script arrêté (PID tué).' : 'Aucun PID trouvé — script peut-être déjà arrêté.';
        $flashType = 'info';
    }
}

// ── État base de données ──────────────────────────────────────────────────────
$dbOk  = false;
$dbErr = '';
$machine = null;
$recentLogs = [];

try {
    $db   = getDB();
    $dbOk = true;

    $stmt = $db->prepare("SELECT statut, valeur_brute, last_update, depuis FROM machine_status WHERE machine_id = ?");
    $stmt->execute([MACHINE_ID]);
    $machine = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT statut, valeur_brute, timestamp
        FROM machine_log WHERE machine_id = ?
        ORDER BY timestamp DESC LIMIT 10
    ");
    $stmt->execute([MACHINE_ID]);
    $recentLogs = $stmt->fetchAll();

} catch (Throwable $e) {
    $dbErr = $e->getMessage();
}

// ── État capteur ──────────────────────────────────────────────────────────────
$actif        = capteurActif($heartbeatFile);
$hbAge        = file_exists($heartbeatFile) ? (time() - filemtime($heartbeatFile)) : null;
$hbHeure      = file_exists($heartbeatFile) ? trim(file_get_contents($heartbeatFile)) : null;
$pidCourant   = file_exists($pidFile) ? (int) trim(file_get_contents($pidFile) ?: '0') : null;

// ── Test COM port ─────────────────────────────────────────────────────────────
$portsList = shell_exec('powershell -Command "[System.IO.Ports.SerialPort]::GetPortNames() -join \',\'" 2>NUL') ?: '';
$portsList = trim($portsList);
$comDispo  = $portsList !== '' ? explode(',', $portsList) : [];
$com3Ok    = in_array('COM3', array_map('trim', $comDispo));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Diagnostic — Salle de sport</title>
  <link rel="stylesheet" href="../public/assets/css/style.css">
  <style>
    .diag-table { width: 100%; border-collapse: collapse; }
    .diag-table td { padding: .6rem .75rem; border-bottom: 1px solid rgba(34,48,60,.08); font-size: .9rem; }
    .diag-table td:first-child { color: #8a9aab; width: 220px; }
    .badge-ok    { background:#166534; color:#bbf7d0; padding:.15rem .6rem; border-radius:99px; font-size:.8rem; font-weight:700; }
    .badge-err   { background:#7f1d1d; color:#fca5a5; padding:.15rem .6rem; border-radius:99px; font-size:.8rem; font-weight:700; }
    .badge-warn  { background:#78350f; color:#fde68a; padding:.15rem .6rem; border-radius:99px; font-size:.8rem; font-weight:700; }
    .badge-info  { background:#1e3a5f; color:#bae6fd; padding:.15rem .6rem; border-radius:99px; font-size:.8rem; font-weight:700; }
    .btn-group   { display:flex; gap:.6rem; flex-wrap:wrap; }
    .log-row-occ { color: #f87171; }
    .log-row-lib { color: #4ade80; }
  </style>
  <meta http-equiv="refresh" content="10">
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main>

  <?php if ($flashMsg): ?>
    <div class="alerte-flash <?= $flashType ?>" role="status" style="margin-bottom:1.25rem">
      <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- ── État du système ──────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="section-titre"><h2>État du système</h2></div>
    <table class="diag-table">
      <tr>
        <td>Base de données</td>
        <td>
          <?php if ($dbOk): ?>
            <span class="badge-ok">✓ Connectée</span>
            <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">
              <?= htmlspecialchars(DB_HOST . ' / ' . DB_NAME, ENT_QUOTES, 'UTF-8') ?>
            </span>
          <?php else: ?>
            <span class="badge-err">✗ Erreur</span>
            <span style="font-size:.8rem;color:#f87171;margin-left:.5rem">
              <?= htmlspecialchars($dbErr, ENT_QUOTES, 'UTF-8') ?>
            </span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Port série (COM3)</td>
        <td>
          <?php if ($com3Ok): ?>
            <span class="badge-ok">✓ Présent</span>
          <?php else: ?>
            <span class="badge-err">✗ Non détecté</span>
            <?php if ($portsList): ?>
              <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">
                Ports disponibles : <?= htmlspecialchars($portsList, ENT_QUOTES, 'UTF-8') ?>
              </span>
            <?php else: ?>
              <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">Aucun port COM trouvé</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Script capteur</td>
        <td>
          <?php if ($actif): ?>
            <span class="badge-ok">✓ Actif</span>
            <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">
              Heartbeat il y a <?= $hbAge ?>s
              <?= $pidCourant ? '· PID ' . $pidCourant : '' ?>
            </span>
          <?php elseif ($hbAge !== null): ?>
            <span class="badge-err">✗ Inactif</span>
            <span style="font-size:.8rem;color:#f87171;margin-left:.5rem">
              Dernier heartbeat il y a <?= $hbAge ?>s
            </span>
          <?php else: ?>
            <span class="badge-err">✗ Jamais démarré</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Machine #<?= MACHINE_ID ?></td>
        <td>
          <?php if ($machine): ?>
            <?php $occ = $machine['statut'] === 'OCCUPEE'; ?>
            <span class="badge-<?= $occ ? 'err' : 'ok' ?>">
              <?= $occ ? '● OCCUPÉE' : '● LIBRE' ?>
            </span>
            <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">
              Valeur : <?= (int)$machine['valeur_brute'] ?> / 4095
              · Seuil : <?= SEUIL ?>
              · Màj : <?= $machine['last_update'] ? date('H:i:s', strtotime($machine['last_update'])) : '—' ?>
            </span>
          <?php else: ?>
            <span class="badge-warn">Aucune donnée</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>Apache / PHP</td>
        <td>
          <span class="badge-ok">✓ En ligne</span>
          <span style="font-size:.8rem;color:#8a9aab;margin-left:.5rem">PHP <?= PHP_VERSION ?></span>
        </td>
      </tr>
    </table>
  </div>

  <!-- ── Contrôles capteur ────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="section-titre"><h2>Contrôles</h2></div>
    <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem">
      Le bouton <strong>Démarrer</strong> lance <code>demarrer_capteur.bat</code> depuis le serveur.
      Vérifiez d'abord que <strong>COM3 est détecté</strong> ci-dessus.
    </p>
    <form method="post" class="btn-group">
      <?= champCSRF() ?>
      <?php if (!$actif): ?>
        <button type="submit" name="action" value="demarrer"
                class="btn btn-succes" <?= !$com3Ok ? 'title="COM3 non détecté"' : '' ?>>
          Démarrer le capteur
        </button>
      <?php else: ?>
        <button type="submit" name="action" value="arreter" class="btn btn-danger">
          Arrêter le capteur
        </button>
      <?php endif; ?>
    </form>

    <?php if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))): ?>
      <p style="color:#f87171;font-size:.82rem;margin-top:.75rem">
        ⚠️ <code>shell_exec</code> est désactivé dans php.ini — impossible de lancer le script depuis le site.
        Lancez manuellement <code>demarrer_capteur.bat</code>.
      </p>
    <?php endif; ?>
  </div>

  <!-- ── Journal récent ───────────────────────────────────────────────────── -->
  <?php if (!empty($recentLogs)): ?>
  <div class="card">
    <div class="section-titre"><h2>Dernières entrées machine_log</h2></div>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Horodatage</th><th>Statut</th><th>Valeur</th></tr></thead>
        <tbody>
          <?php foreach ($recentLogs as $row): ?>
          <tr class="log-row-<?= $row['statut'] === 'OCCUPEE' ? 'occ' : 'lib' ?>">
            <td><?= htmlspecialchars($row['timestamp'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['statut'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$row['valeur_brute'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <p style="font-size:.75rem;color:#8a9aab;margin-top:1rem;text-align:center">
    Page actualisée automatiquement toutes les 10 s
  </p>

</main>
<footer>Projet IoT ISEP — Groupe G9E &nbsp;·&nbsp; Diagnostic système</footer>
</body>
</html>
