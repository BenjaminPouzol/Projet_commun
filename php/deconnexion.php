<?php
require_once __DIR__ . '/../includes/auth.php';
deconnecterUtilisateur();
header('Location: connexion.php?msg=deconnecte');
exit;
