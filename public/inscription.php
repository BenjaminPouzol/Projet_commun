<?php
/**
 * Page d'inscription
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

if (estConnecte()) {
    header('Location: tableau_de_bord.php');
    exit;
}

$erreur  = '';
$succes  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $erreur = 'Formulaire invalide. Veuillez réessayer.';
    } else {
        $nom   = trim($_POST['nom']           ?? '');
        $email = trim($_POST['email']         ?? '');
        $mdp   = $_POST['mot_de_passe']       ?? '';
        $conf  = $_POST['confirmation']       ?? '';

        if ($nom === '' || $email === '' || $mdp === '') {
            $erreur = 'Tous les champs sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse e-mail invalide.';
        } elseif (strlen($mdp) < 8) {
            $erreur = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($mdp !== $conf) {
            $erreur = 'Les mots de passe ne correspondent pas.';
        } else {
            // Vérifier si l'e-mail existe déjà
            $stmt = getDB()->prepare('SELECT id FROM utilisateurs WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            if ($stmt->fetch()) {
                $erreur = 'Cette adresse e-mail est déjà utilisée.';
            } else {
                $hash = password_hash($mdp, PASSWORD_DEFAULT);
                $stmt = getDB()->prepare(
                    'INSERT INTO utilisateurs (nom, email, mot_de_passe) VALUES (:n, :e, :h)'
                );
                $stmt->execute([':n' => $nom, ':e' => $email, ':h' => $hash]);
                $succes = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
            }
        }
    }
}

$pageTitle = 'Inscription';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <h1>Créer un compte</h1>
    <p class="sous-titre">Rejoignez le tableau de bord de la salle de sport</p>

    <?php if ($succes): ?>
      <div class="alerte-flash succes" role="status"><?= htmlspecialchars($succes, ENT_QUOTES, 'UTF-8') ?></div>
      <p class="text-center mt-2"><a href="connexion.php" class="btn btn-primaire">Se connecter</a></p>
    <?php else: ?>

      <?php if ($erreur): ?>
        <div class="alerte-flash erreur" role="alert"><?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="inscription.php" novalidate>
        <?= champCSRF() ?>

        <div class="form-groupe">
          <label for="nom">Nom complet</label>
          <input type="text" id="nom" name="nom" autocomplete="name" required
                 value="<?= htmlspecialchars($_POST['nom'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="Jean Dupont">
        </div>

        <div class="form-groupe">
          <label for="email">Adresse e-mail</label>
          <input type="email" id="email" name="email" autocomplete="email" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="prenom.nom@isep.fr">
        </div>

        <div class="form-groupe">
          <label for="mot_de_passe">Mot de passe <small class="text-muted">(8 caractères min.)</small></label>
          <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="new-password" required minlength="8">
        </div>

        <div class="form-groupe">
          <label for="confirmation">Confirmer le mot de passe</label>
          <input type="password" id="confirmation" name="confirmation" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primaire w-full mt-2">Créer mon compte</button>
      </form>

      <p class="text-center mt-2 text-muted">
        Déjà un compte ? <a href="connexion.php">Se connecter</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
