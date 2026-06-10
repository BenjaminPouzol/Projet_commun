<?php
/**
 * Détail d'un capteur — historique + graphique des mesures
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fonctions.php';

exigerConnexion();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: tableau_de_bord.php');
    exit;
}

// Récupérer le capteur
$stmtC = $db->prepare('SELECT * FROM capteurs WHERE id = :id AND actif = 1 LIMIT 1');
$stmtC->execute([':id' => $id]);
$capteur = $stmtC->fetch();

if (!$capteur) {
    header('Location: tableau_de_bord.php');
    exit;
}

// Paramètres de période
$debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-7 days'));
$fin   = $_GET['fin']   ?? date('Y-m-d');

// Validation des dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

// Mesures sur la période (pour le graphique : max 500 points, sinon agrégé par heure)
$nbMesures = $db->prepare(
    'SELECT COUNT(*) FROM mesures WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f'
);
$nbMesures->execute([':id' => $id, ':d' => $debut . ' 00:00:00', ':f' => $fin . ' 23:59:59']);
$totalMesures = (int)$nbMesures->fetchColumn();

// Pour le graphique : agréger si trop de points
if ($totalMesures > 500) {
    $sqlGraph = "SELECT DATE_FORMAT(horodatage, '%Y-%m-%d %H:00:00') AS ts,
                        ROUND(AVG(valeur), 2) AS valeur
                 FROM mesures
                 WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f
                 GROUP BY DATE_FORMAT(horodatage, '%Y-%m-%d %H:00:00')
                 ORDER BY ts ASC";
} else {
    $sqlGraph = "SELECT horodatage AS ts, valeur
                 FROM mesures
                 WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f
                 ORDER BY horodatage ASC";
}

$stmtG = $db->prepare($sqlGraph);
$stmtG->execute([':id' => $id, ':d' => $debut . ' 00:00:00', ':f' => $fin . ' 23:59:59']);
$donneeGraph = $stmtG->fetchAll();

// Statistiques sur la période
$stmtStats = $db->prepare(
    "SELECT COUNT(*)            AS nb,
            MIN(valeur)         AS min_val,
            MAX(valeur)         AS max_val,
            ROUND(AVG(valeur), 2) AS moy_val
     FROM mesures
     WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f"
);
$stmtStats->execute([':id' => $id, ':d' => $debut . ' 00:00:00', ':f' => $fin . ' 23:59:59']);
$stats = $stmtStats->fetch();

// Historique récent (tableau paginé)
$pageH = max(1, (int)($_GET['page'] ?? 1));
['offset' => $offsetH, 'pages' => $pagesH, 'pageCourante' => $pageH]
    = paginer($totalMesures, 50, $pageH);

$stmtH = $db->prepare(
    "SELECT valeur, horodatage FROM mesures
     WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f
     ORDER BY horodatage DESC LIMIT 50 OFFSET :offset"
);
$stmtH->execute([
    ':id'     => $id,
    ':d'      => $debut . ' 00:00:00',
    ':f'      => $fin   . ' 23:59:59',
    ':offset' => $offsetH,
]);
// Note : bindValue nécessaire pour les entiers avec LIMIT/OFFSET
$stmtH = $db->prepare(
    "SELECT valeur, horodatage FROM mesures
     WHERE capteur_id = :id AND horodatage BETWEEN :d AND :f
     ORDER BY horodatage DESC LIMIT :lim OFFSET :off"
);
$stmtH->bindValue(':id',  $id,          PDO::PARAM_INT);
$stmtH->bindValue(':d',   $debut . ' 00:00:00');
$stmtH->bindValue(':f',   $fin   . ' 23:59:59');
$stmtH->bindValue(':lim', 50,            PDO::PARAM_INT);
$stmtH->bindValue(':off', $offsetH,      PDO::PARAM_INT);
$stmtH->execute();
$historique = $stmtH->fetchAll();

// Préparer les données JSON pour Chart.js
$labels  = array_map(fn($r) => $r['ts'], $donneeGraph);
$valeurs = array_map(fn($r) => (float)$r['valeur'], $donneeGraph);

$pageTitle = htmlspecialchars($capteur['nom'], ENT_QUOTES, 'UTF-8') . ' — Détail capteur';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- En-tête capteur -->
<div class="section-titre">
  <div class="flex items-center gap-2">
    <span class="icone-type" style="width:42px;height:42px"><?= iconeType($capteur['type']) ?></span>
    <div>
      <h1 style="margin:0"><?= e($capteur['nom']) ?></h1>
      <p class="text-muted">
        <?= e(ucfirst($capteur['type'])) ?> &mdash;
        <?= e($capteur['groupe'] ?? '') ?> / <?= e($capteur['equipe'] ?? '') ?>
        <?php if ($capteur['emplacement']): ?> &mdash; <?= e($capteur['emplacement']) ?><?php endif; ?>
      </p>
    </div>
  </div>
  <a href="tableau_de_bord.php" class="btn btn-secondaire btn-sm">← Tableau de bord</a>
</div>

<!-- Filtre de période -->
<form method="get" action="capteur.php" class="barre-filtres" aria-label="Filtre de période">
  <input type="hidden" name="id" value="<?= (int)$capteur['id'] ?>">
  <div class="form-groupe">
    <label for="debut">Du</label>
    <input type="date" id="debut" name="debut" value="<?= e($debut) ?>"
           max="<?= date('Y-m-d') ?>">
  </div>
  <div class="form-groupe">
    <label for="fin">Au</label>
    <input type="date" id="fin" name="fin" value="<?= e($fin) ?>"
           max="<?= date('Y-m-d') ?>">
  </div>
  <div style="align-self:flex-end">
    <button type="submit" class="btn btn-primaire">Appliquer</button>
  </div>
</form>

<!-- Cartes statistiques -->
<div class="grille-cartes mb-3">
  <div class="card text-center">
    <div class="stat-mini">
      <span class="lbl">Mesures</span>
      <span class="val"><?= number_format($stats['nb'], 0, ',', ' ') ?></span>
    </div>
  </div>
  <div class="card text-center">
    <div class="stat-mini">
      <span class="lbl">Minimum</span>
      <span class="val" style="color:var(--sauge)"><?= formatValeur($stats['min_val'], $capteur['unite'] ?? '') ?></span>
    </div>
  </div>
  <div class="card text-center">
    <div class="stat-mini">
      <span class="lbl">Maximum</span>
      <span class="val" style="color:var(--terracotta)"><?= formatValeur($stats['max_val'], $capteur['unite'] ?? '') ?></span>
    </div>
  </div>
  <div class="card text-center">
    <div class="stat-mini">
      <span class="lbl">Moyenne</span>
      <span class="val"><?= formatValeur($stats['moy_val'], $capteur['unite'] ?? '') ?></span>
    </div>
  </div>
</div>

<!-- Graphique -->
<?php if (!empty($donneeGraph)): ?>
<div class="chart-wrapper">
  <h2 style="margin-bottom:1rem">
    Évolution — <?= e($capteur['nom']) ?>
    <?php if ($totalMesures > 500): ?>
      <small class="text-muted" style="font-size:.7rem;font-weight:400">(moyennes horaires)</small>
    <?php endif; ?>
  </h2>
  <canvas id="graphique-capteur" height="100" aria-label="Graphique des mesures du capteur <?= e($capteur['nom']) ?>"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels  = <?= json_encode($labels,  JSON_THROW_ON_ERROR) ?>;
  const valeurs = <?= json_encode($valeurs, JSON_THROW_ON_ERROR) ?>;
  const unite   = <?= json_encode($capteur['unite'] ?? '', JSON_THROW_ON_ERROR) ?>;

  new Chart(document.getElementById('graphique-capteur'), {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: unite,
        data: valeurs,
        borderColor: '#D96C4A',
        backgroundColor: 'rgba(217,108,74,.1)',
        borderWidth: 2,
        pointRadius: labels.length > 100 ? 0 : 3,
        tension: 0.3,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      interaction: { intersect: false, mode: 'index' },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ctx.parsed.y.toFixed(1) + ' ' + unite
          }
        }
      },
      scales: {
        x: {
          ticks: { maxTicksLimit: 10, maxRotation: 30 },
          grid: { color: 'rgba(34,48,60,.06)' }
        },
        y: {
          grid: { color: 'rgba(34,48,60,.06)' },
          ticks: { callback: v => v + ' ' + unite }
        }
      }
    }
  });
}());
</script>
<?php endif; ?>

<!-- Historique -->
<div class="section-titre mt-3">
  <h2>Historique des mesures</h2>
  <span class="text-muted"><?= number_format($totalMesures, 0, ',', ' ') ?> mesure<?= $totalMesures > 1 ? 's' : '' ?></span>
</div>

<div class="card">
  <?php if (empty($historique)): ?>
    <p class="text-muted text-center" style="padding:1.5rem">Aucune mesure sur cette période.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table aria-label="Historique des mesures">
        <thead>
          <tr>
            <th>#</th>
            <th>Valeur</th>
            <th>État</th>
            <th>Horodatage</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historique as $i => $m): ?>
            <tr>
              <td class="text-muted"><?= $offsetH + $i + 1 ?></td>
              <td><strong><?= formatValeur($m['valeur'], $capteur['unite'] ?? '') ?></strong></td>
              <td><?= badgeEtat($capteur['type'], (float)$m['valeur']) ?></td>
              <td><?= e(date('d/m/Y H:i:s', strtotime($m['horodatage']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination historique -->
    <?php if ($pagesH > 1): ?>
      <nav class="pagination" aria-label="Pagination historique">
        <?php for ($p = max(1, $pageH - 2); $p <= min($pagesH, $pageH + 2); $p++): ?>
          <?php
            $url = '?id=' . $capteur['id'] . '&debut=' . $debut . '&fin=' . $fin . '&page=' . $p;
          ?>
          <?php if ($p === $pageH): ?>
            <span class="active" aria-current="page"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= $url ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
