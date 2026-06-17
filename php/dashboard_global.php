<?php
/**
 * Dashboard global — Tous les capteurs de la salle de sport
 *
 * Affiche :
 *  – Statut machine (capteur de proximité)
 *  – Température, Humidité, CO2, Luminosité, Son
 *  – Alertes actives
 *  – Historique compact des dernières lectures
 *
 * Rafraîchissement silencieux toutes les 5 s via fetch API → /api_sensors.php/current
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';

exigerConnexion();

$db = getDB();

// ── Acquittement d'alerte ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acquitter') {
    if (verifierTokenCSRF($_POST['csrf_token'] ?? '')) {
        $id = (int)($_POST['alerte_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE sensor_alerts SET acquittee = 1 WHERE id = ?")->execute([$id]);
        }
    }
    header('Location: dashboard_global.php');
    exit;
}

// ── Données courantes ─────────────────────────────────────────────────────────
$machine = $db->prepare("
    SELECT statut, valeur_brute,
           TIMESTAMPDIFF(SECOND, depuis, NOW()) AS secondes_depuis,
           last_update
    FROM machine_status WHERE machine_id = ?
");
$machine->execute([MACHINE_ID]);
$machineData = $machine->fetch() ?: ['statut' => 'LIBRE', 'valeur_brute' => 0, 'secondes_depuis' => 0, 'last_update' => null];

$stmt = $db->prepare("
    SELECT sensor_type, valeur, unite, team_id, last_update
    FROM sensor_current WHERE machine_id = ?
");
$stmt->execute([MACHINE_ID]);
$current = [];
foreach ($stmt->fetchAll() as $r) {
    $current[$r['sensor_type']] = $r;
}

// ── Alertes actives ───────────────────────────────────────────────────────────
$alertes = $db->prepare("
    SELECT id, sensor_type, valeur, seuil, direction, message, timestamp
    FROM sensor_alerts
    WHERE acquittee = 0
    ORDER BY timestamp DESC
    LIMIT 20
");
$alertes->execute();
$alertesActives = $alertes->fetchAll();

// ── Historique compact des 30 dernières lectures ──────────────────────────────
$historique = $db->prepare("
    SELECT sensor_type, valeur, unite, timestamp
    FROM sensor_readings
    WHERE machine_id = ?
    ORDER BY timestamp DESC
    LIMIT 30
");
$historique->execute([MACHINE_ID]);
$derniersLog = $historique->fetchAll();

// ── Sparklines : 20 dernières lectures par capteur (pour mini-graphes) ────────
$spark = [];
foreach (['TEMPERATURE', 'HUMIDITE', 'CO2', 'LUX', 'SON'] as $t) {
    $s = $db->prepare("
        SELECT valeur FROM sensor_readings
        WHERE machine_id = ? AND sensor_type = ?
        ORDER BY timestamp DESC LIMIT 20
    ");
    $s->execute([MACHINE_ID, $t]);
    $spark[$t] = array_reverse(array_column($s->fetchAll(), 'valeur'));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function dureeHumaine(int $sec): string
{
    if ($sec < 60)   return "{$sec} s";
    if ($sec < 3600) return floor($sec / 60) . ' min ' . ($sec % 60) . ' s';
    return floor($sec / 3600) . ' h ' . floor(($sec % 3600) / 60) . ' min';
}

function etatLabel(string $type, ?float $valeur): array
{
    if ($valeur === null) return ['—', 'badge-info', ''];

    $seuils = [
        'TEMPERATURE' => ['min' => 15.0, 'max' => 30.0],
        'HUMIDITE'    => ['min' => 30.0, 'max' => 75.0],
        'CO2'         => ['min' => null, 'max' => 1000.0],
        'LUX'         => ['min' => 100.0, 'max' => null],
        'SON'         => ['min' => null, 'max' => 90.0],
    ];

    $s = $seuils[$type] ?? null;
    if (!$s) return ['Info', 'badge-info', ''];

    if (($s['min'] !== null && $valeur < $s['min']) || ($s['max'] !== null && $valeur > $s['max'])) {
        return ['ALERTE', 'badge-alerte', 'border-color:var(--rouge-alerte)'];
    }
    if ($s['min'] !== null && $valeur < $s['min'] * 1.12) {
        return ['Vigilance', 'badge-ambre', 'border-color:var(--ambre)'];
    }
    if ($s['max'] !== null && $valeur > $s['max'] * 0.88) {
        return ['Vigilance', 'badge-ambre', 'border-color:var(--ambre)'];
    }
    return ['OK', 'badge-ok', ''];
}

function sparkSVG(array $vals, string $color = '#D96C4A'): string
{
    if (count($vals) < 2) return '<span style="color:#ccc;font-size:.7rem">pas de données</span>';
    $min = min($vals); $max = max($vals);
    $range = max($max - $min, 0.001);
    $w = 120; $h = 32;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i / (count($vals) - 1) * $w, 1);
        $y = round($h - (($v - $min) / $range * ($h - 4)) - 2, 1);
        $pts[] = "$x,$y";
    }
    $poly = implode(' ', $pts);
    return "<svg viewBox=\"0 0 $w $h\" width=\"$w\" height=\"$h\" aria-hidden=\"true\">"
         . "<polyline points=\"$poly\" fill=\"none\" stroke=\"$color\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/>"
         . "</svg>";
}

$estOccupee = $machineData['statut'] === 'OCCUPEE';
$nbAlertes  = count($alertesActives);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard capteurs — Salle de sport IoT</title>
  <link rel="stylesheet" href="../public/assets/css/style.css">
  <style>
    /* ── Grille capteurs ───────────────────────────────────────── */
    .grid-capteurs {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }

    .capteur-card {
      background: var(--blanc-casse);
      border-radius: var(--radius-lg);
      box-shadow: var(--ombre);
      padding: 1.4rem 1.5rem 1.2rem;
      border: 2px solid transparent;
      transition: border-color .2s, box-shadow .2s;
      position: relative;
    }
    .capteur-card.alerte   { border-color: var(--rouge-alerte); }
    .capteur-card.vigilance { border-color: var(--ambre); }

    .capteur-entete {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: .75rem;
    }
    .capteur-nom {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .09em;
      color: var(--terracotta);
      font-weight: 700;
    }
    .capteur-equipe {
      font-size: .68rem;
      color: #aab;
      font-weight: 600;
    }

    .capteur-valeur {
      font-size: 2.4rem;
      font-weight: 800;
      line-height: 1;
      color: var(--bleu-nuit);
    }
    .capteur-unite {
      font-size: 1rem;
      font-weight: 400;
      color: #8a9aab;
      margin-left: .25rem;
    }

    .capteur-bas {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: .75rem; gap: .5rem;
    }
    .capteur-maj { font-size: .68rem; color: #aab; }

    /* ── Carte machine (occupancy) ─────────────────────────────── */
    .machine-card {
      grid-column: span 2;
      background: var(--blanc-casse);
      border-radius: var(--radius-lg);
      box-shadow: var(--ombre);
      padding: 1.5rem;
      border: 2px solid transparent;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
    }
    @media (max-width: 680px) { .machine-card { grid-column: span 1; } }

    .machine-statut-badge {
      display: inline-flex; align-items: center; gap: .5rem;
      padding: .6rem 1.2rem;
      border-radius: 999px;
      font-size: 1.1rem;
      font-weight: 800;
      letter-spacing: .04em;
    }
    .machine-statut-badge.libre   { background: rgba(94,140,106,.18); color: var(--vert-ok); }
    .machine-statut-badge.occupee { background: rgba(192,71,59,.15);  color: var(--rouge-alerte); }

    /* ── Alertes ───────────────────────────────────────────────── */
    .alerte-row { animation: fadeIn .3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

    /* ── Barre d'alerte globale ────────────────────────────────── */
    .bandeau-alertes {
      background: rgba(192,71,59,.1);
      border: 1.5px solid var(--rouge-alerte);
      border-radius: var(--radius);
      padding: .75rem 1.25rem;
      display: flex;
      align-items: center;
      gap: .75rem;
      margin-bottom: 1.5rem;
      color: var(--rouge-alerte);
      font-weight: 600;
      font-size: .9rem;
    }

    /* ── Indicateur voyant ─────────────────────────────────────── */
    .voyant { display:inline-block; width:12px; height:12px; border-radius:50%; }
    .voyant.libre   { background:#4ade80; box-shadow:0 0 0 3px rgba(74,222,128,.3); }
    .voyant.occupee {
      background:#f87171;
      box-shadow:0 0 0 3px rgba(248,113,113,.3);
      animation:pulse 1.2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%,100% { box-shadow:0 0 0 3px rgba(248,113,113,.3); }
      50%      { box-shadow:0 0 0 6px rgba(248,113,113,.1); }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main>

  <!-- ── Bandeau alertes actives ───────────────────────────────────────────── -->
  <?php if ($nbAlertes > 0): ?>
  <div class="bandeau-alertes" id="js-bandeau-alertes" role="alert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
      <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <span id="js-alerte-count"><?= $nbAlertes ?></span> alerte<?= $nbAlertes > 1 ? 's' : '' ?> active<?= $nbAlertes > 1 ? 's' : '' ?> —
    <a href="#section-alertes" style="color:var(--rouge-alerte);font-weight:700">Voir ci-dessous</a>
  </div>
  <?php endif; ?>

  <!-- ── Grille capteurs ───────────────────────────────────────────────────── -->
  <div class="grid-capteurs" id="js-grid">

    <!-- Machine (occupancy) -->
    <div class="machine-card" id="js-machine-card">
      <div>
        <div class="capteur-nom" style="margin-bottom:.5rem">Machine — Capteur de proximité</div>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
          <span class="machine-statut-badge <?= $estOccupee ? 'occupee' : 'libre' ?>" id="js-machine-badge">
            <span class="voyant <?= $estOccupee ? 'occupee' : 'libre' ?>" id="js-machine-voyant"></span>
            <span id="js-machine-statut"><?= $estOccupee ? 'OCCUPÉE' : 'LIBRE' ?></span>
          </span>
          <span class="text-muted" style="font-size:.82rem" id="js-machine-depuis">
            <?= $estOccupee ? 'Occupée' : 'Libre' ?> depuis
            <strong><?= dureeHumaine((int)$machineData['secondes_depuis']) ?></strong>
          </span>
        </div>
        <div style="font-size:.72rem;color:#aab;margin-top:.4rem">
          Capteur ADC : <span id="js-machine-adc"><?= (int)$machineData['valeur_brute'] ?></span> / 4095
          &nbsp;·&nbsp; Seuil : <?= SEUIL ?> &nbsp;·&nbsp; Équipe G9E
        </div>
      </div>
      <a href="dashboard.php" class="btn btn-secondaire btn-sm" style="margin-left:auto;white-space:nowrap">
        Détails →
      </a>
    </div>

    <?php
    $capteursMeta = [
        'TEMPERATURE' => [
            'label'   => 'Température',
            'equipe'  => 'G9B',
            'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>',
            'color'   => '#D96C4A',
            'seuils'  => 'Confort : 15–30 °C',
        ],
        'HUMIDITE' => [
            'label'   => 'Humidité',
            'equipe'  => 'G9B',
            'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>',
            'color'   => '#7C9A82',
            'seuils'  => 'Confort : 30–75 %',
        ],
        'CO2' => [
            'label'   => 'CO₂',
            'equipe'  => 'G9D',
            'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
            'color'   => '#E8A33D',
            'seuils'  => 'Alerte > 1000 ppm',
        ],
        'LUX' => [
            'label'   => 'Luminosité',
            'equipe'  => 'G9C',
            'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
            'color'   => '#E8A33D',
            'seuils'  => 'Min 100 lux',
        ],
        'SON' => [
            'label'   => 'Niveau sonore',
            'equipe'  => 'G9A',
            'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>',
            'color'   => '#7C9A82',
            'seuils'  => 'Alerte > 90 dB',
        ],
    ];

    foreach ($capteursMeta as $type => $meta):
        $row       = $current[$type] ?? null;
        $valeur    = $row ? (float)$row['valeur'] : null;
        $unite     = $row ? $row['unite']        : '—';
        $majDate   = $row ? date('H:i:s', strtotime($row['last_update'])) : '—';
        [$etatTxt, $badgeCls, $cardBorder] = etatLabel($type, $valeur);
        $sparkData = $spark[$type] ?? [];
        $cardClass = ($badgeCls === 'badge-alerte') ? 'alerte' : (($badgeCls === 'badge-ambre') ? 'vigilance' : '');
    ?>
    <div class="capteur-card <?= $cardClass ?>" id="js-card-<?= $type ?>" style="<?= $cardBorder ?>">
      <div class="capteur-entete">
        <span class="capteur-nom"><?= $meta['label'] ?></span>
        <span class="capteur-equipe">Éq. <?= $meta['equipe'] ?></span>
      </div>

      <div style="display:flex;align-items:baseline;gap:.2rem">
        <span class="capteur-valeur" id="js-val-<?= $type ?>">
          <?= $valeur !== null ? number_format($valeur, 1, ',', ' ') : '—' ?>
        </span>
        <span class="capteur-unite"><?= htmlspecialchars($unite) ?></span>
      </div>

      <div class="capteur-bas">
        <span class="badge <?= $badgeCls ?>" id="js-etat-<?= $type ?>"><?= $etatTxt ?></span>
        <span id="js-spark-<?= $type ?>"><?= sparkSVG($sparkData, $meta['color']) ?></span>
      </div>

      <div style="display:flex;justify-content:space-between;margin-top:.5rem">
        <span class="capteur-maj" id="js-maj-<?= $type ?>">
          <?= $majDate !== '—' ? "Mis à jour : $majDate" : 'Aucune donnée' ?>
        </span>
        <span style="font-size:.68rem;color:#bbb"><?= $meta['seuils'] ?></span>
      </div>
    </div>
    <?php endforeach; ?>

  </div>

  <!-- ── Section alertes actives ───────────────────────────────────────────── -->
  <div id="section-alertes" class="card mb-3" style="border-left:4px solid var(--rouge-alerte)">
    <div class="section-titre">
      <h2 style="font-size:1rem">
        Alertes actives
        <?php if ($nbAlertes > 0): ?>
          <span class="badge badge-alerte" style="margin-left:.5rem"><?= $nbAlertes ?></span>
        <?php endif; ?>
      </h2>
    </div>

    <?php if (empty($alertesActives)): ?>
      <p class="text-muted" style="font-size:.85rem">
        Aucune alerte active. Tous les capteurs sont dans les plages normales.
      </p>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Capteur</th>
            <th>Message</th>
            <th>Valeur</th>
            <th>Seuil</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($alertesActives as $al): ?>
          <tr class="alerte-row">
            <td><span class="badge badge-alerte"><?= htmlspecialchars($al['sensor_type']) ?></span></td>
            <td><?= htmlspecialchars($al['message']) ?></td>
            <td><?= htmlspecialchars((string)$al['valeur']) ?></td>
            <td><?= htmlspecialchars((string)$al['seuil']) ?></td>
            <td><?= htmlspecialchars(date('d/m H:i', strtotime($al['timestamp']))) ?></td>
            <td>
              <form method="post" action="dashboard_global.php">
                <?= champCSRF() ?>
                <input type="hidden" name="action"    value="acquitter">
                <input type="hidden" name="alerte_id" value="<?= (int)$al['id'] ?>">
                <button type="submit" class="btn btn-secondaire btn-sm">Acquitter</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>


</main>

<footer>
  Projet IoT ISEP — Salle de sport connectée &nbsp;·&nbsp;
  Rafraîchissement auto. toutes les 5 s &nbsp;·&nbsp;
  <a href="api_sensors.php/current" target="_blank" rel="noopener">API JSON</a>
</footer>

<script>
// ── Rafraîchissement silencieux toutes les 5 s ────────────────────────────────
(function () {
  const SEUILS = {
    TEMPERATURE: { min: 15, max: 30 },
    HUMIDITE:    { min: 30, max: 75 },
    CO2:         { min: null, max: 1000 },
    LUX:         { min: 100, max: null },
    SON:         { min: null, max: 90 },
  };

  function etat(type, val) {
    const s = SEUILS[type];
    if (!s) return { txt: 'Info', cls: 'badge-info' };
    if ((s.min !== null && val < s.min) || (s.max !== null && val > s.max))
      return { txt: 'ALERTE', cls: 'badge-alerte' };
    if (s.min !== null && val < s.min * 1.12) return { txt: 'Vigilance', cls: 'badge-ambre' };
    if (s.max !== null && val > s.max * 0.88) return { txt: 'Vigilance', cls: 'badge-ambre' };
    return { txt: 'OK', cls: 'badge-ok' };
  }

  function duree(sec) {
    sec = Math.max(0, sec);
    if (sec < 60)   return sec + ' s';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s';
    return Math.floor(sec / 3600) + ' h ' + Math.floor((sec % 3600) / 60) + ' min';
  }

  function el(id) { return document.getElementById(id); }

  async function refresh() {
    try {
      const r = await fetch('api_sensors.php/current', { cache: 'no-store' });
      if (!r.ok) return;
      const d = await r.json();

      // Machine
      if (d.machine) {
        const occ = d.machine.statut === 'OCCUPEE';
        const badge = el('js-machine-badge');
        const voyant = el('js-machine-voyant');
        const statut = el('js-machine-statut');
        const depuis = el('js-machine-depuis');
        const adc    = el('js-machine-adc');

        if (badge) badge.className = 'machine-statut-badge ' + (occ ? 'occupee' : 'libre');
        if (voyant) voyant.className = 'voyant ' + (occ ? 'occupee' : 'libre');
        if (statut) statut.textContent = occ ? 'OCCUPÉE' : 'LIBRE';
        if (depuis) depuis.innerHTML = (occ ? 'Occupée' : 'Libre') +
          ' depuis <strong>' + duree(d.machine.secondes_depuis) + '</strong>';
        if (adc) adc.textContent = d.machine.valeur_brute;
      }

      // Capteurs
      if (d.capteurs) {
        Object.entries(d.capteurs).forEach(([type, c]) => {
          const valEl  = el('js-val-'  + type);
          const etatEl = el('js-etat-' + type);
          const majEl  = el('js-maj-'  + type);
          const card   = el('js-card-' + type);

          if (valEl)  valEl.textContent = parseFloat(c.valeur).toLocaleString('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
          if (majEl && c.last_update) {
            const d2 = new Date(c.last_update);
            majEl.textContent = 'Mis à jour : ' + d2.toLocaleTimeString('fr-FR');
          }

          const e = etat(type, c.valeur);
          if (etatEl) { etatEl.textContent = e.txt; etatEl.className = 'badge ' + e.cls; }
          if (card)   {
            card.classList.remove('alerte', 'vigilance');
            if (e.cls === 'badge-alerte') card.classList.add('alerte');
            else if (e.cls === 'badge-ambre') card.classList.add('vigilance');
          }
        });
      }

      // Bandeau alertes
      const bandeau = el('js-bandeau-alertes');
      const cnt     = el('js-alerte-count');
      if (bandeau && cnt && d.nb_alertes !== undefined) {
        bandeau.style.display = d.nb_alertes > 0 ? '' : 'none';
        cnt.textContent = d.nb_alertes;
      }

    } catch (_) {}
  }

  setInterval(refresh, 5000);
})();
</script>
</body>
</html>
