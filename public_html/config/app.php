<?php
session_start();

function base_url() {
  // Auto-detect domain; adjust if needed
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'];
  return $scheme . '://' . $host;
}

function is_logged_in() {
  return isset($_SESSION['user']);
}

function current_user() {
  return $_SESSION['user'] ?? null;
}

function require_login() {
  if (!is_logged_in()) {
    header('Location: ' . base_url() . '/auth/login.php');
    exit;
  }
}

function require_admin() {
  if (!is_logged_in() || !is_admin($_SESSION['user'])) {
    header('Location: ' . base_url() . '/auth/login.php');
    exit;
  }
}

/**
 * Check if the given user is an admin.
 * Expects $_SESSION['user']['role'] to be set (e.g. 'admin' or 'user').
 */
function is_admin($user) {
  return isset($user['role']) && strtolower($user['role']) === 'admin';
}

function ensure_wallet(PDO $pdo, int $user_id) {
  $stmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id=?");
  $stmt->execute([$user_id]);
  if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO wallets (user_id) VALUES (?)")->execute([$user_id]);
  }
}

function get_settings(PDO $pdo) {
  $s = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1")->fetch();
  if (!$s) {
    $pdo->exec("INSERT INTO settings (admin_email) VALUES ('zagdebate@gmail.com')");
    $s = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1")->fetch();
  }
  return $s;
}
