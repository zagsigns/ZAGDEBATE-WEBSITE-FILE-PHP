<?php
// Reusable meta include
$PRIMARY = 'ZAG DEBATE';
$SECONDARY = 'THE DEBATIFY';
$DOMAIN_PRIMARY = 'zagdebate.com';
$DOMAIN_SECONDARY = 'thedebatify.com';

$meta_title = $meta_title ?? "$PRIMARY • $SECONDARY";
$meta_desc  = $meta_desc  ?? 'Create, join, and monetize online debates. A sleek, responsive community for bold, thoughtful discussions.';
$meta_url   = $meta_url   ?? (isset($_SERVER['REQUEST_URI']) ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] : '');
$meta_img   = $meta_img   ?? base_url() . '/assets/img/placeholder.jpg';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($meta_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($meta_desc) ?>">
<link rel="canonical" href="<?= htmlspecialchars($meta_url) ?>">
<meta name="robots" content="index, follow">

<!-- Open Graph -->
<meta property="og:title" content="<?= htmlspecialchars($meta_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($meta_desc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($meta_url) ?>">
<meta property="og:image" content="<?= htmlspecialchars($meta_img) ?>">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($meta_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($meta_desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($meta_img) ?>">
