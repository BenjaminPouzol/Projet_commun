<?php
/**
 * Page d'accueil — Vue d'ensemble de la salle de sport
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fonctions.php';

$pageTitle = 'Accueil';

// Récupérer les dernières mesures de chaque type de capteur
$db = getDB();

// Une carte par type de capteur : prend la mesure la plus récente tous groupes confondus
$stmt = $db->query(
    "SELECT c.type, c.unite, c.nom,
            m.valeur, m.horodatage
     FROM capteurs c
     JOIN mesures m ON m.id = (
         SELECT id FROM mesures WHERE capteur_id = c.id ORDER BY horodatage DESC LIMIT 1
     )
     WHERE c.actif = 1
     GROUP BY c.type
     ORDER BY c.type"
);
$dernieresMesures = $stmt->fetchAll();

// Occupation actuelle (capteur de proximité)
$occupation = getOccupationActuelle();
$tauxOccupation = $occupation !== null ? min(100, round($occupation / 50 * 100)) : null;

// Météo extérieure
$meteo = getMeteoExterieure();

// Statut de tous les actionneurs (tous groupes)
$actionneurs = $db->query(
    "SELECT a.*,
            (SELECT commande FROM commandes_actionneurs
             WHERE actionneur_id = a.id ORDER BY horodatage DESC LIMIT 1) AS derniere_commande
     FROM actionneurs a ORDER BY a.type, a.nom"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Hero -->
<div class="hero">
  <h1>
    <svg style="display:inline;width:1.1em;height:1.1em;vertical-align:-.15em;margin-right:.35em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
      <polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
    Salle de sport connectée
  </h1>
  <p>Supervision en temps réel des capteurs IoT — Groupe G9 ISEP</p>

  <?php if (!estConnecte()): ?>
    <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
      <a href="connexion.php" class="btn btn-primaire btn-lg">Se connecter</a>
      <a href="inscription.php" class="btn" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.3)">S'inscrire</a>
    </div>
  <?php else: ?>
    <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
      <a href="tableau_de_bord.php" class="btn btn-primaire btn-lg">Tableau de bord</a>
      <a href="analyse.php" class="btn" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.3)">Analyse</a>
    </div>
  <?php endif; ?>
</div>

<!-- Carte d'occupation principale -->
<div class="grille-cartes" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">

  <!-- Occupation salle -->
  <div class="card" style="border-left:4px solid var(--terracotta)">
    <div class="card-icone"><?= iconeType('proximite') ?></div>
    <div class="card-titre">Occupation actuelle</div>
    <?php if ($occupation !== null): ?>
      <div class="card-valeur">
        <?= (int)$occupation ?><span class="card-unite">personnes</span>
      </div>
      <?php if ($tauxOccupation !== null): ?>
        <!-- Barre de progression d'occupation -->
        <div style="margin-top:.75rem">
          <div style="height:8px;background:rgba(34,48,60,.1);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?= $tauxOccupation ?>%;
                        background:<?= $tauxOccupation > 80 ? 'var(--rouge-alerte)' : ($tauxOccupation > 60 ? 'var(--ambre)' : 'var(--vert-ok)') ?>;
                        border-radius:99px;transition:.3s ease"></div>
          </div>
          <p class="card-sous"><?= $tauxOccupation ?>% de capacité (max 50)</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="card-valeur">—</div>
      <p class="card-sous">Capteur non connecté</p>
    <?php endif; ?>
  </div>

  <?php foreach ($dernieresMesures as $m): ?>
    <?php if ($m['type'] === 'proximite') continue; // déjà affiché ?>
    <div class="card">
      <div class="card-icone"><?= iconeType($m['type']) ?></div>
      <div class="card-titre"><?= e(ucfirst($m['type'])) ?></div>
      <div class="card-valeur">
        <?= e(number_format((float)$m['valeur'], 1, ',', ' ')) ?><span class="card-unite"><?= e($m['unite']) ?></span>
      </div>
      <div style="margin-top:.5rem"><?= badgeEtat($m['type'], (float)$m['valeur']) ?></div>
      <p class="card-sous"><?= e(date('d/m H:i', strtotime($m['horodatage']))) ?></p>
    </div>
  <?php endforeach; ?>

  <!-- Météo extérieure (open-meteo) -->
  <?php if ($meteo): ?>
    <div class="card" style="border-left:4px solid var(--sauge)">
      <div class="card-icone" style="background:rgba(124,154,130,.15);color:var(--sauge)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 0 1 0 9z"/>
        </svg>
      </div>
      <div class="card-titre">Météo extérieure (Paris)</div>
      <div class="card-valeur"><?= e(number_format($meteo['temperature'], 1, ',', '')) ?><span class="card-unite">°C</span></div>
      <p class="card-sous">Vent : <?= e($meteo['windspeed']) ?> km/h &mdash; via Open-Meteo</p>
    </div>
  <?php endif; ?>
</div>

<!-- État des actionneurs -->
<?php if (!empty($actionneurs)): ?>
<div class="section-titre">
  <h2>État des actionneurs</h2>
  <a href="actionneurs.php" class="btn btn-secondaire btn-sm">Gérer →</a>
</div>

<div class="grid-actionneurs mb-3">
  <?php foreach ($actionneurs as $a): ?>
    <div class="actionneur-card">
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem">
        <span class="icone-type"><?= iconeType($a['type']) ?></span>
        <strong><?= e($a['nom']) ?></strong>
      </div>
      <div class="etat">
        <span class="etat-dot <?= $a['etat'] !== 'off' ? 'on' : 'off' ?>"></span>
        <?= e($a['etat']) ?>
        <?php if ($a['derniere_commande']): ?>
          <span class="text-muted" style="margin-left:.5rem;font-weight:400">&rarr; <?= e($a['derniere_commande']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
