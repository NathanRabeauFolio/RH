<?php
/** @var string $pageTitle */
/** @var array|null $user */
/** @var string $active */
$config = app_config();
$adminRole = ($user['role'] ?? '') === 'admin';
$home = $adminRole ? 'admin.php' : 'index.php';
$onHome = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === $home;
$sectionUrl = static fn(string $id): string => ($onHome ? '' : $home) . '#' . $id;
$icons = [
    'home' => '<path d="m3 10 9-7 9 7v10H3zM9 20v-7h6v7"/>',
    'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/>',
    'ticket' => '<path d="M3 7h18v4a1 1 0 0 0 0 2v4H3v-4a1 1 0 0 0 0-2zM13 7v10"/>',
    'transport' => '<rect x="5" y="3" width="14" height="15" rx="3"/><path d="M5 11h14M8 21l2-3m6 0 2 3M9 15h.01M15 15h.01"/>',
    'cal' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'file' => '<path d="M6 3h8l4 4v14H6zM14 3v5h5M9 12h6M9 16h6"/>',
    'out' => '<path d="M10 17l5-5-5-5M15 12H3M15 3h6v18h-6"/>',
];
$navIcon = static fn(string $name): string => '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$name] ?? '') . '</svg>';
$navLink = static function (string $label, string $url, string $icon, bool $selected = false) use ($navIcon): void {
    echo '<a class="nav-link' . ($selected ? ' is-active' : '') . '" href="' . h($url) . '"' . ($selected ? ' aria-current="page"' : '') . '>' . $navIcon($icon) . '<span>' . h($label) . '</span></a>';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h($pageTitle) ?></title>
  <meta name="theme-color" content="<?= h($config['brand']['primary']) ?>">
  <link rel="stylesheet" href="assets/css/styles.css?v=2bis">
</head>
<body class="<?= $user ? 'app-auth' : 'app-guest' ?>">
<a class="skip-link" href="#main-content">Aller au contenu</a>
<header class="site-header">
  <div class="wrap header-bar">
    <a class="brand" href="<?= h($home) ?>">
      <img src="<?= h($config['logo_url']) ?>" alt="MAK Galerie">
      <div class="brand-copy"><strong><?= h($config['gallery_name']) ?></strong><span>Espace RH</span></div>
    </a>
    <?php if ($user): ?>
      <nav class="header-nav desktop-nav" aria-label="Navigation principale">
        <?php $navLink('Accueil', $home, 'home', $onHome); ?>
        <?php if ($adminRole): ?>
          <div class="nav-group"><p>Administration</p>
            <?php $navLink('Validation des congés', $sectionUrl('validation'), 'cal'); ?>
            <?php $navLink('Calendrier d’équipe', 'team_calendar.php', 'cal', $active === 'calendar'); ?>
            <?php $navLink('Exports', $sectionUrl('exports'), 'file'); ?>
            <?php $navLink('Historique des congés', $sectionUrl('historique'), 'receipt'); ?>
          </div>
        <?php else: ?>
          <div class="nav-group"><p>Mes déclarations</p>
            <?php $navLink('Notes de frais', $sectionUrl('notes-de-frais'), 'receipt'); ?>
            <?php $navLink('Titres-restaurant', $sectionUrl('titres-restaurant'), 'ticket'); ?>
            <?php $navLink('Navigo', $sectionUrl('navigo'), 'transport'); ?>
            <?php $navLink('Congés', $sectionUrl('conges'), 'cal'); ?>
          </div>
          <div class="nav-group"><p>Équipe</p><?php $navLink('Calendrier d’équipe', 'team_calendar.php', 'cal', $active === 'calendar'); ?></div>
        <?php endif; ?>
        <div class="nav-group"><p>Documents</p>
          <?php if (!$adminRole) $navLink('Word · Notes de frais', 'export_notes_docx.php', 'file'); ?>
          <?php $navLink('PDF · Calendrier', 'export_calendar_pdf.php', 'file'); ?>
        </div>
        <form class="logout-form" method="post" action="logout.php"><button class="nav-link nav-button" type="submit"><?= $navIcon('out') ?>Déconnexion</button></form>
      </nav>
      <nav class="mobile-nav" aria-label="Navigation mobile">
        <?php $navLink('Accueil', $home, 'home', $onHome); ?>
        <?php $navLink('Calendrier', 'team_calendar.php', 'cal', $active === 'calendar'); ?>
        <?php if (!$adminRole) $navLink('Word frais', 'export_notes_docx.php', 'file'); ?>
        <?php $navLink('PDF calendrier', 'export_calendar_pdf.php', 'file'); ?>
        <?php if ($adminRole): ?><form method="post" action="logout.php"><button class="nav-link nav-button" type="submit">Déconnexion</button></form><?php endif; ?>
      </nav>
      <?php if (!$adminRole): ?><form class="mobile-logout" method="post" action="logout.php"><button type="submit">Déconnexion</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</header>
<main id="main-content" class="wrap page-shell">
