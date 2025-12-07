<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app.php';

$meta_title = 'ZAG DEBATE & THE DEBATIFY • Bold debates, real rewards';
$meta_desc  = 'Join ZAG DEBATE & THE DEBATIFY — the stylish platform for bold debates, real rewards, and global reach. Start or join debates, earn as a creator, and engage the crowd.';

// Read search and pagination (GET)
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;

// Build query safely
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
  <meta name="description" content="<?= htmlspecialchars($meta_desc) ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Open Graph / Twitter -->
  <meta property="og:title" content="<?= htmlspecialchars($meta_title) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($meta_desc) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($meta_title) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($meta_desc) ?>">

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?')) ?>">

  <!-- Favicon -->
  <link rel="icon" href="/favicon.ico" type="image/x-icon">

  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Inline helpers for search visuals (keeps page neat if CSS not yet updated) */
    .search-bar { display:flex; gap:10px; align-items:center; margin:20px auto 0; max-width:720px; width:100%; padding:8px; background:linear-gradient(180deg, rgba(255,46,46,0.02), transparent); border:1px solid var(--border); border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,0.35); }
    .search-input { flex:1; padding:12px 14px; background:transparent; border:none; color:var(--text); font-size:1rem; outline:none; border-radius:8px; }
    .search-input::placeholder { color:#9aa3ad; }
    .search-btn { padding:10px 16px; border-radius:10px; background:linear-gradient(90deg,#ef4444,#dc2626); color:#fff; border:none; font-weight:700; cursor:pointer; box-shadow:0 6px 18px rgba(220,36,36,0.12); }
    .search-btn:hover { transform:translateY(-2px); }
    .search-clear { background:transparent; border:1px solid transparent; color:var(--muted); padding:6px 8px; border-radius:8px; cursor:pointer; transition:color .12s, background .12s; }
    .search-clear:hover { color:#fff; background:rgba(255,255,255,0.03); }
    .sr-only { position:absolute !important; height:1px; width:1px; overflow:hidden; clip:rect(1px,1px,1px,1px); white-space:nowrap; }

    /* CTA buttons row */
    .cta-buttons { display:flex; gap:12px; justify-content:center; align-items:center; margin-top:18px; flex-wrap:wrap; }
    .cta-buttons .btn { display:inline-block; }

    /* Keep existing button colors/styles intact; only change layout on smaller screens */
    @media (max-width:900px) {
      .cta-buttons { flex-direction:column; align-items:stretch; gap:10px; }
      .cta-buttons .btn { width:100%; text-align:center; }
      .search-bar { flex-direction:column; gap:8px; padding:12px; }
      .search-btn { width:100%; }
      .search-clear { align-self:flex-end; }
    }

    @media (max-width:520px) {
      .search-bar { padding:12px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main>
  <!-- Hero / Search -->
  <section class="hero container" aria-labelledby="site-heading">
    <h1 id="site-heading">ZAG DEBATE &amp; THE DEBATIFY</h1>
    <h2 class="tagline">Debate boldly. Earn fairly.</h2>
    <p class="subtext">Start a topic, invite the crowd, and split the revenue 50/50 with the platform.</p>

    <div class="cta-buttons" role="region" aria-label="Primary actions">
      <a class="btn btn-primary" href="/auth/register.php">👤➕ Create your account</a>
      <a class="btn btn-secondary" href="/debates/list.php">🔍💬  Explore debates</a>
      <!-- New button that redirects to user dashboard -->
      <a class="btn btn-primary" href="/user/dashboard.php">🗣💰️ Create debate&amp;Earn</a>
    </div>

    <!-- Search form with clear button -->
    <form class="search-bar" method="get" action="/" role="search" aria-label="Search debates">
      <label for="q" class="sr-only">Search debates</label>
      <input id="q" name="q" class="search-input" type="search" placeholder="🔍 Search debates by title or description..." value="<?= htmlspecialchars($q) ?>" aria-label="Search debates" autocomplete="off" />
      <button type="button" class="search-clear" id="searchClear" title="Clear search" aria-hidden="false">✕</button>
      <button class="search-btn" type="submit">🔍 Search</button>
    </form>

    <?php if ($q !== ''): ?>
      <div class="search-meta" style="margin-top:10px; color:var(--muted); text-align:center;">
        Showing results for “<?= htmlspecialchars($q) ?>” — <?= $total ?> result<?= $total !== 1 ? 's' : '' ?>.
      </div>
    <?php endif; ?>
  </section>

  <!-- Results / Featured -->
  <section class="container" aria-labelledby="results-heading" style="margin-top:28px;">
    <h2 id="results-heading" class="section-title"><?= $q === '' ? '🔥 Featured Debates' : 'Search results' ?></h2>

    <?php if (empty($debates)): ?>
      <div class="card" style="text-align:center; padding:28px;">
        <?php if ($q !== ''): ?>
          <p style="color:var(--muted); margin-bottom:12px;">No debates match your search.</p>
          <p style="margin-bottom:12px;"><a href="/" class="btn">Clear search</a></p>
          <p style="color:var(--muted);">Try different keywords or browse all debates.</p>
        <?php else: ?>
          <p style="color:var(--muted); margin-bottom:12px;">No debates yet. Be the first to create one.</p>
          <a class="btn" href="/debates/create.php">Create debate</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="grid" style="margin-top:12px;">
        <?php foreach ($debates as $deb): ?>
          <?php
            $imgTag = '';
            if (!empty($deb['thumb_image'])) {
              $imgPath = __DIR__ . '/' . ltrim($deb['thumb_image'], '/');
              if (file_exists($imgPath)) {
                $imgTag = '<img src="' . htmlspecialchars($deb['thumb_image']) . '" alt="Thumbnail for ' . htmlspecialchars($deb['title']) . '" class="debate-thumb" loading="lazy">';
              }
            }
          ?>
          <article class="card debate-card" itemscope itemtype="http://schema.org/DiscussionForumPosting">
            <?= $imgTag ?>
            <h3 class="debate-title" itemprop="headline"><?= htmlspecialchars($deb['title']) ?></h3>
            <p class="debate-desc" itemprop="articleBody"><?= htmlspecialchars(mb_strimwidth(strip_tags($deb['description']), 0, 140, '...')) ?></p>
            <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
              <a class="btn-view" href="/debates/view.php?id=<?= (int)$deb['id'] ?>">👁🗣️️ View Debate</a>
              <div style="margin-left:auto; color:var(--muted); font-size:0.9rem;">By <?= htmlspecialchars($deb['creator_name']) ?></div>
            </div>
          </article>
        <?php endforeach; ?>
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
              if ($start > 1) echo '<li><span class="card" style="padding:8px 12px; background:transparent; border:none; color:var(--muted);">1</span></li>';
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
    <?php endif; ?>
  </section>
</main>

<script src="/assets/js/app.js"></script>

<!-- Small inline script to power the clear button and keyboard shortcut (Esc) -->
<script>
  (function () {
    const input = document.getElementById('q');
    const clearBtn = document.getElementById('searchClear');

    if (!input || !clearBtn) return;

    function toggleClear() {
      clearBtn.style.visibility = input.value.trim() ? 'visible' : 'hidden';
    }

    clearBtn.addEventListener('click', function () {
      input.value = '';
      input.focus();
      toggleClear();
    });

    input.addEventListener('input', toggleClear);

    // Hide clear initially if empty
    toggleClear();

    // Allow Esc to clear input
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        input.value = '';
        toggleClear();
      }
    });
  })();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
