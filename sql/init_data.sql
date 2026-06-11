-- ============================================================
-- Données initiales — Salle de sport G9E
-- Usage : mysql -u root < sql/init_data.sql
--         (ou lancer depuis phpMyAdmin)
-- ============================================================

USE iot_machine;

-- ── Statut initial de la machine ──────────────────────────────────────────────
INSERT INTO machine_status (machine_id, statut, team_id)
VALUES (1, 'LIBRE', 'G9E')
ON DUPLICATE KEY UPDATE
  statut      = 'LIBRE',
  last_update = NOW();

-- ── Actionneurs initiaux ──────────────────────────────────────────────────────
INSERT IGNORE INTO actionneurs (id, nom, type, groupe, equipe, etat) VALUES
  (1, 'LED Machine 1',       'led',    'G9C', 'Équipe G9C', 'off'),
  (2, 'LED Machine 2',       'led',    'G9C', 'Équipe G9C', 'off'),
  (3, 'Écran OLED Principal','oled',   'G9C', 'Équipe G9C', 'off'),
  (4, 'Buzzer Alarme',       'buzzer', 'G9C', 'Équipe G9C', 'off'),
  (5, 'Moteur Ventilation',  'moteur', 'G9B', 'Équipe G9B', 'off');

-- ── Comptes de test ───────────────────────────────────────────────────────────
-- Mots de passe en clair (NE PAS partager en dehors de l'équipe) :
--   admin@isep.fr   →  admin1234
--   prof@isep.fr    →  isep2024
--   g9e@isep.fr     →  g9e2024

INSERT IGNORE INTO utilisateurs (nom, email, mot_de_passe, role) VALUES
  ('Admin G9E',
   'admin@isep.fr',
   '$2y$10$s4fc.Win.pxVEZJOWccdv.3K3rMlqTyOhUZF1/z3YY7G0LNFP7a4e',
   'admin'),

  ('Professeur ISEP',
   'prof@isep.fr',
   '$2y$10$fLcmXulpyTdPdUbOJNjPOuWsDIvhmUBHK3F33g4kXdUHb/lLJlYvS',
   'admin'),

  ('Utilisateur G9E',
   'g9e@isep.fr',
   '$2y$10$8bvhVjui4SuDFtK3i2HmJO7LFxpfY0M1lpXvBkY98JDKrzU/VS9re',
   'utilisateur');
