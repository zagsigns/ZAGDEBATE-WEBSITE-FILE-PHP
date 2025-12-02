<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<header class="header">
  <div class="navbar container">
    <!-- Brand / Logo -->
    <a class="brand" href="/">
      <img src="/assets/img/logo.svg" alt="ZAG" class="logo-img">
      <span class="logo-text">ZAG DEBATE</span>
    </a>

    <!-- Toggle button (mobile) -->
    <button class="toggle btn-outline" onclick="document.querySelector('.mobile-menu').classList.toggle('open')">
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
        <a href="/auth/logout.php" class="btn">Logout</a>
      <?php else: ?>
        <a href="/auth/register.php" class="btn">Register</a>
        <a href="/auth/login.php" class="btn">Login</a>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Mobile menu -->
  <div class="mobile-menu">
    <a href="/debates/list.php">Debates</a>
    <a href="/user/dashboard.php">Dashboard</a>

    <?php if (!empty($_SESSION['user'])): ?>
      <?php if ($_SESSION['user']['role'] === 'admin'): ?>
        <a href="/admin/dashboard.php">Admin Dashboard</a>
      <?php endif; ?>
      <a href="/auth/logout.php">Logout</a>
    <?php else: ?>
      <a href="/auth/register.php">Register</a>
      <a href="/auth/login.php">Login</a>
    <?php endif; ?>
  </div>
</header>
