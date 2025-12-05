<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
$meta_title = 'All Debates • ZAG DEBATE';

$user = current_user();

// Always fetch the latest settings row
$settings = $pdo->query("SELECT debate_access_mode, credits_to_join FROM settings ORDER BY id DESC LIMIT 1")->fetch();
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);

// Read search and pagination (GET)
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 18;
$offset = ($page - 1) * $perPage;

// Build safe query
$params = [];
$where = '';
if ($q !== '') {
    $where = "WHERE (d.title LIKE :q OR d.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

// Count total for pagination
$countSql = "SELECT COUNT(*) FROM debates d $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// Fetch debates with creator name
$sql = "SELECT d.*, u.name AS creator_name
        FROM debates d
        JOIN users u ON d.creator_id = u.id
        $where
        ORDER BY d.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$debates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for building query strings (preserve q)
function qs(array $overrides = []) {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($base[$k]); else $base[$k] = $v;
    }
    return http_build_query($base);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($meta_title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?php include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Inline helpers to ensure centered search looks neat even if external CSS hasn't loaded */
    .list-search-center {
      display:flex;
      justify-content:center;
      align-items:center;
      min-height:40vh;
      width:100%;
      padding:24px 0;
    }
    .search-bar { display:flex; gap:10px; align-items:center; max-width:720px; width:100%; padding:10px; background:linear-gradient(180deg, rgba(255,46,46,0.02), transparent); border:1px solid var(--border); border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.35); }
    .search-input { flex:1; padding:12px 14px; background:transparent; border:none; color:var(--text); border-radius:8px; font-size:1rem; }
    .search-input::placeholder { color:#9aa3ad; }
    .search-btn { padding:10px 14px; border-radius:8px; background:var(--accent); color:#fff; border:none; cursor:pointer; font-weight:600; }
    .sr-only { position:absolute !important; height:1px; width:1px; overflow:hidden; clip:rect(1px,1px,1px,1px); white-space:nowrap; }
    @media (max-width:520px) { .search-bar { flex-direction:column; } .search-btn { width:100%; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container">
  <h1 style="text-align:center; margin-top:8px;">All debates</h1>

  <!-- Centered Search (middle of the page) -->
  <div class="list-search-center" role="region" aria-label="Search debates">
    <form class="search-bar" method="get" action="/debates/list.php" role="search" aria-label="Search debates">
      <label for="q" class="sr-only">Search debates</label>
      <input id="q" name="q" class="search-input" type="search" placeholder="Search debates by title or description..." value="<?= htmlspecialchars($q) ?>" aria-label="Search debates" autocomplete="off">
      <button class="search-btn" type="submit">Search</button>
    </form>
  </div>

  <?php if ($q !== ''): ?>
    <div class="search-meta" style="color:var(--muted); margin-bottom:12px; text-align:center;">
      Showing results for “<?= htmlspecialchars($q) ?>” — <?= $total ?> result<?= $total !== 1 ? 's' : '' ?>.
    </div>
  <?php endif; ?>

  <div class="grid" style="margin-top:8px;">
    <?php if (empty($debates)): ?>
      <div class="card" style="text-align:center; padding:28px;">
        <?php if ($q !== ''): ?>
          <p style="color:var(--muted); margin-bottom:12px;">No debates match your search.</p>
          <p style="margin-bottom:12px;"><a href="/debates/list.php" class="btn">Clear search</a></p>
          <p style="color:var(--muted);">Try different keywords or create a new debate.</p>
        <?php else: ?>
          <p style="color:var(--muted); margin-bottom:12px;">No debates yet.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($debates as $deb): ?>
        <?php
          $img = $deb['thumb_image'] ? htmlspecialchars($deb['thumb_image']) : '/assets/img/placeholder.jpg';
        ?>
        <article class="card debate-card" itemscope itemtype="http://schema.org/DiscussionForumPosting">
          <img src="<?= $img ?>" alt="Thumbnail for <?= htmlspecialchars($deb['title']) ?>" class="debate-thumb" loading="lazy">
          <h3 class="debate-title" itemprop="headline"><?= htmlspecialchars($deb['title']) ?></h3>
          <div class="label" style="margin-bottom:8px;">By <?= htmlspecialchars($deb['creator_name']) ?></div>

          <?php
            // Check if user already joined this debate
            $joined = false;
            if ($user) {
              $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id=? AND user_id=?");
              $j->execute([(int)$deb['id'], (int)$user['id']]);
              $joined = (bool)$j->fetch();
            }
          ?>

          <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
            <a class="btn-view" href="/debates/view.php?id=<?= (int)$deb['id'] ?>">View debate</a>

            <?php if ($joined): ?>
              <span class="label" style="margin-left:auto; color:#0f0; font-weight:700;">✓ Joined</span>
            <?php else: ?>
              <?php if ($access_mode === 'credits' && $credits_required > 0): ?>
                <span class="label" style="margin-left:auto;">Requires <?= $credits_required ?> credits</span>
              <?php else: ?>
                <span class="label" style="margin-left:auto;">Free to join</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <nav aria-label="Pagination" style="margin-top:20px; text-align:center;">
      <ul style="display:inline-flex; gap:8px; list-style:none; padding:0; margin:0;">
        <?php if ($page > 1): ?>
          <li><a class="btn-outline" href="?<?= qs(['page' => $page - 1, 'q' => $q]) ?>">Prev</a></li>
        <?php endif; ?>

        <?php
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);
          if ($start > 1) echo '<li><a class="btn-outline" href="?'. qs(['page' => 1, 'q' => $q]) .'">1</a></li>';
          if ($start > 2) echo '<li><span class="card" style="padding:8px 12px; background:transparent; border:none; color:var(--muted);">…</span></li>';
          for ($i = $start; $i <= $end; $i++):
        ?>
          <li>
            <?php if ($i === $page): ?>
              <span class="btn" aria-current="page" style="background:var(--bg-2); border:1px solid var(--border);"><?= $i ?></span>
            <?php else: ?>
              <a class="btn-outline" href="?<?= qs(['page' => $i, 'q' => $q]) ?>"><?= $i ?></a>
            <?php endif; ?>
          </li>
        <?php endfor;
          if ($end < $totalPages - 1) echo '<li><span class="card" style="padding:8px 12px; background:transparent; border:none; color:var(--muted);">…</span></li>';
          if ($end < $totalPages) echo '<li><a class="btn-outline" href="?'. qs(['page' => $totalPages, 'q' => $q]) .'">'. $totalPages .'</a></li>';
        ?>

        <?php if ($page < $totalPages): ?>
          <li><a class="btn-outline" href="?<?= qs(['page' => $page + 1, 'q' => $q]) ?>">Next</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
