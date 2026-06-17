<?php
/**
 * Navbar commune à toutes les pages.
 * Utilise des URLs absolutes pour fonctionner depuis php/ et public/.
 */
$_np = basename($_SERVER['PHP_SELF'] ?? '');
function _nav(string $page): string { global $_np; return $_np === $page ? ' class="active"' : ''; }
?>
<nav class="navbar">
  <a class="navbar-brand" href="/Projet_commun/php/dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="2" y="3" width="20" height="14" rx="2"/>
      <line x1="8" y1="21" x2="16" y2="21"/>
      <line x1="12" y1="17" x2="12" y2="21"/>
    </svg>
    Salle de sport — IoT ISEP
  </a>

  <ul class="navbar-nav">
    <li><a href="/Projet_commun/php/dashboard.php"<?= _nav('dashboard.php') ?>>Proximité G9E</a></li>
    <li><a href="/Projet_commun/php/dashboard_global.php"<?= _nav('dashboard_global.php') ?>>Vue globale</a></li>
    <li><a href="/Projet_commun/public/actionneurs.php"<?= _nav('actionneurs.php') ?>>Actionneurs</a></li>
    <li><a href="/Projet_commun/php/diagnostic.php"<?= _nav('diagnostic.php') ?>>Diagnostic</a></li>
  </ul>

  <div class="navbar-user">
    <?php if (($_SESSION['utilisateur_role'] ?? '') === 'admin'): ?>
    <a href="/Projet_commun/php/admin_stats.php"
       style="display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;
              padding:.3rem .7rem;border-radius:6px;text-decoration:none;font-weight:600;
              background:var(--ambre);color:#fff;
              <?= $_np === 'admin_stats.php' ? 'outline:2px solid #fff;outline-offset:2px;' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
      </svg>
      Admin
    </a>
    <?php endif; ?>
    <span><?= htmlspecialchars($_SESSION['utilisateur_nom'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    <a href="/Projet_commun/php/deconnexion.php"
       style="color:rgba(255,255,255,.65);font-size:.82rem;padding:.3rem .65rem;
              border:1px solid rgba(255,255,255,.2);border-radius:6px;text-decoration:none">
      Déconnexion
    </a>
  </div>
</nav>
