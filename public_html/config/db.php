<?php
$DB_HOST = 'localhost';
$DB_NAME = 'u326600163_zagdebate_db';
$DB_USER = 'u326600163_zagdebate';
$DB_PASS = 'Zagdebate@2025';

try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Exception $e) {
  die('Database connection error.');
}
