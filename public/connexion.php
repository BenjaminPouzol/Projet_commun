<?php
/**
 * Page de connexion
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

// Déjà connecté → tableau de bord
if (estConnecte()) {
    header('Location: tableau_de_bord.php');
    exit;
}

$erreur  = '';
$message = '';

// Message de déconnexion
if (isset($_GET['msg']) && $_GET['msg'] === 'deconnecte') {
    $message = 'Vous avez été déconnecté.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $erreur = 'Formulaire invalide. Veuillez réessayer.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';

        if ($email === '' || $mdp === '') {
            $erreur = 'Veuillez remplir tous les champs.';
        } else {
            $stmt = getDB()->prepare(
                'SELECT id, nom, mot_de_passe, role FROM utilisateurs WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($mdp, $user['mot_de_passe'])) {
                connecterUtilisateur($user);
                $redirect = $_GET['redirect'] ?? 'tableau_de_bord.php';
                // Sécurité : n'accepter que des redirections internes
                if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+\.php(\?.*)?$/', $redirect)) {
                    $redirect = 'tableau_de_bord.php';
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                $erreur = 'E-mail ou mot de passe incorrect.';
            }
        }
    }
}

$pageTitle = 'Connexion';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <h1>Connexion</h1>
    <p class="sous-titre">Accédez au tableau de bord de la salle de sport</p>

    <?php if ($message): ?>
      <div class="alerte-flash info" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
      <div class="alerte-flash erreur" role="alert"><?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="connexion.php<?= isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>" novalidate>
      <?= champCSRF() ?>

      <div class="form-groupe">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" autocomplete="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               placeholder="prenom.nom@isep.fr">
      </div>

      <div class="form-groupe">
        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn btn-primaire w-full mt-2">Se connecter</button>
    </form>

    <p class="text-center mt-2 text-muted">
      Pas encore de compte ? <a href="inscription.php">S'inscrire</a>
    </p>
    <p class="text-center mt-1 text-muted" style="font-size:.8rem">
      Compte de test : <strong>admin@isep.fr</strong> / <strong>admin1234</strong>
    </p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
