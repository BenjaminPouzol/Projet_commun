<?php
/**
 * Tableau de bord — liste de tous les capteurs avec filtres et pagination
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fonctions.php';

exigerConnexion();

$db = getDB();

// --- Filtres ---
$filtreGroupe = trim($_GET['groupe'] ?? '');
$filtreEquipe = trim($_GET['equipe'] ?? '');
$filtreType   = trim($_GET['type']   ?? '');
$pageCourante = max(1, (int)($_GET['page'] ?? 1));
$parPage      = 20;

// Listes pour les selects
$groupes = getGroupes();
$equipes = getEquipes($filtreGroupe);
$types   = getTypes();

// --- Requête principale avec filtres ---
$where    = ['c.actif = 1'];
$params   = [];

if ($filtreGroupe !== '') {
    $where[] = 'c.groupe = :groupe';
    $params[':groupe'] = $filtreGroupe;
}
if ($filtreEquipe !== '') {
    $where[] = 'c.equipe = :equipe';
    $params[':equipe'] = $filtreEquipe;
}
if ($filtreType !== '') {
    $where[] = 'c.type = :type';
    $params[':type'] = $filtreType;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Compte total pour la pagination
$stmtCount = $db->prepare("SELECT COUNT(*) FROM capteurs c $whereSQL");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

['offset' => $offset, 'pages' => $pages, 'pageCourante' => $pageCourante]
    = paginer($total, $parPage, $pageCourante);

// Données capteurs + dernière mesure
$sql = "SELECT c.*,
               m_last.valeur        AS derniere_valeur,
               m_last.horodatage    AS dernier_horodatage
        FROM capteurs c
        LEFT JOIN LATERAL (
            SELECT valeur, horodatage FROM mesures
            WHERE capteur_id = c.id
            ORDER BY horodatage DESC LIMIT 1
        ) m_last ON TRUE
        $whereSQL
        ORDER BY c.groupe, c.equipe, c.type, c.nom
        LIMIT :limit OFFSET :offset";

// Note : LATERAL n'est supporté qu'en MySQL 8.0+.
// Pour MariaDB/MySQL 5.7, on utilise une sous-requête corrélée :
$sql = "SELECT c.*,
               (SELECT valeur     FROM mesures WHERE capteur_id = c.id ORDER BY horodatage DESC LIMIT 1) AS derniere_valeur,
               (SELECT horodatage FROM mesures WHERE capteur_id = c.id ORDER BY horodatage DESC LIMIT 1) AS dernier_horodatage
        FROM capteurs c
        $whereSQL
        ORDER BY c.groupe, c.equipe, c.type, c.nom
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $parPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$capteurs = $stmt->fetchAll();

// URL de base pour la pagination (conserve les filtres)
function urlPagination(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

$pageTitle = 'Tableau de bord';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="section-titre">
  <h1>Tableau de bord</h1>
  <span class="text-muted"><?= $total ?> capteur<?= $total > 1 ? 's' : '' ?> trouvé<?= $total > 1 ? 's' : '' ?></span>
</div>

<!-- Filtres -->
<form method="get" action="tableau_de_bord.php" class="barre-filtres" aria-label="Filtres capteurs">
  <div class="form-groupe">
    <label for="f-groupe">Groupe</label>
    <select id="f-groupe" name="groupe" onchange="this.form.submit()">
      <option value="">Tous les groupes</option>
      <?php foreach ($groupes as $g): ?>
        <option value="<?= e($g) ?>" <?= $filtreGroupe === $g ? 'selected' : '' ?>><?= e($g) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-groupe">
    <label for="f-equipe">Équipe</label>
    <select id="f-equipe" name="equipe">
      <option value="">Toutes les équipes</option>
      <?php foreach ($equipes as $eq): ?>
        <option value="<?= e($eq) ?>" <?= $filtreEquipe === $eq ? 'selected' : '' ?>><?= e($eq) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-groupe">
    <label for="f-type">Type de capteur</label>
    <select id="f-type" name="type">
      <option value="">Tous les types</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t) ?>" <?= $filtreType === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div style="display:flex;gap:.5rem;align-items:flex-end">
    <button type="submit" class="btn btn-primaire">Filtrer</button>
    <a href="tableau_de_bord.php" class="btn btn-secondaire">Réinitialiser</a>
  </div>
</form>

<!-- Tableau des capteurs -->
<div class="card">
  <?php if (empty($capteurs)): ?>
    <p class="text-muted text-center" style="padding:2rem">Aucun capteur trouvé pour ces critères.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table aria-label="Liste des capteurs">
        <thead>
          <tr>
            <th>Type</th>
            <th>Capteur</th>
            <th>Groupe</th>
            <th>Équipe</th>
            <th>Emplacement</th>
            <th>Dernière valeur</th>
            <th>Horodatage</th>
            <th>État</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($capteurs as $c): ?>
            <tr>
              <td>
                <span class="flex items-center gap-1">
                  <span class="icone-type"><?= iconeType($c['type']) ?></span>
                  <?= e(ucfirst($c['type'])) ?>
                </span>
              </td>
              <td><strong><?= e($c['nom']) ?></strong></td>
              <td><?= e($c['groupe'] ?? '—') ?></td>
              <td><?= e($c['equipe'] ?? '—') ?></td>
              <td><?= e($c['emplacement'] ?? '—') ?></td>
              <td>
                <?php if ($c['derniere_valeur'] !== null): ?>
                  <strong><?= formatValeur($c['derniere_valeur'], $c['unite'] ?? '') ?></strong>
                <?php else: ?>
                  <span class="text-muted">Aucune mesure</span>
                <?php endif; ?>
              </td>
              <td class="text-muted">
                <?= $c['dernier_horodatage']
                    ? e(date('d/m/Y H:i', strtotime($c['dernier_horodatage'])))
                    : '—' ?>
              </td>
              <td>
                <?php if ($c['derniere_valeur'] !== null): ?>
                  <?= badgeEtat($c['type'], (float)$c['derniere_valeur']) ?>
                <?php else: ?>
                  <span class="badge badge-ambre">Hors ligne</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="capteur.php?id=<?= (int)$c['id'] ?>"
                   class="btn btn-secondaire btn-sm"
                   aria-label="Voir le détail du capteur <?= e($c['nom']) ?>">
                  Détail →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
      <nav class="pagination" aria-label="Pagination">
        <?php if ($pageCourante > 1): ?>
          <a href="<?= urlPagination(1) ?>" aria-label="Première page">&laquo;</a>
          <a href="<?= urlPagination($pageCourante - 1) ?>" aria-label="Page précédente">&lsaquo;</a>
        <?php endif; ?>

        <?php for ($p = max(1, $pageCourante - 2); $p <= min($pages, $pageCourante + 2); $p++): ?>
          <?php if ($p === $pageCourante): ?>
            <span class="active" aria-current="page"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= urlPagination($p) ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($pageCourante < $pages): ?>
          <a href="<?= urlPagination($pageCourante + 1) ?>" aria-label="Page suivante">&rsaquo;</a>
          <a href="<?= urlPagination($pages) ?>" aria-label="Dernière page">&raquo;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
