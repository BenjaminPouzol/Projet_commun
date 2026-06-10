<?php
/**
 * Connexion PDO centralisée — Projet IoT ISEP
 * Modifiez uniquement les constantes ci-dessous.
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'iot_salle_sport');
define('DB_USER',    'root');
define('DB_PASS',    '');          // XAMPP : vide par défaut
define('DB_CHARSET', 'utf8mb4');

/**
 * Retourne une instance PDO partagée (singleton).
 * Lance une exception en cas d'échec de connexion.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Erreur BDD : ' . $e->getMessage());
            http_response_code(503);
            die('<p style="font-family:sans-serif;color:#C0473B;padding:2rem">
                Impossible de se connecter à la base de données.<br>
                Vérifiez que XAMPP est démarré et que la base <strong>' . DB_NAME . '</strong> existe.
                </p>');
        }
    }

    return $pdo;
}
