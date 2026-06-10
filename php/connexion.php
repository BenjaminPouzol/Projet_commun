<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

// Déjà connecté → dashboard
if (estConnecte()) {
    header('Location: dashboard.php');
    exit;
}

$erreur  = '';
$message = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'deconnecte') {
    $message = 'Vous avez été déconnecté.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $erreur = 'Formulaire invalide. Veuillez réessayer.';
    } else {
        $email = trim($_POST['email']    ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';

        if ($email === '' || $mdp === '') {
            $erreur = 'Veuillez remplir tous les champs.';
        } else {
            $stmt = getDB()->prepare(
                'SELECT id, nom, mot_de_passe, role FROM utilisateurs WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($mdp, $user['mot_de_passe'])) {
                connecterUtilisateur($user);
                // Redirect sécurisé : uniquement vers une page .php interne
                $redirect = $_GET['redirect'] ?? 'dashboard.php';
                if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+\.php(\?.*)?$/', $redirect)) {
                    $redirect = 'dashboard.php';
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                // Pause anti-bruteforce
                usleep(300_000);
                $erreur = 'E-mail ou mot de passe incorrect.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — Salle de sport</title>
  <link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>

<nav class="navbar">
  <a class="navbar-brand" href="dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
      <circle cx="9" cy="7" r="4"/>
      <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
    Salle de sport — IoT ISEP
  </a>
</nav>

<main>
  <div class="auth-wrapper">
    <div class="auth-card">
      <h1>Connexion</h1>
      <p class="sous-titre">Accédez au tableau de bord de la salle</p>

      <?php if ($message): ?>
        <div class="alerte-flash info" role="status">
          <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($erreur): ?>
        <div class="alerte-flash erreur" role="alert">
          <?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post"
            action="connexion.php<?= isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>"
            novalidate>
        <?= champCSRF() ?>

        <div class="form-groupe">
          <label for="email">Adresse e-mail</label>
          <input type="email" id="email" name="email" autocomplete="email" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="prenom.nom@isep.fr">
        </div>

        <div class="form-groupe">
          <label for="mot_de_passe">Mot de passe</label>
          <input type="password" id="mot_de_passe" name="mot_de_passe"
                 autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn-primaire w-full mt-2">
          Se connecter
        </button>
      </form>

      <p class="text-center mt-2 text-muted">
        Pas encore de compte ? <a href="inscription.php">S'inscrire</a>
      </p>

    </div>
  </div>
</main>

<footer>
  Projet IoT ISEP — Groupe G9E &nbsp;·&nbsp; Salle de sport connectée
</footer>

</body>
</html>
