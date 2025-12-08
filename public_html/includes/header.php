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

  <!-- Small header-specific CSS to center logo on mobile/tablet and show left dashboard button -->
  <style>
    /* Header layout: three zones (left, center, right) */
    .header .navbar {
      display:flex;
      align-items:center;
      justify-content:space-between;
      position:relative;
      gap:12px;
    }

    /* Left and right zones default hidden on desktop (we keep desktop menu as-is) */
    .mobile-left-btn {
      display:none;
      align-items:center;
      gap:8px;
    }

    /* Ensure brand behaves normally on desktop */
    .brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit; }

    /* Toggle default (desktop hidden by existing CSS) */
    .toggle { /* existing styles apply from style.css */ }

    /* Mobile + Tablet: center logo, show left button, keep toggle on right */
    @media (max-width:1023px) {
      /* Make the navbar three zones: left, center (absolute), right */
      .header .navbar { padding-left:8px; padding-right:8px; }

      /* Left button visible on mobile/tablet */
      .mobile-left-btn { display:flex; }

      /* Center the brand absolutely so it stays centered regardless of left/right widths */
      .brand {
        position:absolute;
        left:50%;
        transform:translateX(-50%);
        margin:0;
        pointer-events:auto;
      }

      /* Keep the right-side toggle aligned to the right (normal flow) */
      .navbar .menu { display:none; } /* hide desktop menu on small screens (existing behavior) */

      /* Ensure toggle remains visible and on the right */
      .toggle { display:inline-flex; z-index:1300; }

      /* Slightly reduce brand image size on small screens if needed */
      .brand img { height:48px; width:auto; display:block; }

      /* Make sure left button and toggle don't overlap the centered logo */
      .mobile-left-btn,
      .toggle {
        background:var(--bg-2);
        border:1px solid var(--border);
        color:var(--text);
        padding:8px 10px;
        border-radius:8px;
        font-weight:600;
      }

      /* Keep left button visually distinct */
      .mobile-left-btn a { color:var(--text); text-decoration:none; display:inline-flex; align-items:center; gap:8px; }

      /* Ensure brand is clickable and accessible */
      .brand:focus { outline:2px solid rgba(255,46,46,0.12); border-radius:6px; }
    }
    
    /* Mobile + tablet: remove red rectangular border on header dashboard button only */
@media (max-width:1023px) {
  .mobile-left-btn .btn-outline,
  .mobile-left-btn .dash-icon-btn {
    border: none !important;
    border-color: transparent !important;
    box-shadow: none !important;
    background: transparent !important;
  }
}



  </style>
  

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">


  
  
<!--AdSense code snippet-->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2771457394201259"
     crossorigin="anonymous"></script>

<!--Adsense Meta tag     -->
<meta name="google-adsense-account" content="ca-pub-2771457394201259">     
     
     
  
</head>
<body>
<header class="header">
  <div class="navbar container">
    <!-- Left zone: mobile-only dashboard button -->
    <div class="mobile-left-btn" aria-hidden="false">
      <a href="/user/dashboard.php" class="btn-outline" aria-label="Dashboard">
        <!-- simple icon + label; adjust text/icon as desired -->
        <span style="display:inline-block; transform:translateY(-1px);">👤</span>
        <!--<span style="font-weight:700;">Dashboard</span>-->
      </a>
    </div>

    <!-- Center zone: Brand / Logo (will be centered on mobile/tablet via CSS) -->
    <a class="brand" href="/" aria-label="Zag Debate home">
      <img src="/logo.png" alt="ZAG" class="logo-img">
    </a>

    <!-- Right zone: Toggle button (mobile) -->
    <button
      class="toggle btn-outline"
      aria-label="Open menu"
      onclick="document.getElementById('mobileMenu').classList.toggle('open')"
    >
      ☰
    </button>

    <!-- Desktop menu (kept for desktop; hidden on small screens by existing CSS) -->
    <nav class="menu" aria-label="Primary navigation">
      <a href="/debates/list.php" class="btn">🗣 Debates</a>
      <a href="/user/dashboard.php" class="btn">👤📊 User Dashboard</a>

      <?php if (!empty($_SESSION['user'])): ?>
        <?php if ($_SESSION['user']['role'] === 'admin'): ?>
          <a href="/admin/dashboard.php" class="btn">👤📊 Admin Dashboard</a>
        <?php endif; ?>
        <a href="/auth/logout.php" class="btn" onclick="return confirm('Are you sure you want to logout?');">👤➡️ Logout</a>
      <?php else: ?>
        <a href="/auth/register.php" class="btn"> 👤➕ User Register</a>
        <a href="/auth/login.php" class="btn">👤🔑 Login</a>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Mobile menu flyout -->
  <div class="mobile-menu" id="mobileMenu" aria-hidden="true">
    <button
      class="mobile-close"
      aria-label="Close menu"
      onclick="document.getElementById('mobileMenu').classList.remove('open')"
    >✕</button>

    <a href="/debates/list.php">🗣 Debates</a>
    <a href="/user/dashboard.php">👤📊 User Dashboard</a>

    <?php if (!empty($_SESSION['user'])): ?>
      <?php if ($_SESSION['user']['role'] === 'admin'): ?>
        <a href="/admin/dashboard.php">👤📊 Admin Dashboard</a>
      <?php endif; ?>
      <a href="/auth/logout.php" onclick="return confirm('Are you sure you want to logout?');">👤➡️ Logout</a>
    <?php else: ?>
      <a href="/auth/register.php">👤➕ User Register</a>
      <a href="/auth/login.php">👤🔑 Login</a>
    <?php endif; ?>
  </div>
</header>
