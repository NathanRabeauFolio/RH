<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
$user = require_login();
$month = team_calendar_month();
$calendar = month_calendar($month);
$pageTitle = app_config()['app_name'] . ' — Calendrier';
$active = 'calendar';
include __DIR__ . '/partials/header.php';
?>
<section class="section-block">
  <div class="section-title"><h1>Calendrier d’équipe</h1></div>
  <div class="panel panel-tight">
    <?php include __DIR__ . '/partials/calendar_block.php'; ?>
  </div>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
