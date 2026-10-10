CREATE DATABASE IF NOT EXISTS unistay CHARACTER SET utf8mb4;
USE unistay;

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
  photo TEXT NOT NULL,                  -- JSON array of file paths, e.g. ["uploads/properties/ab12.jpg", ...]
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
  type ENUM('SINGLE','TWIN','QUAD','QUINTUPLE','SEXTUPLE','OCTUPLE','DECUPLE') NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,   -- derived from type (SINGLE=1, TWIN=2, QUAD=4, QUINTUPLE=5, SEXTUPLE=6, OCTUPLE=8, DECUPLE=10)
  rent DECIMAL(10,2) NOT NULL,
  rent_max DECIMAL(10,2) NULL,                -- NULL = fixed price; set = price range (rent .. rent_max)
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_max DECIMAL(10,2) NULL,             -- NULL = fixed price; set = price range (deposit .. deposit_max)
  occupants TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Available','Occupied','Under Maintenance') NOT NULL DEFAULT 'Available',
  amenities JSON NULL,
  photo TEXT NULL,                      -- JSON array of up to 3 file paths, e.g. ["uploads/rooms/ab12.jpg", ...]
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Existing databases: widen rooms.photo so it can hold a JSON array of room photos
ALTER TABLE rooms MODIFY photo TEXT NULL;

-- Placeholder landlord until login exists (matches landlord_id() in api/db.php)
INSERT INTO users (id, name, role) VALUES (1, 'Juan Dela Cruz', 'landlord');