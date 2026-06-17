<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

exigerAdmin();

$db = getDB();

// ── Pagination sessions ───────────────────────────────────────────────────────
$parPage  = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $parPage;

// ── Dernières lectures capteurs (toutes les 30 dernières) ────────────────────
$derniersLog = $db->prepare("
    SELECT sensor_type, valeur, unite, timestamp
    FROM sensor_readings
    WHERE machine_id = ?
    ORDER BY timestamp DESC
    LIMIT 30
");
$derniersLog->execute([MACHINE_ID]);
$derniersLog = $derniersLog->fetchAll();

// ── Filtre date ───────────────────────────────────────────────────────────────
$dateMin = $_GET['date_min'] ?? '';
$dateMax = $_GET['date_max'] ?? '';
$filtreSql = 'WHERE machine_id = 1';
$filtreParams = [];
if ($dateMin) { $filtreSql .= ' AND DATE(debut) >= ?'; $filtreParams[] = $dateMin; }
if ($dateMax) { $filtreSql .= ' AND DATE(debut) <= ?'; $filtreParams[] = $dateMax; }

// ── Stats globales ────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(*)                         AS nb_sessions,
        COALESCE(SUM(duree_sec), 0)      AS total_sec,
        COALESCE(AVG(duree_sec), 0)      AS moy_sec,
        COUNT(DISTINCT DATE(debut))      AS nb_jours,
        MIN(DATE(debut))                 AS premier_jour,
        MAX(DATE(debut))                 AS dernier_jour
    FROM sessions_occupation $filtreSql
");
$stmt->execute($filtreParams);
$globaux = $stmt->fetch();

// ── Stats par jour ────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        DATE(debut)          AS jour,
        COUNT(*)             AS nb_sessions,
        SUM(duree_sec)       AS duree_totale_sec,
        ROUND(AVG(duree_sec)) AS duree_moy_sec,
        MIN(TIME(debut))     AS premiere_session,
        MAX(TIME(fin))       AS derniere_fin
    FROM sessions_occupation $filtreSql
    GROUP BY DATE(debut)
    ORDER BY DATE(debut) DESC
");
$stmt->execute($filtreParams);
$parJour = $stmt->fetchAll();

// ── Toutes les sessions (paginées) ────────────────────────────────────────────
$stmtCount = $db->prepare("SELECT COUNT(*) FROM sessions_occupation $filtreSql");
$stmtCount->execute($filtreParams);
$totalSessions = (int)$stmtCount->fetchColumn();
$totalPages    = max(1, (int)ceil($totalSessions / $parPage));

$stmt = $db->prepare("
    SELECT id, debut, fin, duree_sec
    FROM sessions_occupation $filtreSql
    ORDER BY debut DESC
    LIMIT $parPage OFFSET $offset
");
$stmt->execute($filtreParams);
$sessions = $stmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDuree(int $sec): string
{
    if ($sec < 60)   return "{$sec}s";
    if ($sec < 3600) return floor($sec / 60) . 'min ' . ($sec % 60) . 's';
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    return "{$h}h {$m}min";
}

function tauxJour(int $dureeSec, string $jour): int
{
    $secondesDispo = (strtotime($jour) === strtotime(date('Y-m-d')))
        ? max(1, time() - strtotime('today'))
        : 86400;
    return min(100, (int)round($dureeSec / $secondesDispo * 100));
}

function couleurTaux(int $t): string
{
    if ($t > 70) return 'var(--rouge-alerte)';
    if ($t > 30) return 'var(--terracotta)';
    return 'var(--vert-ok)';
}

// ── Construction URL filtre ───────────────────────────────────────────────────
function urlFiltre(int $page, string $dMin = '', string $dMax = ''): string
{
    $p = array_filter(['page' => $page, 'date_min' => $dMin, 'date_max' => $dMax]);
    return 'admin_stats.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statistiques globales — Admin</title>
  <link rel="stylesheet" href="../public/assets/css/style.css">
  <style>
    .stat-big   { font-size: 2rem; font-weight: 800; line-height: 1.1; }
    .stat-label { font-size: .78rem; color: #8a9aab; margin-top: .25rem; }

    /* ── Barre taux inline ─── */
    .taux-bar-wrap { width: 90px; height: 8px; background: rgba(34,48,60,.1);
                     border-radius: 99px; overflow: hidden; display: inline-block;
                     vertical-align: middle; margin-right: .4rem; }
    .taux-bar      { height: 100%; border-radius: 99px; }

    /* ── Tableau ─── */
    table { width: 100%; border-collapse: collapse; }
    th    { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em;
            color: #8a9aab; padding: .5rem .75rem; border-bottom: 2px solid rgba(34,48,60,.08);
            text-align: left; }
    td    { padding: .55rem .75rem; border-bottom: 1px solid rgba(34,48,60,.06);
            font-size: .88rem; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: rgba(34,48,60,.025); }

    /* ── Pagination ─── */
    .pagination { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: 1rem; }
    .pagination a, .pagination span {
      padding: .3rem .65rem; border-radius: 6px; font-size: .82rem; font-weight: 600;
      border: 1px solid rgba(34,48,60,.15); color: var(--bleu-nuit); text-decoration: none;
    }
    .pagination .actif { background: var(--bleu-nuit); color: #fff; border-color: var(--bleu-nuit); }
    .pagination a:hover { background: rgba(34,48,60,.07); text-decoration: none; }

    /* ── Graphe barres journalières ─── */
    .jour-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(52px, 1fr));
      gap: .35rem;
      align-items: end;
    }
    .jour-col  { text-align: center; }
    .jour-bar-wrap { height: 80px; display: flex; align-items: flex-end; justify-content: center; }
    .jour-bar  { width: 75%; border-radius: 4px 4px 0 0; opacity: .85; }
    .jour-val  { font-size: .66rem; font-weight: 700; color: var(--bleu-nuit); margin-top: .1rem; }
    .jour-lbl  { font-size: .58rem; color: #8a9aab; }

    /* ── Filtre ─── */
    .filtre-form { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
    .filtre-form label { font-size: .8rem; color: #8a9aab; display: block; margin-bottom: .2rem; }
    .filtre-form input[type=date] {
      padding: .38rem .6rem; border: 1px solid rgba(34,48,60,.2);
      border-radius: 8px; font-size: .88rem; background: #fff; color: var(--bleu-nuit);
    }

    .mb-3 { margin-bottom: 1.5rem; }
    .badge-jour {
      padding: .12rem .55rem; border-radius: 99px; font-size: .72rem; font-weight: 700;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main>

  <div class="section-titre" style="margin-bottom:1.5rem">
    <h2>Statistiques globales d'occupation</h2>
    <?php if ($globaux['premier_jour']): ?>
      <p class="text-muted" style="font-size:.85rem">
        Du <?= date('d/m/Y', strtotime($globaux['premier_jour'])) ?>
        au <?= date('d/m/Y', strtotime($globaux['dernier_jour'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <!-- ── Filtre ───────────────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <form method="get" class="filtre-form">
      <div>
        <label>Date de début</label>
        <input type="date" name="date_min" value="<?= htmlspecialchars($dateMin) ?>"
               max="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label>Date de fin</label>
        <input type="date" name="date_max" value="<?= htmlspecialchars($dateMax) ?>"
               max="<?= date('Y-m-d') ?>">
      </div>
      <button type="submit" class="btn btn-primaire btn-sm">Filtrer</button>
      <?php if ($dateMin || $dateMax): ?>
        <a href="admin_stats.php" class="btn btn-sm"
           style="border:1px solid rgba(34,48,60,.2)">Réinitialiser</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── Cartes globales ───────────────────────────────────────────────────── -->
  <div class="grille-cartes mb-3" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">

    <div class="card">
      <div class="card-titre">Sessions totales</div>
      <div class="stat-big" style="color:var(--terracotta)">
        <?= (int)$globaux['nb_sessions'] ?>
      </div>
      <div class="stat-label">sur <?= (int)$globaux['nb_jours'] ?> jour(s) enregistré(s)</div>
    </div>

    <div class="card">
      <div class="card-titre">Temps total d'occupation</div>
      <div class="stat-big" style="color:var(--bleu-nuit)">
        <?= fmtDuree((int)$globaux['total_sec']) ?>
      </div>
      <div class="stat-label">toutes sessions confondues</div>
    </div>

    <div class="card">
      <div class="card-titre">Durée moyenne / session</div>
      <div class="stat-big" style="color:var(--sauge)">
        <?= $globaux['nb_sessions'] > 0 ? fmtDuree((int)$globaux['moy_sec']) : '—' ?>
      </div>
      <div class="stat-label">par utilisation</div>
    </div>

    <div class="card">
      <div class="card-titre">Sessions / jour moyen</div>
      <?php $moyJour = $globaux['nb_jours'] > 0
            ? round($globaux['nb_sessions'] / $globaux['nb_jours'], 1) : 0; ?>
      <div class="stat-big" style="color:var(--ambre)">
        <?= $moyJour ?>
      </div>
      <div class="stat-label">sessions par journée active</div>
    </div>

  </div>

  <!-- ── Graphe taux journaliers ───────────────────────────────────────────── -->
  <?php if (!empty($parJour)): ?>
  <?php $joursGraphe = array_slice(array_reverse($parJour), 0, 14); /* 14 derniers jours */ ?>
  <div class="card mb-3">
    <div class="section-titre"><h2>Taux d'occupation — 14 derniers jours</h2></div>
    <div class="jour-grid">
      <?php foreach ($joursGraphe as $j):
        $taux  = tauxJour((int)$j['duree_totale_sec'], $j['jour']);
        $barH  = max(2, (int)round($taux / 100 * 80));
        $coul  = couleurTaux($taux);
        $label = date('d/m', strtotime($j['jour']));
        $jSem  = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'][date('w', strtotime($j['jour']))];
      ?>
      <div class="jour-col"
           title="<?= $label ?> — <?= $taux ?>% (<?= fmtDuree((int)$j['duree_totale_sec']) ?> · <?= $j['nb_sessions'] ?> session(s))">
        <div class="jour-bar-wrap">
          <div class="jour-bar" style="height:<?= $barH ?>px;background:<?= $coul ?>"></div>
        </div>
        <div class="jour-val"><?= $taux ?>%</div>
        <div class="jour-lbl"><?= $jSem ?> <?= date('d', strtotime($j['jour'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.85rem;font-size:.76rem;color:#8a9aab">
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--vert-ok);border-radius:2px;margin-right:.3rem"></span>< 30 %</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--terracotta);border-radius:2px;margin-right:.3rem"></span>30 – 70 %</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--rouge-alerte);border-radius:2px;margin-right:.3rem"></span>> 70 %</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Tableau par jour ──────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="section-titre"><h2>Récapitulatif par jour</h2></div>
    <?php if (empty($parJour)): ?>
      <p class="text-muted" style="font-size:.88rem">Aucune donnée pour cette période.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Jour</th>
            <th>Sessions</th>
            <th>Temps occupé</th>
            <th>Taux</th>
            <th>Durée moy.</th>
            <th>Plage horaire</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($parJour as $j):
            $taux = tauxJour((int)$j['duree_totale_sec'], $j['jour']);
            $coul = couleurTaux($taux);
            $jourSem = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][date('w', strtotime($j['jour']))];
            $estAujourdhui = $j['jour'] === date('Y-m-d');
          ?>
          <tr>
            <td>
              <strong><?= date('d/m/Y', strtotime($j['jour'])) ?></strong>
              <?php if ($estAujourdhui): ?>
                <span class="badge-jour" style="background:var(--ambre);color:#fff;margin-left:.4rem">Aujourd'hui</span>
              <?php endif; ?>
            </td>
            <td style="color:#8a9aab"><?= $jourSem ?></td>
            <td><strong><?= (int)$j['nb_sessions'] ?></strong></td>
            <td><?= fmtDuree((int)$j['duree_totale_sec']) ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:.4rem">
                <div class="taux-bar-wrap">
                  <div class="taux-bar" style="width:<?= $taux ?>%;background:<?= $coul ?>"></div>
                </div>
                <strong style="color:<?= $coul ?>"><?= $taux ?>%</strong>
              </div>
            </td>
            <td><?= fmtDuree((int)$j['duree_moy_sec']) ?></td>
            <td style="color:#8a9aab;font-size:.82rem">
              <?= substr($j['premiere_session'], 0, 5) ?> → <?= substr($j['derniere_fin'], 0, 5) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Toutes les sessions (paginées) ────────────────────────────────────── -->
  <div class="card">
    <div class="section-titre" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
      <h2>Toutes les sessions</h2>
      <span style="font-size:.82rem;color:#8a9aab">
        <?= $totalSessions ?> session(s) au total
      </span>
    </div>

    <?php if (empty($sessions)): ?>
      <p class="text-muted" style="font-size:.88rem">Aucune session trouvée.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Début</th>
            <th>Fin</th>
            <th>Durée</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s): ?>
          <tr>
            <td style="color:#8a9aab;font-size:.78rem"><?= (int)$s['id'] ?></td>
            <td><?= date('d/m/Y', strtotime($s['debut'])) ?></td>
            <td><?= date('H:i', strtotime($s['debut'])) ?></td>
            <td><?= $s['fin'] ? date('H:i', strtotime($s['fin'])) : '<span style="color:var(--ambre)">En cours</span>' ?></td>
            <td>
              <strong><?= fmtDuree((int)$s['duree_sec']) ?></strong>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars(urlFiltre($page - 1, $dateMin, $dateMax)) ?>">← Préc.</a>
      <?php endif; ?>

      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="actif"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= htmlspecialchars(urlFiltre($p, $dateMin, $dateMax)) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= htmlspecialchars(urlFiltre($page + 1, $dateMin, $dateMax)) ?>">Suiv. →</a>
      <?php endif; ?>

      <span style="margin-left:.5rem;border:none;color:#8a9aab">
        Page <?= $page ?> / <?= $totalPages ?>
      </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── Dernières lectures capteurs ──────────────────────────────────────── -->
  <?php if (!empty($derniersLog)): ?>
  <div class="card" style="margin-top:1.5rem">
    <div class="section-titre" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
      <h2>Dernières lectures capteurs</h2>
      <a href="api_sensors.php/history?hours=1" class="btn btn-secondaire btn-sm"
         target="_blank" rel="noopener" style="font-size:.78rem">JSON API ↗</a>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Heure</th><th>Capteur</th><th>Valeur</th><th>Unité</th></tr>
        </thead>
        <tbody>
          <?php foreach ($derniersLog as $l): ?>
          <tr>
            <td><?= date('H:i:s', strtotime($l['timestamp'])) ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($l['sensor_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= htmlspecialchars(number_format((float)$l['valeur'], 1, ',', ' '), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($l['unite'], ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</main>

<footer>
  Projet IoT ISEP — Groupe G9E &nbsp;·&nbsp; Administration
</footer>

</body>
</html>
