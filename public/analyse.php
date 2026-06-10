<?php
/**
 * Page d'analyse — statistiques inter-groupes, comparaisons, graphique de synthèse
 * Toutes les agrégations sont calculées en SQL (éco-conception).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fonctions.php';

exigerConnexion();

$db = getDB();

// --- Filtres ---
$filtreGroupe = trim($_GET['groupe'] ?? '');
$filtreEquipe = trim($_GET['equipe'] ?? '');
$filtreType   = trim($_GET['type']   ?? '');
$debut        = $_GET['debut'] ?? date('Y-m-d', strtotime('-7 days'));
$fin          = $_GET['fin']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

$groupes = getGroupes();
$equipes = getEquipes($filtreGroupe);
$types   = getTypes();

// --- Construction des conditions de filtre ---
$where  = ['c.actif = 1'];
$params = [
    ':d' => $debut . ' 00:00:00',
    ':f' => $fin   . ' 23:59:59',
];

if ($filtreGroupe !== '') { $where[] = 'c.groupe = :groupe'; $params[':groupe'] = $filtreGroupe; }
if ($filtreEquipe !== '') { $where[] = 'c.equipe = :equipe'; $params[':equipe'] = $filtreEquipe; }
if ($filtreType   !== '') { $where[] = 'c.type = :type';     $params[':type']   = $filtreType;   }

$whereSQL = implode(' AND ', $where);

// --- Statistiques par capteur (agrégées en SQL) ---
$sqlStats = "SELECT
    c.id, c.nom, c.type, c.unite, c.groupe, c.equipe, c.emplacement,
    COUNT(m.id)              AS nb_mesures,
    MIN(m.valeur)            AS valeur_min,
    MAX(m.valeur)            AS valeur_max,
    ROUND(AVG(m.valeur), 2)  AS valeur_moyenne,
    (SELECT m2.valeur     FROM mesures m2 WHERE m2.capteur_id = c.id ORDER BY m2.horodatage DESC LIMIT 1) AS derniere_valeur,
    (SELECT m2.horodatage FROM mesures m2 WHERE m2.capteur_id = c.id ORDER BY m2.horodatage DESC LIMIT 1) AS dernier_horodatage
FROM capteurs c
LEFT JOIN mesures m ON m.capteur_id = c.id
    AND m.horodatage BETWEEN :d AND :f
WHERE $whereSQL
GROUP BY c.id, c.nom, c.type, c.unite, c.groupe, c.equipe, c.emplacement
ORDER BY c.groupe, c.type, c.nom";

$stmtStats = $db->prepare($sqlStats);
$stmtStats->execute($params);
$statistiques = $stmtStats->fetchAll();

// --- Moyennes par groupe × type (pour le graphique de comparaison) ---
$sqlMoyGrp = "SELECT
    c.groupe,
    c.type,
    c.unite,
    ROUND(AVG(m.valeur), 2) AS moyenne
FROM capteurs c
JOIN mesures m ON m.capteur_id = c.id
    AND m.horodatage BETWEEN :d AND :f
WHERE c.actif = 1
GROUP BY c.groupe, c.type, c.unite
ORDER BY c.type, c.groupe";

$stmtMoy = $db->prepare($sqlMoyGrp);
$stmtMoy->execute([':d' => $debut . ' 00:00:00', ':f' => $fin . ' 23:59:59']);
$moyParGroupe = $stmtMoy->fetchAll();

// Préparer les données graphique par type
$graphData = [];
foreach ($moyParGroupe as $row) {
    $graphData[$row['type']][] = [
        'groupe'  => $row['groupe'],
        'moyenne' => (float)$row['moyenne'],
        'unite'   => $row['unite'],
    ];
}

// Données JSON pour Chart.js (premier type disponible ou le type filtré)
$typeGraphique = $filtreType ?: (array_key_first($graphData) ?? '');
$donneesGraph  = $graphData[$typeGraphique] ?? [];
$labelsGraph   = array_column($donneesGraph, 'groupe');
$valeursGraph  = array_column($donneesGraph, 'moyenne');
$uniteGraph    = $donneesGraph[0]['unite'] ?? '';

$pageTitle = 'Analyse';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="section-titre">
  <h1>Analyse transversale</h1>
  <span class="text-muted">
    Du <?= e(date('d/m/Y', strtotime($debut))) ?>
    au <?= e(date('d/m/Y', strtotime($fin))) ?>
  </span>
</div>

<!-- Filtres -->
<form method="get" action="analyse.php" class="barre-filtres" aria-label="Filtres d'analyse">
  <div class="form-groupe">
    <label for="f-groupe">Groupe</label>
    <select id="f-groupe" name="groupe">
      <option value="">Tous</option>
      <?php foreach ($groupes as $g): ?>
        <option value="<?= e($g) ?>" <?= $filtreGroupe === $g ? 'selected' : '' ?>><?= e($g) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-groupe">
    <label for="f-equipe">Équipe</label>
    <select id="f-equipe" name="equipe">
      <option value="">Toutes</option>
      <?php foreach ($equipes as $eq): ?>
        <option value="<?= e($eq) ?>" <?= $filtreEquipe === $eq ? 'selected' : '' ?>><?= e($eq) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-groupe">
    <label for="f-type">Type</label>
    <select id="f-type" name="type">
      <option value="">Tous</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t) ?>" <?= $filtreType === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-groupe">
    <label for="f-debut">Du</label>
    <input type="date" id="f-debut" name="debut" value="<?= e($debut) ?>" max="<?= date('Y-m-d') ?>">
  </div>

  <div class="form-groupe">
    <label for="f-fin">Au</label>
    <input type="date" id="f-fin" name="fin" value="<?= e($fin) ?>" max="<?= date('Y-m-d') ?>">
  </div>

  <div style="display:flex;gap:.5rem;align-items:flex-end">
    <button type="submit" class="btn btn-primaire">Analyser</button>
    <a href="analyse.php" class="btn btn-secondaire">Réinitialiser</a>
  </div>
</form>

<!-- Graphique de comparaison inter-groupes -->
<?php if (!empty($donneesGraph)): ?>
<div class="chart-wrapper mb-3">
  <h2 style="margin-bottom:1rem">
    Comparaison par groupe — <?= e(ucfirst($typeGraphique)) ?>
    <small class="text-muted" style="font-size:.75rem;font-weight:400">(moyenne sur la période)</small>
  </h2>
  <canvas id="graphique-comparaison" height="80"
          aria-label="Graphique de comparaison des moyennes par groupe"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels  = <?= json_encode($labelsGraph,  JSON_THROW_ON_ERROR) ?>;
  const valeurs = <?= json_encode($valeursGraph, JSON_THROW_ON_ERROR) ?>;
  const unite   = <?= json_encode($uniteGraph,   JSON_THROW_ON_ERROR) ?>;
  const couleurs = ['#D96C4A','#7C9A82','#22303C','#E8A33D','#5E8C6A','#C0473B','#B5512F'];

  new Chart(document.getElementById('graphique-comparaison'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Moyenne ' + unite,
        data: valeurs,
        backgroundColor: labels.map((_, i) => couleurs[i % couleurs.length] + 'cc'),
        borderColor:     labels.map((_, i) => couleurs[i % couleurs.length]),
        borderWidth: 2,
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ctx.parsed.y.toFixed(2) + ' ' + unite } }
      },
      scales: {
        y: {
          beginAtZero: false,
          grid: { color: 'rgba(34,48,60,.06)' },
          ticks: { callback: v => v + ' ' + unite }
        },
        x: { grid: { display: false } }
      }
    }
  });
}());
</script>
<?php elseif (!empty($statistiques)): ?>
  <div class="alerte-flash info mb-3">Sélectionnez un type de capteur pour afficher le graphique de comparaison inter-groupes.</div>
<?php endif; ?>

<!-- Tableau récapitulatif des statistiques -->
<div class="section-titre">
  <h2>Tableau récapitulatif</h2>
  <span class="text-muted"><?= count($statistiques) ?> capteur<?= count($statistiques) > 1 ? 's' : '' ?></span>
</div>

<div class="card">
  <?php if (empty($statistiques)): ?>
    <p class="text-muted text-center" style="padding:2rem">Aucune donnée pour ces critères.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table aria-label="Tableau statistique des capteurs">
        <thead>
          <tr>
            <th>Type</th>
            <th>Capteur</th>
            <th>Groupe</th>
            <th>Équipe</th>
            <th>Mesures</th>
            <th>Minimum</th>
            <th>Moyenne</th>
            <th>Maximum</th>
            <th>Dernière valeur</th>
            <th>Horodatage</th>
            <th>État</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($statistiques as $s): ?>
            <tr>
              <td>
                <span class="flex items-center gap-1">
                  <span class="icone-type"><?= iconeType($s['type']) ?></span>
                  <?= e(ucfirst($s['type'])) ?>
                </span>
              </td>
              <td>
                <a href="capteur.php?id=<?= (int)$s['id'] ?>"><?= e($s['nom']) ?></a>
              </td>
              <td><?= e($s['groupe'] ?? '—') ?></td>
              <td><?= e($s['equipe'] ?? '—') ?></td>
              <td class="text-center">
                <?= $s['nb_mesures'] > 0
                    ? number_format($s['nb_mesures'], 0, ',', ' ')
                    : '<span class="text-muted">0</span>' ?>
              </td>
              <td style="color:var(--sauge)">
                <?= $s['nb_mesures'] > 0 ? formatValeur($s['valeur_min'], $s['unite'] ?? '') : '—' ?>
              </td>
              <td><strong>
                <?= $s['nb_mesures'] > 0 ? formatValeur($s['valeur_moyenne'], $s['unite'] ?? '') : '—' ?>
              </strong></td>
              <td style="color:var(--terracotta)">
                <?= $s['nb_mesures'] > 0 ? formatValeur($s['valeur_max'], $s['unite'] ?? '') : '—' ?>
              </td>
              <td>
                <?= $s['derniere_valeur'] !== null
                    ? formatValeur($s['derniere_valeur'], $s['unite'] ?? '')
                    : '<span class="text-muted">—</span>' ?>
              </td>
              <td class="text-muted">
                <?= $s['dernier_horodatage']
                    ? e(date('d/m/Y H:i', strtotime($s['dernier_horodatage'])))
                    : '—' ?>
              </td>
              <td>
                <?php if ($s['derniere_valeur'] !== null): ?>
                  <?= badgeEtat($s['type'], (float)$s['derniere_valeur']) ?>
                <?php else: ?>
                  <span class="badge badge-ambre">Hors ligne</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Synthèse par type de capteur -->
<?php if (!empty($moyParGroupe)): ?>
<div class="section-titre mt-3">
  <h2>Synthèse par type (tous groupes)</h2>
</div>

<?php
// Regrouper les moyennes par type
$synthese = [];
foreach ($moyParGroupe as $r) {
    $synthese[$r['type']][] = $r;
}
?>
<div class="grille-cartes">
  <?php foreach ($synthese as $type => $lignes): ?>
    <div class="card">
      <div class="flex items-center gap-1 mb-2">
        <span class="icone-type"><?= iconeType($type) ?></span>
        <strong><?= e(ucfirst($type)) ?></strong>
      </div>
      <?php foreach ($lignes as $l): ?>
        <div class="flex justify-between items-center" style="font-size:.85rem;padding:.2rem 0;border-bottom:1px solid rgba(34,48,60,.06)">
          <span class="text-muted"><?= e($l['groupe']) ?></span>
          <strong><?= e(number_format($l['moyenne'], 1, ',', ' ')) ?> <?= e($l['unite'] ?? '') ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
