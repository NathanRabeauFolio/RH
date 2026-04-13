<?php
/** @var string $pageTitle */
/** @var array|null $user */
/** @var string $active */
$config = app_config();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h($pageTitle) ?></title>
  <meta name="theme-color" content="<?= h($config['brand']['primary']) ?>">
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<header class="site-header">
  <div class="wrap header-bar">
    <a class="brand" href="<?= is_admin() ? 'admin.php' : 'index.php' ?>">
      <img src="<?= h($config['logo_url']) ?>" alt="MAK Galerie">
      <div class="brand-copy">
        <strong><?= h($config['gallery_name']) ?></strong>
      </div>
    </a>
    <nav class="header-nav">
      <?php if ($user && ($user['role'] ?? '') === 'employee'): ?>
        <a class="nav-link <?= $active === 'dashboard' ? 'is-active' : '' ?>" href="index.php">Accueil</a>
        <a class="nav-link <?= $active === 'calendar' ? 'is-active' : '' ?>" href="team_calendar.php">Calendrier</a>
        <a class="nav-link" href="export_notes_docx.php">Word frais</a>
        <a class="nav-link" href="export_calendar_pdf.php">PDF calendrier</a>
      <?php elseif ($user && ($user['role'] ?? '') === 'admin'): ?>
        <a class="nav-link <?= $active === 'admin' ? 'is-active' : '' ?>" href="admin.php">Accueil</a>
        <a class="nav-link <?= $active === 'calendar' ? 'is-active' : '' ?>" href="team_calendar.php">Calendrier</a>
        <a class="nav-link" href="export_calendar_pdf.php">PDF calendrier</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <form method="post" action="logout.php">
          <button class="nav-link nav-button" type="submit">Sortie</button>
        </form>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap page-shell">
