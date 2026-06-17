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

<?php require_once __DIR__ . '/navbar.php'; ?>

<main id="contenu-principal">
