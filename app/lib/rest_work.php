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

function rest_work_save(string $employeeId, array $dates, array $reasons): int
{
    if (!$dates || count($dates) > 100 || count($dates) !== count($reasons) || !array_is_list($dates) || !array_is_list($reasons)) {
        throw new InvalidArgumentException('Déclarations invalides.');
    }
    $pending = [];
    foreach ($dates as $i => $value) {
        if (!is_string($value) || !is_string($reasons[$i])) throw new InvalidArgumentException('Déclaration invalide.');
        $date = rest_work_date($value);
        if (isset($pending[$date])) throw new InvalidArgumentException('Date déclarée deux fois : ' . $date);
        $pending[$date] = rest_work_reason($reasons[$i]);
    }
    return rest_work_mutate(static function (array &$rows) use ($employeeId, $pending): int {
        foreach ($rows as $row) {
            if ($row['employee_id'] === $employeeId && isset($pending[$row['date']])) {
                throw new InvalidArgumentException('Date déjà déclarée : ' . $row['date']);
            }
        }
        foreach ($pending as $date => $reason) {
            $rows[] = ['id' => generate_id('rest'), 'employee_id' => $employeeId, 'date' => $date, 'reason' => $reason,
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

function rest_work_update(string $employeeId, string $id, string $date, string $reason): void
{
    $date = rest_work_date($date);
    $reason = rest_work_reason($reason);
    rest_work_mutate(static function (array &$rows) use ($employeeId, $id, $date, $reason): void {
        $index = null;
        foreach ($rows as $i => $row) {
            if ($row['employee_id'] !== $employeeId) continue;
            if ($row['id'] === $id) $index = $i;
            elseif ($row['date'] === $date) throw new InvalidArgumentException('Date déjà déclarée : ' . $date);
        }
        if ($index === null) throw new RuntimeException('Déclaration introuvable.');
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
        if (substr($row['date'], 0, 7) === $month) $grouped[$row['employee_id']][$row['date']] = true;
    }
    $rows = [];
    $total = 0;
    foreach (employees() as $employee) {
        $dates = array_keys($grouped[$employee['id']] ?? []);
        sort($dates, SORT_STRING);
        $labels = array_map(static fn(string $date): string => rest_work_day($date) . ' ' . (new DateTimeImmutable($date))->format('d/m/Y'), $dates);
        $n = count($dates);
        $total += $n;
        $rows[] = [$employee['name'], $labels ? implode(' ; ', $labels) : '—', (string) $n];
    }
    $rows[] = ['TOTAL ÉQUIPE', '', (string) $total];
    return ['headers' => ['Collaborateur', 'Dates des jours de repos travaillés', 'Nombre de jours'], 'widths' => [2700, 5800, 1900], 'rows' => $rows, 'total' => $total];
}
