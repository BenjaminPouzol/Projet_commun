<?php
/**
 * Gestion des sessions et de l'authentification
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function estConnecte(): bool
{
    return !empty($_SESSION['utilisateur_id']);
}

function exigerConnexion(): void
{
    if (!estConnecte()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: connexion.php?redirect=' . $redirect);
        exit;
    }
}

function exigerAdmin(): void
{
    exigerConnexion();
    if (($_SESSION['utilisateur_role'] ?? '') !== 'admin') {
        header('Location: tableau_de_bord.php');
        exit;
    }
}

/** Génère (ou récupère) le token CSRF de la session courante */
function genererTokenCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Vérifie le token CSRF avec une comparaison à temps constant */
function verifierTokenCSRF(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Champ caché CSRF à insérer dans chaque formulaire */
function champCSRF(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(genererTokenCSRF(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Connecte un utilisateur en session */
function connecterUtilisateur(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['utilisateur_id']   = $u['id'];
    $_SESSION['utilisateur_nom']  = $u['nom'];
    $_SESSION['utilisateur_role'] = $u['role'];
}

/** Déconnecte l'utilisateur */
function deconnecterUtilisateur(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
