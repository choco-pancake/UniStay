CREATE DATABASE IF NOT EXISTS dormfinder CHARACTER SET utf8mb4;
USE dormfinder;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  role ENUM('tenant','landlord') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE properties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  landlord_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  university VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  description TEXT,
  photo VARCHAR(255) NOT NULL,          -- file path, e.g. uploads/properties/ab12.jpg
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (landlord_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (university)
);

CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  type ENUM('Single','Double','Quad','Bedspace') NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,
  rent DECIMAL(10,2) NOT NULL,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  occupants TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Available','Occupied','Under Maintenance') NOT NULL DEFAULT 'Available',
  amenities JSON NULL,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Placeholder landlord until login exists (matches landlord_id() in api/db.php)
INSERT INTO users (id, name, role) VALUES (1, 'Juan Dela Cruz', 'landlord');