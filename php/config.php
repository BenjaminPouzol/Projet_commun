<?php
// Connexion PDO – crée la base et les tables automatiquement au premier accès

define('DB_HOST',   'localhost');
define('DB_NAME',   'iot_machine');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('MACHINE_ID', 1);
define('SEUIL',      500);   // Valeur ADC : >= SEUIL → machine OCCUPÉE

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $boot = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $boot->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $boot->exec("USE `" . DB_NAME . "`");

    // ── Utilisateurs ─────────────────────────────────────────────────────────
    $boot->exec("
        CREATE TABLE IF NOT EXISTS utilisateurs (
            id           INT          NOT NULL AUTO_INCREMENT,
            nom          VARCHAR(80)  NOT NULL,
            email        VARCHAR(120) NOT NULL,
            mot_de_passe VARCHAR(255) NOT NULL,
            role         ENUM('admin','utilisateur') NOT NULL DEFAULT 'utilisateur',
            cree_le      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_email (email)
        ) ENGINE=InnoDB
    ");

    // ── Statut actuel de la machine ───────────────────────────────────────────
    // 'depuis' = quand le statut actuel a commencé
    $boot->exec("
        CREATE TABLE IF NOT EXISTS machine_status (
            machine_id   INT                    NOT NULL,
            statut       ENUM('LIBRE','OCCUPEE') NOT NULL DEFAULT 'LIBRE',
            valeur_brute INT                    NOT NULL DEFAULT 0,
            depuis       TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_update  TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP
                                               ON UPDATE CURRENT_TIMESTAMP,
            team_id      VARCHAR(20)            NOT NULL DEFAULT 'G9E',
            PRIMARY KEY (machine_id)
        ) ENGINE=InnoDB
    ");

    // ── Historique des changements de statut ──────────────────────────────────
    $boot->exec("
        CREATE TABLE IF NOT EXISTS machine_log (
            id           BIGINT                 NOT NULL AUTO_INCREMENT,
            machine_id   INT                    NOT NULL,
            statut       ENUM('LIBRE','OCCUPEE') NOT NULL,
            valeur_brute INT                    NOT NULL DEFAULT 0,
            timestamp    TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_machine_time (machine_id, timestamp)
        ) ENGINE=InnoDB
    ");

    // Ligne initiale : machine LIBRE
    $boot->exec("INSERT IGNORE INTO machine_status (machine_id, statut, team_id)
                 VALUES (1, 'LIBRE', 'G9E')");

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    return $pdo;
}
