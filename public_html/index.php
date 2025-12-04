<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app.php';
$meta_title = 'ZAG DEBATE • THE DEBATIFY — Bold debates, real rewards';
$meta_desc  = 'Create and join online debates. Earn as a creator. Dark, stylish, mobile-first design.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-MC6RD907XX"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-MC6RD907XX');
  </script>

  <?php include __DIR__ . '/seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<section class="hero container">
  <h1>Debate boldly. Earn fairly.</h1>
  <p>Start a topic, invite the crowd, and split the revenue 50/50 with the platform.</p>
  <div>
    <a class="btn" href="/auth/register.php">Create your account</a>
    <a class="btn" href="/debates/list.php" style="margin-left:8px">Explore debates</a>
  </div>
</section>

<section class="container">
  <div class="grid">
<?php
$debates = $pdo->query("SELECT d.*, u.name AS creator_name 
                        FROM debates d 
                        JOIN users u ON d.creator_id=u.id 
                        ORDER BY d.created_at DESC LIMIT 6")->fetchAll();

foreach ($debates as $deb) {
  $imgTag = '';
  if (!empty($deb['thumb_image'])) {
    $imgPath = __DIR__ . '/' . ltrim($deb['thumb_image'], '/');
    if (file_exists($imgPath)) {
      $imgTag = '<img src="' . htmlspecialchars($deb['thumb_image']) . '" alt="Debate" class="debate-thumb">';
    }
  }

  echo '<div class="card debate-card">';
  echo $imgTag;
  echo '<div class="debate-title">' . htmlspecialchars($deb['title']) . '</div>';
  echo '<div class="debate-desc">' . htmlspecialchars(mb_strimwidth(strip_tags($deb['description']), 0, 120, '...')) . '</div>';
  echo '<a class="btn-view" href="/debates/view.php?id=' . (int)$deb['id'] . '">View Debate</a>';
  echo '</div>';
}

if (empty($debates)) {
  echo '<div class="card"><p>No debates yet. Be the first to create one.</p><a class="btn" href="/debates/create.php">Create debate</a></div>';
}
?>
  </div>
</section>

<script src="/assets/js/app.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
