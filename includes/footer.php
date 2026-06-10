<?php
/**
 * Pied de page HTML commun à toutes les pages
 */
?>
</main><!-- /main -->

<footer>
  <p>
    Projet IoT ISEP — Salle de sport connectée &mdash; Groupe G9 &mdash;
    <a href="index.php">Accueil</a>
    <?php if (estConnecte()): ?>
     &mdash; <a href="tableau_de_bord.php">Tableau de bord</a>
     &mdash; <a href="analyse.php">Analyse</a>
     &mdash; <a href="actionneurs.php">Actionneurs</a>
    <?php endif; ?>
  </p>
</footer>

</body>
</html>
