<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
$user = require_login('admin');
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
$table = transport_docx_data($month);
$spec = [
    'title' => 'Titres-restaurant & Navigo',
    'subtitle' => month_label_fr($month),
    'meta' => [
        ['Période', month_label_fr($month)],
        ['Export', date('d/m/Y')],
    ],
    'summary' => [
        ['Collaborateurs', (string) count(employees())],
        ['Mois', month_label_fr($month)],
        ['Congés posés', (string) array_sum(array_map(static fn(array $employee): int => leave_days_in_month($employee['id'], $month), employees()))],
    ],
    'table' => $table,
    'notes' => [
        'Document de synthèse destiné au suivi administratif mensuel de l’équipe. Les jours de congés posés sur le mois sont intégrés au tableau.',
    ],
];
response_docx('titres_restaurant_navigo_' . $month . '.docx', $spec);
