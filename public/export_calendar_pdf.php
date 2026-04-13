<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
require_login();
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
$title = 'Calendrier équipe — ' . month_label_fr($month);
response_pdf('calendrier_equipe_' . $month . '.pdf', $title, $month);
