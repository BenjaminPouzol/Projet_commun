-- ============================================================
-- Surveillance machine par capteur de proximité – Schéma BDD
-- Usage : mysql -u root -p < sql/setup.sql
-- (config.php le crée aussi automatiquement au premier accès)
-- ============================================================

CREATE DATABASE IF NOT EXISTS iot_machine
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iot_machine;

CREATE TABLE IF NOT EXISTS utilisateurs (
    id           INT          NOT NULL AUTO_INCREMENT,
    nom          VARCHAR(80)  NOT NULL,
    email        VARCHAR(120) NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role         ENUM('admin','utilisateur') NOT NULL DEFAULT 'utilisateur',
    cree_le      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB;

-- Statut courant de la machine (une ligne)
CREATE TABLE IF NOT EXISTS machine_status (
    machine_id   INT                    NOT NULL,
    statut       ENUM('LIBRE','OCCUPEE') NOT NULL DEFAULT 'LIBRE',
    valeur_brute INT                    NOT NULL DEFAULT 0,
    depuis       TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_update  TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    team_id      VARCHAR(20)            NOT NULL DEFAULT 'G9E',
    PRIMARY KEY (machine_id)
) ENGINE=InnoDB;

-- Historique de tous les changements de statut
CREATE TABLE IF NOT EXISTS machine_log (
    id           BIGINT                 NOT NULL AUTO_INCREMENT,
    machine_id   INT                    NOT NULL,
    statut       ENUM('LIBRE','OCCUPEE') NOT NULL,
    valeur_brute INT                    NOT NULL DEFAULT 0,
    timestamp    TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_machine_time (machine_id, timestamp)
) ENGINE=InnoDB;

INSERT IGNORE INTO machine_status (machine_id, statut, team_id)
VALUES (1, 'LIBRE', 'G9E');
