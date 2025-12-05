<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ZAG DEBATE</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Site Icon -->
  <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
  <link rel="icon" href="/favicon.ico" type="image/x-icon">

  <!-- Styles -->
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="header">
  <div class="navbar container">
    <!-- Brand / Logo -->
    <a class="brand" href="/">
      <img src="/logo.png" alt="ZAG" class="logo-img">
    </a>

    <!-- Toggle button (mobile) -->
    <button
      class="toggle btn-outline"
      aria-label="Open menu"
      onclick="document.getElementById('mobileMenu').classList.toggle('open')"
    >
      ☰
    </button>

    <!-- Desktop menu -->
    <nav class="menu">
      <a href="/debates/list.php" class="btn">Debates</a>
      <a href="/user/dashboard.php" class="btn">Dashboard</a>

      <?php if (!empty($_SESSION['user'])): ?>
        <?php if ($_SESSION['user']['role'] === 'admin'): ?>
          <a href="/admin/dashboard.php" class="btn">Admin Dashboard</a>
        <?php endif; ?>
        <a href="/auth/logout.php" class="btn" onclick="return confirm('Are you sure you want to logout?');">Logout</a>
      <?php else: ?>
        <a href="/auth/register.php" class="btn">Register</a>
        <a href="/auth/login.php" class="btn">Login</a>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Mobile menu flyout -->
  <div class="mobile-menu" id="mobileMenu">
    <button
      class="mobile-close"
      aria-label="Close menu"
      onclick="document.getElementById('mobileMenu').classList.remove('open')"
    >✕</button>

    <a href="/debates/list.php">Debates</a>
    <a href="/user/dashboard.php">Dashboard</a>

    <?php if (!empty($_SESSION['user'])): ?>
      <?php if ($_SESSION['user']['role'] === 'admin'): ?>
        <a href="/admin/dashboard.php">Admin Dashboard</a>
      <?php endif; ?>
      <a href="/auth/logout.php" onclick="return confirm('Are you sure you want to logout?');">Logout</a>
    <?php else: ?>
      <a href="/auth/register.php">Register</a>
      <a href="/auth/login.php">Login</a>
    <?php endif; ?>
  </div>
</header>
