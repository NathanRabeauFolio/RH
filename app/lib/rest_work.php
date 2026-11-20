<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

// Separate declaration register; no link to leave balances or payroll calculations.
function rest_work_records(): array
{
    ensure_storage();
    $path = data_file('rest_work');
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $rows = $raw === false ? null : json_decode($raw, true);
    if (!is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException('Registre des jours de repos travaillés illisible.');
    }
    return $rows;
}

function rest_work_mutate(callable $callback)
{
    ensure_storage();
    return with_file_lock(data_lock_file('rest_work'), static function () use ($callback) {
        $rows = rest_work_records();
        $result = $callback($rows);
        atomic_json_write(data_file('rest_work'), array_values($rows));
        return $result;
    });
}

function rest_work_for_employee(string $employeeId): array
{
    $rows = array_values(array_filter(rest_work_records(), static fn(array $row): bool => $row['employee_id'] === $employeeId));
    usort($rows, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
    return $rows;
}

function rest_work_day(string $date): string
{
    $days = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    return $days[(int) (new DateTimeImmutable($date))->format('N')];
}

function rest_work_date(string $date): string
{
    $date = validate_date($date);
    if ($date > date('Y-m-d')) throw new InvalidArgumentException('La date travaillée ne peut pas être future.');
    return $date;
}

function rest_work_reason(string $reason): string
{
    $reason = trim($reason);
    if (strlen($reason) > 800 || preg_match('//u', $reason) !== 1) {
        throw new InvalidArgumentException('Le motif est trop long ou invalide.');
    }
    return $reason;
}

function rest_work_token(): string
{
    session_start_if_needed();
    return $_SESSION['rest_work_csrf'] ??= bin2hex(random_bytes(32));
}

function rest_work_verify_token(string $token): void
{
    if (!hash_equals(rest_work_token(), $token)) throw new RuntimeException('Session expirée. Rechargez la page.');
}

function rest_work_flash_set(string $type, string $message): void
{
    session_start_if_needed();
    $_SESSION['rest_work_flash'] = ['type' => $type, 'message' => $message];
}

function rest_work_flash_get(): ?array
{
    session_start_if_needed();
    $flash = $_SESSION['rest_work_flash'] ?? null;
    unset($_SESSION['rest_work_flash']);
    return $flash;
}

// A date worked and the payroll reporting month are distinct facts.
// Existing declarations have a creation timestamp, so no stored data migration is needed.
function rest_work_report_month(array $row): string
{
    if (!empty($row['report_month'])) return (string) $row['report_month'];
    if (!empty($row['created_at'])) {
        $created = new DateTimeImmutable((string) $row['created_at']);
        return $created->setTimezone(new DateTimeZone((string) app_config()['timezone']))->format('Y-m');
    }
    return substr((string) $row['date'], 0, 7);
}

function rest_work_validate_report_month(string $month, string $date): string
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || $month < substr($date, 0, 7) || $month > date('Y-m')) {
        throw new InvalidArgumentException('Le mois RH doit être compris entre le mois travaillé et le mois en cours.');
    }
    return $month;
}

function rest_work_save(string $employeeId, array $dates, array $reasons, ?string $reportMonth = null): int
{
    if (!$dates || count($dates) > 100 || count($dates) !== count($reasons) || !array_is_list($dates) || !array_is_list($reasons)) {
        throw new InvalidArgumentException('Déclarations invalides.');
    }
    $reportMonth ??= date('Y-m');
    $pending = [];
    foreach ($dates as $i => $value) {
        if (!is_string($value) || !is_string($reasons[$i])) throw new InvalidArgumentException('Déclaration invalide.');
        $date = rest_work_date($value);
        rest_work_validate_report_month($reportMonth, $date);
        if (isset($pending[$date])) throw new InvalidArgumentException('Date déclarée deux fois : ' . $date);
        $pending[$date] = rest_work_reason($reasons[$i]);
    }
    return rest_work_mutate(static function (array &$rows) use ($employeeId, $pending, $reportMonth): int {
        foreach ($rows as $row) {
            if ($row['employee_id'] === $employeeId && isset($pending[$row['date']])) {
                throw new InvalidArgumentException('Date déjà déclarée : ' . $row['date']);
            }
        }
        foreach ($pending as $date => $reason) {
            $rows[] = ['id' => generate_id('rest'), 'employee_id' => $employeeId, 'date' => $date, 'reason' => $reason, 'report_month' => $reportMonth,
                'created_at' => now_iso(), 'updated_at' => now_iso()];
        }
        return count($pending);
    });
}

function rest_work_find(string $employeeId, string $id): array
{
    foreach (rest_work_for_employee($employeeId) as $row) {
        if ($row['id'] === $id) return $row;
    }
    throw new RuntimeException('Déclaration introuvable.');
}

function rest_work_update(string $employeeId, string $id, string $date, string $reason, ?string $reportMonth = null): void
{
    $date = rest_work_date($date);
    $reason = rest_work_reason($reason);
    rest_work_mutate(static function (array &$rows) use ($employeeId, $id, $date, $reason, $reportMonth): void {
        $index = null;
        foreach ($rows as $i => $row) {
            if ($row['employee_id'] !== $employeeId) continue;
            if ($row['id'] === $id) $index = $i;
            elseif ($row['date'] === $date) throw new InvalidArgumentException('Date déjà déclarée : ' . $date);
        }
        if ($index === null) throw new RuntimeException('Déclaration introuvable.');
        $month = rest_work_validate_report_month($reportMonth ?? rest_work_report_month($rows[$index]), $date);
        $rows[$index]['report_month'] = $month;
        $rows[$index]['date'] = $date;
        $rows[$index]['reason'] = $reason;
        $rows[$index]['updated_at'] = now_iso();
    });
}

function rest_work_delete(string $employeeId, string $id): void
{
    rest_work_mutate(static function (array &$rows) use ($employeeId, $id): void {
        foreach ($rows as $i => $row) {
            if ($row['employee_id'] === $employeeId && $row['id'] === $id) {
                unset($rows[$i]);
                return;
            }
        }
        throw new RuntimeException('Déclaration introuvable.');
    });
}

function rest_work_month_report(string $month): array
{
    $month = validate_month($month);
    $grouped = [];
    foreach (rest_work_records() as $row) {
        if (rest_work_report_month($row) === $month) $grouped[$row['employee_id']][$row['date']] = $row;
    }
    foreach ($grouped as &$records) ksort($records, SORT_STRING);
    unset($records);
    return $grouped;
}

function monthly_hr_table(string $month): array
{
    $grouped = rest_work_month_report($month);
    $transport = transport_docx_data($month);
    $rows = [];
    $total = 0;
    foreach (employees() as $i => $employee) {
        $records = $grouped[$employee['id']] ?? [];
        $count = count($records);
        $total += $count;
        $details = [['text' => $count . ' jour' . ($count === 1 ? '' : 's'), 'bold' => true]];
        foreach ($records as $record) {
            $date = $record['date'];
            $label = rest_work_day($date) . ' ' . (new DateTimeImmutable($date))->format('d/m/Y');
            $reason = trim((string) ($record['reason'] ?? ''));
            $details[] = ['text' => $label . ' — ' . ($reason === '' ? 'Motif non renseigné' : $reason)];
        }
        $original = $transport['rows'][$i];
        $rows[] = [$original[0], $original[2], $original[3], $original[4], $original[5], $original[6], $details];
    }
    $rows[] = ['TOTAL REPOS TRAVAILLÉS', '', '', '', '', '', [['text' => $total . ' jour' . ($total === 1 ? '' : 's'), 'bold' => true]]];
    return [
        'headers' => ['Collaborateur', 'Jours ouvrant droit', 'Congés posés', 'Retraits', 'Titres restants', 'Navigo', 'Jours de repos travaillés — dates et motifs'],
        'widths' => [2650, 1450, 1150, 1000, 1300, 850, 6600],
        'rows' => $rows,
        'total' => $total,
        'width' => 15000,
        'font_size' => 20,
        'left_columns' => [0, 6],
        'repeat_header' => true,
        'total_row' => true,
        'title' => 'Récapitulatif par collaborateur',
    ];
}
