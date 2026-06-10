-- =========================================================
-- Base de données : iot_salle_sport
-- Projet IoT ISEP — Salle de sport connectée (Groupe G9)
-- Encodage : utf8mb4
-- =========================================================

CREATE DATABASE IF NOT EXISTS `iot_salle_sport`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `iot_salle_sport`;

-- ---------------------------------------------------------
-- Utilisateurs du site web
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilisateurs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nom          VARCHAR(80)  NOT NULL,
  email        VARCHAR(120) UNIQUE NOT NULL,
  mot_de_passe VARCHAR(255) NOT NULL,                   -- password_hash() uniquement
  role         ENUM('admin','utilisateur') DEFAULT 'utilisateur',
  cree_le      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Capteurs — schéma générique, tous types acceptés
-- type : son | temperature | humidite | proximite | gaz | lumiere | couleur …
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS capteurs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nom         VARCHAR(80)  NOT NULL,
  type        VARCHAR(50)  NOT NULL,
  unite       VARCHAR(20),                              -- dB, °C, %, ppm, cm, lux …
  groupe      VARCHAR(50),                              -- ex. G9
  equipe      VARCHAR(50),                              -- ex. G9A … G9C
  emplacement VARCHAR(120),
  actif       TINYINT(1) DEFAULT 1,
  INDEX idx_groupe_type (groupe, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Mesures brutes (toutes équipes, tous groupes)
-- Indexé sur (capteur_id, horodatage) pour les requêtes temporelles
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS mesures (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  capteur_id  INT NOT NULL,
  valeur      DECIMAL(10,2) NOT NULL,
  horodatage  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (capteur_id) REFERENCES capteurs(id) ON DELETE CASCADE,
  INDEX idx_capteur_temps (capteur_id, horodatage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Actionneurs (LED, OLED, buzzer, moteur, 7 segments …)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS actionneurs (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nom    VARCHAR(80) NOT NULL,
  type   VARCHAR(50) NOT NULL,                         -- led | oled | buzzer | moteur | 7segments
  groupe VARCHAR(50),
  equipe VARCHAR(50),
  etat   VARCHAR(50) DEFAULT 'off'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Commandes vers les actionneurs
-- Le site insère ici ; l'équipe G9C relaie vers sa carte TIVA
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS commandes_actionneurs (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  actionneur_id INT NOT NULL,
  commande      VARCHAR(100) NOT NULL,
  envoye        TINYINT(1) DEFAULT 0,
  horodatage    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actionneur_id) REFERENCES actionneurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Alertes sur seuils (e-mail si valeur hors plage)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  capteur_id INT NOT NULL,
  seuil_min  DECIMAL(10,2),
  seuil_max  DECIMAL(10,2),
  email      VARCHAR(120),
  FOREIGN KEY (capteur_id) REFERENCES capteurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
