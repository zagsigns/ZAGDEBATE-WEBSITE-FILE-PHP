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

/**
 * Fetch the latest settings row.
 * Uses updated_at to ensure we always get the newest values.
 */
function get_settings(PDO $pdo) {
  $stmt = $pdo->query("SELECT * FROM settings ORDER BY updated_at DESC LIMIT 1");
  $s = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$s) {
    // If no settings row exists, insert a default one
    $pdo->exec("INSERT INTO settings (admin_email) VALUES ('zagdebate@gmail.com')");
    $stmt = $pdo->query("SELECT * FROM settings ORDER BY updated_at DESC LIMIT 1");
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  return $s;
}
