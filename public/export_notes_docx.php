<?php
require dirname(__DIR__) . '/app/lib/export_layout_v2.php';
ensure_storage();

$user = require_login();
$employeeId = (string) ($_GET['employee'] ?? (($user['role'] ?? '') === 'employee' ? $user['id'] : ''));
if ($employeeId === '' || !employee_by_id($employeeId)) {
    flash_set('error', 'Employé invalide.');
    redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
}
if (($user['role'] ?? '') === 'employee' && $employeeId !== $user['id']) {
    flash_set('error', 'Accès refusé.');
    redirect('index.php');
}

$employee = employee_by_id($employeeId);
$totals = notes_total_for_employee($employeeId);
$bundle = notes_docx_bundle($employeeId);
$table = $bundle['table'];
$appendices = $bundle['appendices'];
$hasNonPreview = false;
foreach ($appendices as $appendix) {
    if (($appendix['type'] ?? '') !== 'preview') {
        $hasNonPreview = true;
        break;
    }
}
$notes = [];
if ($hasNonPreview) {
    $notes[] = 'Les justificatifs non prévisualisables restent référencés dans les annexes, sans aperçu imprimé dans le document Word.';
}
$spec = [
    'layout_v2' => 'expenses',
    'title' => 'Notes de frais',
    'subtitle' => $employee['name'] . ', le ' . date('d/m/Y'),
    'summary' => [
        ['Total TTC', money($totals['ttc'])],
        ['Total HT', money($totals['ht'])],
        ['Lignes', (string) count($table['rows'])],
    ],
    'table' => $table,
    'notes' => $notes,
    'appendices' => $appendices,
];
response_docx('notes_frais_' . slugify($employee['name']) . '.docx', $spec);
