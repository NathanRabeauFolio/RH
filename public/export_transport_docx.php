<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
ensure_storage();
$user = require_login('admin');
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
$table = monthly_hr_table($month);
$spec = [
    'title' => 'Déclarations RH mensuelles',
    'subtitle' => month_label_fr($month) . ' · Export du ' . date('d/m/Y'),
    'landscape' => $table['has_rest_work'],
    'table' => $table,
];
response_docx('declarations_rh_' . $month . '.docx', $spec);
