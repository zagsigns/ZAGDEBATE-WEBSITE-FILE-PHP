<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
$meta_title = 'All Debates • ZAG DEBATE';

$user = current_user();

// Always fetch the latest settings row
$settings = $pdo->query("SELECT debate_access_mode, credits_to_join FROM settings ORDER BY id DESC LIMIT 1")->fetch();
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-MC6RD907XX"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-MC6RD907XX');
  </script>

  <?php include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container">
  <h2>All debates</h2>
  <div class="grid">
    <?php
      $debates = $pdo->query("SELECT d.*, u.name AS creator_name FROM debates d JOIN users u ON d.creator_id=u.id ORDER BY d.created_at DESC")->fetchAll();
      foreach ($debates as $deb) {
        $img = $deb['thumb_image'] ? htmlspecialchars($deb['thumb_image']) : '/assets/img/placeholder.jpg';
        echo '<div class="card debate-card">';
        echo '<img src="'. $img .'" alt="Debate" style="width:100%;height:160px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin-bottom:8px;">';
        echo '<div class="debate-title">'. htmlspecialchars($deb['title']) .'</div>';
        echo '<div class="label">By '. htmlspecialchars($deb['creator_name']) .'</div>';

        // Check if user already joined this debate
        $joined = false;
        if ($user) {
          $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id=? AND user_id=?");
          $j->execute([(int)$deb['id'], (int)$user['id']]);
          $joined = (bool)$j->fetch();
        }

        // Always show View button
        echo '<a class="btn" href="/debates/view.php?id='. (int)$deb['id'] .'">View debate</a>';

        // Optional joined badge
        if ($joined) {
          echo '<span class="label" style="margin-top:6px;color:#0f0">✓ Joined</span>';
        } else {
          if ($access_mode === 'credits' && $credits_required > 0) {
            echo '<span class="label" style="margin-top:6px">Requires '. $credits_required .' credits</span>';
          } else {
            echo '<span class="label" style="margin-top:6px">Free to join</span>';
          }
        }

        echo '</div>';
      }

      if (empty($debates)) {
        echo '<div class="card"><p>No debates yet.</p></div>';
      }
    ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


</body>
</html>
