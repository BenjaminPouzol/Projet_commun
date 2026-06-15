<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

exigerConnexion();

$db = getDB();

// ── Correction manuelle ───────────────────────────────────────────────────────
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $flashMsg = ['type' => 'erreur', 'texte' => 'Token invalide.'];
    } elseif ($_POST['action'] === 'forcer') {
        $statut = ($_POST['statut'] ?? '') === 'OCCUPEE' ? 'OCCUPEE' : 'LIBRE';
        $db->prepare("UPDATE machine_status SET statut=?, depuis=NOW(), last_update=NOW() WHERE machine_id=?")
           ->execute([$statut, MACHINE_ID]);
        $db->prepare("INSERT INTO machine_log (machine_id, statut, valeur_brute) VALUES (?,?,0)")
           ->execute([MACHINE_ID, $statut]);
        $flashMsg = ['type' => 'succes', 'texte' => "Statut forcé manuellement : {$statut}"];
    }
}

// ── Statut actuel ─────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT statut, valeur_brute, last_update,
           TIMESTAMPDIFF(SECOND, depuis, NOW()) AS secondes_depuis
    FROM machine_status WHERE machine_id = ?
");
$stmt->execute([MACHINE_ID]);
$machine = $stmt->fetch() ?: ['statut' => 'LIBRE', 'valeur_brute' => 0, 'secondes_depuis' => 0];

// ── Stats du jour ─────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(CASE WHEN statut = 'OCCUPEE' THEN 1 END) AS nb_utilisations,
        COUNT(*) AS nb_changements
    FROM machine_log
    WHERE machine_id = ? AND DATE(timestamp) = CURDATE()
");
$stmt->execute([MACHINE_ID]);
$statsJour = $stmt->fetch();

// Durée totale d'occupation aujourd'hui (en secondes)
// On associe chaque OCCUPEE avec le LIBRE suivant
$stmt = $db->prepare("
    SELECT SUM(duree_sec) AS total_sec
    FROM (
        SELECT TIMESTAMPDIFF(SECOND, l1.timestamp,
            COALESCE(
                (SELECT MIN(l2.timestamp) FROM machine_log l2
                 WHERE l2.machine_id = l1.machine_id
                   AND l2.statut = 'LIBRE'
                   AND l2.timestamp > l1.timestamp
                   AND DATE(l2.timestamp) = CURDATE()),
                NOW()
            )
        ) AS duree_sec
        FROM machine_log l1
        WHERE l1.machine_id = ? AND l1.statut = 'OCCUPEE' AND DATE(l1.timestamp) = CURDATE()
    ) t
");
$stmt->execute([MACHINE_ID]);
$totalSecOccupe = (int) ($stmt->fetchColumn() ?: 0);

// ── Taux d'occupation du jour depuis sessions_occupation ─────────────────────
// On somme les sessions enregistrées + la session en cours si la machine est occupée
$stmt = $db->prepare("
    SELECT COALESCE(SUM(duree_sec), 0) AS total_sec_sessions
    FROM sessions_occupation
    WHERE machine_id = ? AND DATE(debut) = CURDATE()
");
$stmt->execute([MACHINE_ID]);
$secSessions = (int) $stmt->fetchColumn();

// Secondes écoulées depuis minuit (dénominateur du taux)
$secsJournee = max(1, time() - strtotime('today'));

// Utilisations récentes (paires OCCUPEE→LIBRE) ────────────────────────────────
$stmt = $db->prepare("
    SELECT
        l1.timestamp                                              AS debut,
        (SELECT MIN(l2.timestamp) FROM machine_log l2
         WHERE l2.machine_id = l1.machine_id
           AND l2.statut = 'LIBRE' AND l2.timestamp > l1.timestamp) AS fin,
        TIMESTAMPDIFF(SECOND, l1.timestamp,
            COALESCE(
                (SELECT MIN(l2.timestamp) FROM machine_log l2
                 WHERE l2.machine_id = l1.machine_id
                   AND l2.statut = 'LIBRE' AND l2.timestamp > l1.timestamp),
                NOW()
            )
        ) AS duree_sec
    FROM machine_log l1
    WHERE l1.machine_id = ? AND l1.statut = 'OCCUPEE'
    ORDER BY l1.timestamp DESC
    LIMIT 8
");
$stmt->execute([MACHINE_ID]);
$utilisations = $stmt->fetchAll();

// ── Taux d'occupation par heure (historique) ──────────────────────────────────
// Pour chaque heure, combien de fois la machine était OCCUPÉE vs LIBRE
$stmt = $db->prepare("
    SELECT HOUR(timestamp) AS heure,
           COUNT(CASE WHEN statut = 'OCCUPEE' THEN 1 END) AS nb_occ,
           COUNT(*) AS nb_total
    FROM machine_log
    WHERE machine_id = ?
    GROUP BY HOUR(timestamp)
    ORDER BY heure
");
$stmt->execute([MACHINE_ID]);
$tauxHoraires = $stmt->fetchAll();

// ── Endpoint JSON pour mise à jour silencieuse ────────────────────────────────
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    echo json_encode([
        'statut'          => $machine['statut'],
        'valeur_brute'    => (int) $machine['valeur_brute'],
        'secondes_depuis' => (int) $machine['secondes_depuis'],
        'last_update'     => $machine['last_update'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function dureeHumaine(int $sec): string
{
    if ($sec < 60)   return "{$sec} s";
    if ($sec < 3600) return floor($sec / 60) . ' min ' . ($sec % 60) . ' s';
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    return "{$h} h {$m} min";
}

$estOccupee     = $machine['statut'] === 'OCCUPEE';
$secondes       = (int) $machine['secondes_depuis'];

// Taux d'occupation = (sessions terminées + session en cours) / secondes écoulées
$secOccupeTotal  = $secSessions + ($estOccupee ? $secondes : 0);
$tauxOccupation  = min(100, (int) round($secOccupeTotal / $secsJournee * 100));
$lastUpdate     = $machine['last_update']
                    ? date('H:i:s', strtotime($machine['last_update']))
                    : '—';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statut machine — Salle de sport</title>
  <link rel="stylesheet" href="../public/assets/css/style.css">
  <style>
    /* ── Indicateur principal ─────────────────────────────── */
    .statut-hero {
      padding: 2.5rem 2rem 2rem;
      text-align: center;
      border-radius: var(--radius-lg);
      margin-bottom: 2rem;
      transition: background .4s ease;
    }
    .statut-hero.libre   { background: linear-gradient(135deg, #2d5a3d 0%, #3a7a52 100%); }
    .statut-hero.occupee { background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c  100%); }

    .statut-label {
      font-size: clamp(2.5rem, 8vw, 5rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: .04em;
      line-height: 1;
    }
    .statut-depuis {
      font-size: 1.05rem;
      color: rgba(255,255,255,.75);
      margin-top: .6rem;
    }
    .statut-valeur {
      font-size: .8rem;
      color: rgba(255,255,255,.4);
      margin-top: .35rem;
    }

    /* ── Voyant animé ──────────────────────────────────────── */
    .voyant {
      display: inline-block;
      width: 18px; height: 18px;
      border-radius: 50%;
      vertical-align: middle;
      margin-right: .5rem;
      margin-bottom: .15rem;
    }
    .voyant.libre   { background: #4ade80; box-shadow: 0 0 0 4px rgba(74,222,128,.3); }
    .voyant.occupee {
      background: #f87171;
      box-shadow: 0 0 0 4px rgba(248,113,113,.3);
      animation: pulse 1.2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%,100% { box-shadow: 0 0 0 4px rgba(248,113,113,.3); }
      50%      { box-shadow: 0 0 0 8px rgba(248,113,113,.1); }
    }

    /* ── Stats ─────────────────────────────────────────────── */
    .stat-big { font-size: 2.2rem; font-weight: 800; line-height: 1; }

    /* ── Barres taux horaire ───────────────────────────────── */
    .heure-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(48px, 1fr));
      gap: .4rem;
      align-items: end;
    }
    .heure-col { text-align: center; }
    .heure-bar-wrap { height: 70px; display: flex; align-items: flex-end; justify-content: center; }
    .heure-bar {
      width: 72%;
      background: var(--terracotta);
      border-radius: 4px 4px 0 0;
      opacity: .82;
    }
    .heure-val { font-size: .68rem; font-weight: 700; color: var(--bleu-nuit); margin-top: .15rem; }
    .heure-lbl { font-size: .6rem; color: #8a9aab; }
  </style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────────────────────── -->
<nav class="navbar">
  <a class="navbar-brand" href="dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="2" y="3" width="20" height="14" rx="2"/>
      <line x1="8" y1="21" x2="16" y2="21"/>
      <line x1="12" y1="17" x2="12" y2="21"/>
    </svg>
    Salle de sport — Capteur de proximité
  </a>
  <ul class="navbar-nav">
    <li><a href="dashboard.php" class="active">Proximité G9E</a></li>
    <li><a href="dashboard_global.php">Vue globale</a></li>
    <li><a href="../public/actionneurs.php">Actionneurs</a></li>
  </ul>
  <div class="navbar-user">
    <span><?= htmlspecialchars($_SESSION['utilisateur_nom'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    <a href="deconnexion.php"
       style="color:rgba(255,255,255,.65);font-size:.82rem;padding:.3rem .65rem;
              border:1px solid rgba(255,255,255,.2);border-radius:6px;text-decoration:none">
      Déconnexion
    </a>
  </div>
</nav>

<main>

  <?php if ($flashMsg): ?>
    <div class="alerte-flash <?= $flashMsg['type'] ?>" role="status" style="margin-bottom:1.25rem">
      <?= htmlspecialchars($flashMsg['texte'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- ── Statut principal ──────────────────────────────────────────────────── -->
  <div class="statut-hero <?= $estOccupee ? 'occupee' : 'libre' ?>" id="js-hero">
    <div class="statut-label">
      <span class="voyant <?= $estOccupee ? 'occupee' : 'libre' ?>" id="js-voyant"></span>
      <span id="js-statut"><?= $estOccupee ? 'OCCUPÉE' : 'LIBRE' ?></span>
    </div>
    <div class="statut-depuis" id="js-depuis">
      <?= $estOccupee ? 'Occupée' : 'Libre' ?> depuis
      <strong><?= dureeHumaine($secondes) ?></strong>
    </div>
    <div class="statut-valeur" id="js-valeur">
      Capteur : <?= (int) $machine['valeur_brute'] ?> / 4095 &nbsp;·&nbsp;
      Seuil : <?= SEUIL ?>
    </div>
  </div>

  <!-- ── Cartes stats ───────────────────────────────────────────────────────── -->
  <div class="grille-cartes" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">

    <div class="card">
      <div class="card-titre">Utilisations aujourd'hui</div>
      <div class="stat-big" style="color:var(--terracotta)">
        <?= (int)$statsJour['nb_utilisations'] ?>
      </div>
      <div class="card-sous">fois la machine utilisée</div>
    </div>

    <div class="card">
      <div class="card-titre">Temps occupé aujourd'hui</div>
      <div class="stat-big" style="color:var(--bleu-nuit)">
        <?= dureeHumaine($totalSecOccupe) ?>
      </div>
      <div class="card-sous">durée totale d'occupation</div>
    </div>

    <div class="card">
      <div class="card-titre">Durée moy. par utilisation</div>
      <?php
        $moy = $statsJour['nb_utilisations'] > 0
            ? (int) ($totalSecOccupe / $statsJour['nb_utilisations'])
            : 0;
      ?>
      <div class="stat-big" style="color:var(--sauge)">
        <?= dureeHumaine($moy) ?>
      </div>
      <div class="card-sous">par session aujourd'hui</div>
    </div>

    <div class="card">
      <div class="card-titre">Taux d'occupation du jour</div>
      <?php
        $couleurTaux = $tauxOccupation > 70 ? 'var(--rouge-alerte)'
                     : ($tauxOccupation > 30 ? 'var(--terracotta)' : 'var(--sauge)');
      ?>
      <div class="stat-big" style="color:<?= $couleurTaux ?>">
        <?= $tauxOccupation ?> %
      </div>
      <div class="card-sous"><?= dureeHumaine($secOccupeTotal) ?> sur <?= dureeHumaine($secsJournee) ?> écoulées</div>
      <!-- Barre de progression -->
      <div style="height:6px;background:rgba(34,48,60,.1);border-radius:99px;overflow:hidden;margin-top:.75rem">
        <div style="height:100%;width:<?= $tauxOccupation ?>%;background:<?= $couleurTaux ?>;
                    border-radius:99px;transition:.3s ease"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-titre">Capteur en direct</div>
      <div class="stat-big" id="js-val-num" style="color:var(--ambre)">
        <?= (int) $machine['valeur_brute'] ?>
      </div>
      <div class="card-sous">valeur brute (0–4095) · seuil <?= SEUIL ?></div>
      <!-- Mini jauge -->
      <div style="height:6px;background:rgba(34,48,60,.1);border-radius:99px;overflow:hidden;margin-top:.75rem">
        <div id="js-jauge"
             style="height:100%;width:<?= min(100, round($machine['valeur_brute'] / 4095 * 100)) ?>%;
                    background:<?= $estOccupee ? 'var(--rouge-alerte)' : 'var(--vert-ok)' ?>;
                    border-radius:99px;transition:.3s ease"></div>
      </div>
    </div>

  </div>

  <!-- ── Taux d'utilisation par heure ──────────────────────────────────────── -->
  <?php if (!empty($tauxHoraires)): ?>
  <div class="chart-wrapper mb-3">
    <div class="section-titre">
      <h2>Taux d'utilisation par heure</h2>
    </div>
    <?php
      $parHeure = array_fill(0, 24, ['nb_occ' => 0, 'nb_total' => 0]);
      foreach ($tauxHoraires as $h) {
          $parHeure[(int)$h['heure']] = $h;
      }
    ?>
    <div class="heure-grid">
      <?php for ($i = 0; $i < 24; $i++):
        $d    = $parHeure[$i];
        $taux = $d['nb_total'] > 0 ? round($d['nb_occ'] / $d['nb_total'] * 100) : 0;
        $h    = max(2, round($taux / 100 * 70));
      ?>
        <div class="heure-col"
             title="<?= $i ?>h : <?= $taux ?>% occupée (<?= (int)$d['nb_occ'] ?>/<?= (int)$d['nb_total'] ?> lectures)">
          <div class="heure-bar-wrap">
            <div class="heure-bar" style="height:<?= $taux > 0 ? $h : 0 ?>px;
                 background:<?= $taux > 70 ? 'var(--rouge-alerte)' : ($taux > 30 ? 'var(--terracotta)' : 'var(--sauge)') ?>">
            </div>
          </div>
          <div class="heure-val"><?= $taux > 0 ? $taux . '%' : '—' ?></div>
          <div class="heure-lbl"><?= $i ?>h</div>
        </div>
      <?php endfor; ?>
    </div>
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:1rem;font-size:.78rem;color:#8a9aab">
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--sauge);border-radius:2px;margin-right:.3rem"></span>Peu utilisée (< 30 %)</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--terracotta);border-radius:2px;margin-right:.3rem"></span>Utilisation normale</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--rouge-alerte);border-radius:2px;margin-right:.3rem"></span>Très sollicitée (> 70 %)</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Dernières utilisations ─────────────────────────────────────────────── -->
  <?php if (!empty($utilisations)): ?>
  <div class="card mb-3">
    <div class="section-titre"><h2>Dernières utilisations</h2></div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Début</th>
            <th>Fin</th>
            <th>Durée</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($utilisations as $u): ?>
          <tr>
            <td><?= htmlspecialchars(date('H:i:s', strtotime($u['debut']))) ?></td>
            <td>
              <?= $u['fin']
                  ? htmlspecialchars(date('H:i:s', strtotime($u['fin'])))
                  : '<span class="badge badge-alerte">En cours</span>' ?>
            </td>
            <td><?= dureeHumaine((int)$u['duree_sec']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Forçage manuel ────────────────────────────────────────────────────── -->
  <div class="card" style="border-left:4px solid var(--ambre)">
    <div class="section-titre">
      <h2 style="font-size:1rem">Correction manuelle</h2>
    </div>
    <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem">
      Forcer le statut si le capteur n'est pas branché ou si la valeur est incorrecte.
    </p>
    <form method="post" action="dashboard.php" style="display:flex;gap:.75rem;flex-wrap:wrap">
      <?= champCSRF() ?>
      <input type="hidden" name="action" value="forcer">
      <button type="submit" name="statut" value="LIBRE"
              class="btn btn-succes btn-sm">Forcer LIBRE</button>
      <button type="submit" name="statut" value="OCCUPEE"
              class="btn btn-danger btn-sm">Forcer OCCUPÉE</button>
    </form>
  </div>

</main>

<footer>
  Projet IoT ISEP — Groupe G9E &nbsp;·&nbsp; Salle de sport connectée
</footer>

<script>
// ── Rafraîchissement silencieux du statut toutes les 2 s ─────────────────────
(function () {
  function duree(sec) {
    sec = Math.max(0, sec);
    if (sec < 60)   return sec + ' s';
    if (sec < 3600) return Math.floor(sec/60) + ' min ' + (sec%60) + ' s';
    return Math.floor(sec/3600) + ' h ' + Math.floor((sec%3600)/60) + ' min';
  }

  async function refresh() {
    try {
      const r = await fetch('dashboard.php?format=json', { cache: 'no-store' });
      if (!r.ok) return;
      const d = await r.json();

      const occupe = d.statut === 'OCCUPEE';

      // Hero background
      const hero = document.getElementById('js-hero');
      hero.className = 'statut-hero ' + (occupe ? 'occupee' : 'libre');

      // Texte statut
      document.getElementById('js-statut').textContent = occupe ? 'OCCUPÉE' : 'LIBRE';

      // Voyant
      const v = document.getElementById('js-voyant');
      v.className = 'voyant ' + (occupe ? 'occupee' : 'libre');

      // Durée depuis
      const label = occupe ? 'Occupée' : 'Libre';
      document.getElementById('js-depuis').innerHTML =
        label + ' depuis <strong>' + duree(d.secondes_depuis) + '</strong>';

      // Valeur brute
      document.getElementById('js-valeur').textContent =
        'Capteur : ' + d.valeur_brute + ' / 4095  ·  Seuil : <?= SEUIL ?>';

      document.getElementById('js-val-num').textContent = d.valeur_brute;

      // Jauge
      const pct = Math.min(100, Math.round(d.valeur_brute / 4095 * 100));
      const jauge = document.getElementById('js-jauge');
      jauge.style.width  = pct + '%';
      jauge.style.background = occupe ? 'var(--rouge-alerte)' : 'var(--vert-ok)';

    } catch (_) {}
  }

  setInterval(refresh, 2000);
})();
</script>

</body>
</html>
