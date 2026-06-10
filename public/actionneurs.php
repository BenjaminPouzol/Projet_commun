<?php
/**
 * Pilotage des actionneurs — LED et OLED
 * Insère les commandes dans commandes_actionneurs ; l'équipe G9C relaie vers la carte.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fonctions.php';

exigerConnexion();

$db = getDB();
$message = '';
$erreur  = '';

// --- Traitement d'une commande ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actionneur_id'], $_POST['commande'])) {
    if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $erreur = 'Requête invalide (CSRF).';
    } else {
        $actId    = (int)$_POST['actionneur_id'];
        $commande = trim($_POST['commande']);

        // Valider la commande (liste blanche)
        $commandesAutorisees = [
            // LED
            'on_vert', 'on_rouge', 'off',
            // OLED
            'oled:Salle ouverte', 'oled:Salle pleine', 'oled:Bienvenue !',
            'oled:Fermeture 22h', 'oled:Nettoyage en cours', 'oled:off',
            // Buzzer / moteur
            'buzzer:on', 'buzzer:off', 'moteur:on', 'moteur:off',
        ];

        if ($actId > 0 && in_array($commande, $commandesAutorisees, true)) {
            // Insérer la commande
            $stmt = $db->prepare(
                'INSERT INTO commandes_actionneurs (actionneur_id, commande) VALUES (:a, :c)'
            );
            $stmt->execute([':a' => $actId, ':c' => $commande]);

            // Mettre à jour l'état de l'actionneur
            $etat = $commande;
            if (str_starts_with($commande, 'oled:')) $etat = substr($commande, 5);
            $db->prepare('UPDATE actionneurs SET etat = :e WHERE id = :id')
               ->execute([':e' => $etat, ':id' => $actId]);

            $message = 'Commande « ' . htmlspecialchars($commande, ENT_QUOTES, 'UTF-8') . ' » envoyée.';
        } else {
            $erreur = 'Commande invalide ou actionneur introuvable.';
        }
    }
}

// --- Récupérer les actionneurs ---
$actionneurs = $db->query(
    "SELECT a.*,
            (SELECT ca.commande     FROM commandes_actionneurs ca WHERE ca.actionneur_id = a.id ORDER BY ca.horodatage DESC LIMIT 1) AS derniere_commande,
            (SELECT ca.horodatage   FROM commandes_actionneurs ca WHERE ca.actionneur_id = a.id ORDER BY ca.horodatage DESC LIMIT 1) AS derniere_commande_ts
     FROM actionneurs a ORDER BY a.groupe, a.type, a.nom"
)->fetchAll();

// --- Historique des 30 dernières commandes ---
$historique = $db->query(
    "SELECT ca.*, a.nom AS actionneur_nom, a.type AS actionneur_type
     FROM commandes_actionneurs ca
     JOIN actionneurs a ON a.id = ca.actionneur_id
     ORDER BY ca.horodatage DESC LIMIT 30"
)->fetchAll();

$pageTitle = 'Actionneurs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="section-titre">
  <h1>Actionneurs</h1>
  <span class="text-muted">Pilotez les LED et l'écran OLED de la salle</span>
</div>

<?php if ($message): ?>
  <div class="alerte-flash succes" role="status"><?= $message ?></div>
<?php endif; ?>
<?php if ($erreur): ?>
  <div class="alerte-flash erreur" role="alert"><?= e($erreur) ?></div>
<?php endif; ?>

<?php if (empty($actionneurs)): ?>
  <div class="card text-center" style="padding:2rem">
    <p class="text-muted">Aucun actionneur enregistré. Lancez d'abord <code>scripts/seed.php</code>.</p>
  </div>
<?php else: ?>

<div class="grid-actionneurs">
  <?php foreach ($actionneurs as $a): ?>
    <div class="actionneur-card">
      <!-- En-tête -->
      <div class="flex items-center gap-2" style="margin-bottom:.75rem">
        <span class="icone-type" style="width:38px;height:38px"><?= iconeType($a['type']) ?></span>
        <div>
          <strong><?= e($a['nom']) ?></strong>
          <div class="text-muted" style="font-size:.8rem"><?= e($a['groupe']) ?> / <?= e($a['equipe']) ?></div>
        </div>
        <div style="margin-left:auto">
          <div class="etat">
            <span class="etat-dot <?= ($a['etat'] !== 'off' && $a['etat'] !== null) ? 'on' : 'off' ?>"></span>
            <?= e($a['etat'] ?? 'off') ?>
          </div>
        </div>
      </div>

      <!-- Boutons de commande selon le type -->
      <form method="post" action="actionneurs.php">
        <?= champCSRF() ?>
        <input type="hidden" name="actionneur_id" value="<?= (int)$a['id'] ?>">

        <?php if ($a['type'] === 'led'): ?>
          <p style="font-size:.8rem;color:#8a9aab;margin-bottom:.5rem">Poste : vert = libre, rouge = occupé</p>
          <div class="btn-group">
            <button type="submit" name="commande" value="on_vert" class="btn btn-succes btn-sm">
              Libre (vert)
            </button>
            <button type="submit" name="commande" value="on_rouge" class="btn btn-danger btn-sm">
              Occupé (rouge)
            </button>
            <button type="submit" name="commande" value="off" class="btn btn-secondaire btn-sm">
              Éteindre
            </button>
          </div>

        <?php elseif ($a['type'] === 'oled'): ?>
          <p style="font-size:.8rem;color:#8a9aab;margin-bottom:.5rem">Message à afficher sur l'écran</p>
          <div class="btn-group">
            <button type="submit" name="commande" value="oled:Salle ouverte" class="btn btn-succes btn-sm">Salle ouverte</button>
            <button type="submit" name="commande" value="oled:Salle pleine"  class="btn btn-danger btn-sm">Salle pleine</button>
            <button type="submit" name="commande" value="oled:Bienvenue !"   class="btn btn-primaire btn-sm">Bienvenue</button>
            <button type="submit" name="commande" value="oled:Fermeture 22h" class="btn btn-ambre btn-sm">Fermeture 22h</button>
            <button type="submit" name="commande" value="oled:off"            class="btn btn-secondaire btn-sm">Éteindre</button>
          </div>

        <?php elseif ($a['type'] === 'buzzer'): ?>
          <div class="btn-group">
            <button type="submit" name="commande" value="buzzer:on"  class="btn btn-primaire btn-sm">Activer</button>
            <button type="submit" name="commande" value="buzzer:off" class="btn btn-secondaire btn-sm">Désactiver</button>
          </div>

        <?php elseif ($a['type'] === 'moteur'): ?>
          <div class="btn-group">
            <button type="submit" name="commande" value="moteur:on"  class="btn btn-primaire btn-sm">Démarrer</button>
            <button type="submit" name="commande" value="moteur:off" class="btn btn-secondaire btn-sm">Arrêter</button>
          </div>

        <?php else: ?>
          <div class="btn-group">
            <button type="submit" name="commande" value="off" class="btn btn-secondaire btn-sm">Éteindre</button>
          </div>
        <?php endif; ?>
      </form>

      <?php if ($a['derniere_commande']): ?>
        <p class="text-muted mt-1" style="font-size:.78rem">
          Dernière commande : <strong><?= e($a['derniere_commande']) ?></strong>
          — <?= e(date('d/m H:i', strtotime($a['derniere_commande_ts']))) ?>
        </p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Historique des commandes -->
<?php if (!empty($historique)): ?>
<div class="section-titre mt-3">
  <h2>Historique des commandes</h2>
  <span class="text-muted">30 dernières</span>
</div>

<div class="card">
  <div class="table-wrapper">
    <table aria-label="Historique des commandes actionneurs">
      <thead>
        <tr>
          <th>Actionneur</th>
          <th>Type</th>
          <th>Commande</th>
          <th>Envoyé</th>
          <th>Horodatage</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($historique as $h): ?>
          <tr>
            <td><?= e($h['actionneur_nom']) ?></td>
            <td>
              <span class="flex items-center gap-1">
                <span class="icone-type"><?= iconeType($h['actionneur_type']) ?></span>
                <?= e($h['actionneur_type']) ?>
              </span>
            </td>
            <td><code><?= e($h['commande']) ?></code></td>
            <td>
              <?php if ($h['envoye']): ?>
                <span class="badge badge-ok">Relayé</span>
              <?php else: ?>
                <span class="badge badge-ambre">En attente</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= e(date('d/m/Y H:i:s', strtotime($h['horodatage']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
