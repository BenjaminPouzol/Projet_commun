-- ============================================================
-- Données initiales – Salle de sport G9E
-- Usage : mysql -u root -p iot_occupancy < sql/init_data.sql
-- ============================================================

USE iot_occupancy;

-- Initialiser la salle (room_id = 1) à 0 occupant
INSERT INTO occupancy_current (room_id, occupancy_count, team_id)
VALUES (1, 0, 'G9E')
ON DUPLICATE KEY UPDATE
  occupancy_count = 0,
  last_update     = NOW();
