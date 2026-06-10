<?php
/**
 * En-tête HTML commun à toutes les pages
 * Variable attendue : $pageTitle (string)
 */

require_once __DIR__ . '/../includes/auth.php';

$titre = isset($pageTitle) ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' — ' : '';
$titre .= 'IoT Salle de sport | ISEP G9';

// Chemin relatif vers les assets (depuis public/)
$cssPath = 'assets/css/style.css';

// Page active pour le menu
$script = basename($_SERVER['PHP_SELF']);
function navActive(string $page): string {
    global $script;
    return $script === $page ? ' class="active"' : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Supervision IoT de la salle de sport connectée — Groupe G9 ISEP">
  <title><?= $titre ?></title>
  <link rel="stylesheet" href="<?= $cssPath ?>">
</head>
<body>

<header>
  <nav class="navbar" role="navigation" aria-label="Navigation principale">
    <!-- Logo / marque -->
    <a href="index.php" class="navbar-brand" aria-label="Accueil">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M6.5 6.5h11M6.5 12h11M6.5 17.5h11"/>
        <circle cx="4" cy="6.5" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="17.5" r="1"/>
      </svg>
      <span>IoT Salle de sport</span>
    </a>

    <!-- Liens de navigation -->
    <ul class="navbar-nav" role="list">
      <li><a href="index.php"<?= navActive('index.php') ?>>Accueil</a></li>
      <?php if (estConnecte()): ?>
        <li><a href="tableau_de_bord.php"<?= navActive('tableau_de_bord.php') ?>>Tableau de bord</a></li>
        <li><a href="analyse.php"<?= navActive('analyse.php') ?>>Analyse</a></li>
        <li><a href="actionneurs.php"<?= navActive('actionneurs.php') ?>>Actionneurs</a></li>
      <?php endif; ?>
    </ul>

    <!-- Zone utilisateur -->
    <div class="navbar-user">
      <?php if (estConnecte()): ?>
        <span><?= htmlspecialchars($_SESSION['utilisateur_nom'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (($_SESSION['utilisateur_role'] ?? '') === 'admin'): ?>
          <span class="badge badge-ambre">Admin</span>
        <?php endif; ?>
        <a href="deconnexion.php" class="btn btn-secondaire btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3)">Déconnexion</a>
      <?php else: ?>
        <a href="connexion.php" class="btn btn-primaire btn-sm">Connexion</a>
        <a href="inscription.php" class="btn btn-secondaire btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3)">Inscription</a>
      <?php endif; ?>
    </div>
  </nav>
</header>

<main id="contenu-principal">
