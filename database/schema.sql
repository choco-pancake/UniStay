CREATE DATABASE IF NOT EXISTS unistay CHARACTER SET utf8mb4;
USE unistay;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- Account details, filled by the login/registration flow
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  phone_number VARCHAR(30) NULL,
  facebook_link VARCHAR(255) NULL,
  personal_email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  -- Extra details, collected by tenant/complete-profile.php after first login
  date_of_birth DATE NULL,
  gender VARCHAR(20) NULL,
  school VARCHAR(150) NULL,
  emergency_name VARCHAR(150) NULL,
  emergency_relationship VARCHAR(50) NULL,
  emergency_phone VARCHAR(30) NULL,
  profile_photo VARCHAR(255) NULL,
  school_id_file VARCHAR(255) NULL,
  gov_id_file VARCHAR(255) NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  role ENUM('tenant','landlord') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  photo VARCHAR(255) NULL
);

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
INSERT INTO users (id, first_name, last_name, role) VALUES (1, 'Juan', 'Dela Cruz', 'landlord');

-- Newsletter subscribers (api/subscribe.php also creates this table on demand)
CREATE TABLE IF NOT EXISTS subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);