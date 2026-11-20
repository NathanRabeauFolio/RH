<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
ensure_storage();
$user = require_login('admin');
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
$table = monthly_hr_table($month);
$spec = [
    'title' => 'Déclarations RH mensuelles',
    'subtitle' => month_label_fr($month) . ' · Export du ' . date('d/m/Y'),
    'landscape' => true,
    'table' => $table,
    'notes' => [
        'Jours de repos travaillés : déclarations directes des salariés, sans validation administrative. Les dates effectivement travaillées et leurs motifs figurent dans le mois RH de rattachement, y compris en cas de déclaration tardive.',
        'Total : ' . $table['total'] . ' jour(s) de repos travaillé(s) déclaré(s) pour ce mois RH. Aucune compensation n’est calculée.',
    ],
];
response_docx('declarations_rh_' . $month . '.docx', $spec);
