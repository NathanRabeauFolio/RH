<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
require_login();
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
rh2_response_pdf('calendrier_equipe_' . $month . '.pdf', $month);
