<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Contact ZAG DEBATE | Get in Touch</title>
  <meta name="description" content="Contact ZAG DEBATE for support, partnership inquiries, or feedback.">
  <meta name="keywords" content="contact ZAG DEBATE, support, feedback, partnership">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Responsive page content margins */
    .page-content {
      max-width: 960px;        /* keeps content narrow on large screens */
      margin: 0 auto;          /* centers content horizontally */
      padding: 0 16px;         /* left/right padding for mobile */
    }
    @media (min-width: 768px) and (max-width: 1023px) {
      .page-content { padding: 0 24px; } /* tablet */
    }
    @media (min-width: 1024px) {
      .page-content { padding: 0 32px; } /* desktop */
    }

    .page-content h1 {
      margin-top: 12px;
      margin-bottom: 16px;
    }
    .page-content p {
      margin-bottom: 16px;
      line-height: 1.6;
    }
    .contact-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .contact-list li {
      margin-bottom: 12px;
      font-size: 1.1rem;
    }
    .contact-list a {
      color: var(--accent);
      text-decoration: none;
    }
    .contact-list a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="page-content">
  <h1>Contact Us</h1>
  <p>We’d love to hear from you. Reach out to us using the details below:</p>
  
  <ul class="contact-list">
    <li>Email: <a href="mailto:zagdebate@gmail.com">zagdebate@gmail.com</a></li>
    <li>Website: <a href="https://www.zagdebate.com" target="_blank" rel="noopener">www.zagdebate.com</a></li>
    <li>Alternate Domain: <a href="https://www.thedebatify.com" target="_blank" rel="noopener">www.thedebatify.com</a></li>
  </ul>

  <p><em>Note:</em> Both <strong>zagdebate.com</strong> and <strong>thedebatify.com</strong> point to the same platform, so you can use either address.</p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
