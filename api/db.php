<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

const DB_HOST = 'localhost', DB_NAME = 'unistay', DB_USER = 'root', DB_PASS = '';
const AMENITIES = ['WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen'];
const STATUSES  = ['Available', 'Occupied', 'Under Maintenance'];
const TYPES     = ['Single', 'Double', 'Quad', 'Bedspace'];

function db() {
  static $pdo;
  return $pdo ??= new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok($data = ['ok' => true]) { echo json_encode($data); exit; }
function body() { return json_decode(file_get_contents('php://input'), true) ?? []; }
// TODO: replace with your real login. Until then everything runs as seeded landlord #1.
function landlord_id() { return (int)($_SESSION['user_id'] ?? 1); }

// Validates + normalizes one room (calls fail() on bad input)
function clean_room($r) {
  $name = trim($r['name'] ?? ''); $cap = (int)($r['capacity'] ?? 0);
  $rent = (float)($r['rent'] ?? 0); $occ = (int)($r['occupants'] ?? 0);
  if ($name === '' || $cap < 1 || $rent <= 0) fail('Each room needs a name, rent, and capacity.');
  if ($occ < 0 || $occ > $cap) fail('Current occupants cannot exceed max occupants.');
  return [
    'name' => $name,
    'type' => in_array($r['type'] ?? '', TYPES, true) ? $r['type'] : 'Single',
    'capacity' => $cap, 'rent' => $rent, 'deposit' => max(0, (float)($r['deposit'] ?? 0)), 'occupants' => $occ,
    'status' => in_array($r['status'] ?? '', STATUSES, true) ? $r['status'] : 'Available',
    'amenities' => json_encode(array_values(array_intersect(AMENITIES, (array)($r['amenities'] ?? [])))),
  ];
}
function insert_room($pid, $r) {
  db()->prepare('INSERT INTO rooms (property_id,name,type,capacity,rent,deposit,occupants,status,amenities) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$pid, $r['name'], $r['type'], $r['capacity'], $r['rent'], $r['deposit'], $r['occupants'], $r['status'], $r['amenities']]);
  return (int)db()->lastInsertId();
}