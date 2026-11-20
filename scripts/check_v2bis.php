<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/lib/helpers.php';
function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}
$staff = employees();
check(count($staff) === 5, 'Expected five employees');
$ids = array_column($staff, 'id');
check(count(array_unique($ids)) === count($ids), 'Duplicate employee IDs');
$laura = employee_by_id('laura');
check($laura !== null && $laura['name'] === 'Laura', 'Laura not registered');
check(password_verify(strtolower($laura['first_name']), $laura['password_hash']), 'Laura password convention mismatch');
check(employee_off_weekdays('laura') === [6, 7], 'Laura default weekends mismatch');
$calendar = month_calendar('2026-11');
check(count($calendar['weeks']) === 6, 'Calendar regression');
foreach ($staff as $employee) {
    check(default_ticket_days_for_month($employee['id'], '2026-11') >= 0, 'Invalid ticket calculation');
}
echo "RH V2 bis: five employee profiles, Laura credential, calendar, ticket calculation OK\n";
