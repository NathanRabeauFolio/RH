<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
ensure_storage();
$user = require_login('admin');
$month = team_calendar_month((string) ($_GET['month'] ?? date('Y-m')));
$table = transport_docx_data($month);
$restReport = rest_work_month_report($month);
$spec = [
    'title' => 'Déclarations RH mensuelles',
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
    'sections' => [[
        'title' => 'Jours de repos travaillés — ' . month_label_fr($month),
        'intro' => 'Déclarations directes des salariés, reprises sans validation administrative. Les dates correspondent aux journées travaillées pendant leurs jours de repos. Total : ' . $restReport['total'] . ' jour(s) déclaré(s).',
        'table' => $restReport,
        'note' => 'Décompte des dates déclarées uniquement. Aucune équivalence en repos ou compensation n’est calculée.',
    ]],
    'notes' => [
        'Document de synthèse destiné au suivi administratif mensuel de l’équipe. Les jours de congés posés sur le mois sont intégrés au tableau.',
    ],
];
response_docx('declarations_rh_' . $month . '.docx', $spec);
