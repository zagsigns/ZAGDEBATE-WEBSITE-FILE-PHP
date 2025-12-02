<?php
require_once __DIR__ . '/../config/db.php';

$email = 'zagdebate@gmail.com';
$password = 'Zagdebate@2025';

$stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin' LIMIT 1");
$stmt->execute([$email]);
$admin = $stmt->fetch();

echo "<pre>";
if (!$admin) {
  echo "❌ No admin found with that email.\n";
} else {
  echo "✅ Admin found.\n";
  echo "Email: " . $admin['email'] . "\n";
  echo "Role: " . $admin['role'] . "\n";
  echo "Verified: " . $admin['is_verified'] . "\n";
  echo "Password match: " . (password_verify($password, $admin['password_hash']) ? '✅ YES' : '❌ NO') . "\n";
}
echo "</pre>";
