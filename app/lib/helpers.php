<?php
declare(strict_types=1);

function root_path(string $suffix = ''): string
{
    $base = dirname(__DIR__, 2);
    return $suffix ? $base . '/' . ltrim($suffix, '/') : $base;
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = require root_path('app/config.default.php');
    $overridePath = root_path('config.local.php');
    if (is_file($overridePath)) {
        $override = require $overridePath;
        if (is_array($override)) {
            $config = array_replace_recursive($config, $override);
        }
    }
    date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Paris'));
    return $config;
}

function ensure_storage(): void
{
    foreach (['storage', 'storage/uploads', 'storage/data', 'storage/backups', 'storage/logs'] as $dir) {
        $path = root_path($dir);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Impossible de créer le stockage.');
        }
    }
    foreach (['expenses', 'tickets', 'navigo', 'leaves'] as $type) {
        $file = data_file($type);
        if (!is_file($file)) {
            atomic_json_write($file, []);
        }
    }
    if (!is_file(login_journal_file())) {
        touch(login_journal_file());
    }
}

function data_file(string $type): string
{
    return root_path('storage/data/' . $type . '.json');
}

function backup_dir(string $type): string
{
    $dir = root_path('storage/backups/' . $type);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de sauvegarde.');
    }
    return $dir;
}

function login_journal_file(): string
{
    return root_path('storage/logs/login_journal.ndjson');
}

function data_lock_file(string $type): string
{
    return root_path('storage/data/' . $type . '.lock');
}

function with_file_lock(string $lockPath, callable $callback)
{
    $fh = fopen($lockPath, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Impossible d\'ouvrir le verrou.');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Impossible de verrouiller le stockage.');
        }
        return $callback($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function rotate_backups(string $type): void
{
    $keep = max(5, (int) (app_config()['backups_to_keep'] ?? 60));
    $files = glob(backup_dir($type) . '/*.json') ?: [];
    rsort($files, SORT_STRING);
    foreach (array_slice($files, $keep) as $stale) {
        @unlink($stale);
    }
}

function snapshot_existing_file(string $path, string $type): void
{
    if (!is_file($path)) {
        return;
    }
    $backup = backup_dir($type) . '/' . basename($path, '.json') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
    if (@copy($path, $backup)) {
        rotate_backups($type);
    }
}

function atomic_write_raw(string $path, string $contents): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d\'écrire les données.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Impossible de finaliser l\'écriture.');
    }
}

function atomic_json_write(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Erreur JSON.');
    }
    $type = basename($path, '.json');
    snapshot_existing_file($path, $type);
    atomic_write_raw($path, $json);
}

function json_read(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function load_records(string $type): array
{
    ensure_storage();
    return json_read(data_file($type));
}

function save_records(string $type, array $records): void
{
    ensure_storage();
    with_file_lock(data_lock_file($type), static function () use ($type, $records): void {
        atomic_json_write(data_file($type), array_values($records));
    });
}

function mutate_records(string $type, callable $callback)
{
    ensure_storage();
    return with_file_lock(data_lock_file($type), static function () use ($type, $callback) {
        $records = json_read(data_file($type));
        $result = $callback($records);
        atomic_json_write(data_file($type), array_values($records));
        return $result;
    });
}

function append_login_journal(array $payload): void
{
    ensure_storage();
    $payload['at'] = $payload['at'] ?? now_iso();
    $payload['ip'] = $payload['ip'] ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $payload['ua'] = $payload['ua'] ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    file_put_contents(login_journal_file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function login_journal_rows(): array
{
    ensure_storage();
    $rows = [];
    $fh = fopen(login_journal_file(), 'r');
    if ($fh === false) {
        return [];
    }
    while (($line = fgets($fh)) !== false) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    fclose($fh);
    usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));
    return $rows;
}

function response_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function download_login_journal_csv(): never
{
    $rows = [];
    foreach (login_journal_rows() as $row) {
        $rows[] = [
            (string) ($row['at'] ?? ''),
            (string) ($row['event'] ?? ''),
            (string) ($row['user_id'] ?? ''),
            (string) ($row['result'] ?? ''),
            (string) ($row['ip'] ?? ''),
            (string) ($row['ua'] ?? ''),
        ];
    }
    response_csv('journal_connexions.csv', ['Horodatage', 'Événement', 'Utilisateur', 'Résultat', 'IP', 'User-Agent'], $rows);
}

function session_start_if_needed(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('mak_admin_app');
        session_start();
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    session_start_if_needed();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    session_start_if_needed();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function users(): array
{
    return app_config()['users'];
}

function users_index(): array
{
    $index = [];
    foreach (users() as $user) {
        $index[$user['id']] = $user;
    }
    return $index;
}

function user_by_id(?string $id): ?array
{
    if ($id === null || $id === '') {
        return null;
    }
    $index = users_index();
    return $index[$id] ?? null;
}

function employees(): array
{
    return array_values(array_filter(users(), static fn(array $u): bool => ($u['role'] ?? '') === 'employee'));
}

function employee_by_id(?string $id): ?array
{
    $user = user_by_id($id);
    if (!$user || ($user['role'] ?? '') !== 'employee') {
        return null;
    }
    return $user;
}

function current_user(): ?array
{
    session_start_if_needed();
    return user_by_id($_SESSION['user_id'] ?? null);
}

function current_employee(): ?array
{
    $user = current_user();
    return ($user && ($user['role'] ?? '') === 'employee') ? $user : null;
}

function is_admin(): bool
{
    $user = current_user();
    return (bool) ($user && ($user['role'] ?? '') === 'admin');
}

function login_user(string $userId, string $password): bool
{
    $user = user_by_id($userId);
    $normalized = strtolower(trim($password));
    if (!$user) {
        append_login_journal(['event' => 'login', 'user_id' => $userId, 'result' => 'unknown_user']);
        return false;
    }
    if (!password_verify($normalized, (string) $user['password_hash'])) {
        append_login_journal(['event' => 'login', 'user_id' => $user['id'], 'result' => 'invalid_password']);
        return false;
    }
    session_start_if_needed();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    append_login_journal(['event' => 'login', 'user_id' => $user['id'], 'result' => 'success']);
    return true;
}

function logout_user(): void
{
    session_start_if_needed();
    $userId = (string) ($_SESSION['user_id'] ?? '');
    if ($userId !== '') {
        append_login_journal(['event' => 'logout', 'user_id' => $userId, 'result' => 'success']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string) $params['path'], (string) $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function require_login(?string $role = null): array
{
    $user = current_user();
    if (!$user) {
        redirect('index.php');
    }
    if ($role !== null && ($user['role'] ?? '') !== $role) {
        redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
    }
    return $user;
}

function employee_off_weekdays(string $employeeId): array
{
    $employee = employee_by_id($employeeId);
    return $employee['off_weekdays'] ?? [6, 7];
}

function employee_name(string $employeeId): string
{
    $employee = employee_by_id($employeeId);
    return $employee['name'] ?? $employeeId;
}

function employee_accent(string $employeeId): string
{
    $employee = employee_by_id($employeeId);
    return (string) ($employee['accent'] ?? app_config()['brand']['primary'] ?? '#1f3561');
}

function hex_to_rgb_triplet(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[A-Fa-f0-9]{6}$/', $hex)) {
        $hex = '1f3561';
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function tint_hex(string $hex, float $amount): string
{
    [$r, $g, $b] = hex_to_rgb_triplet($hex);
    $amount = max(0.0, min(1.0, $amount));
    $r = (int) round($r + (255 - $r) * $amount);
    $g = (int) round($g + (255 - $g) * $amount);
    $b = (int) round($b + (255 - $b) * $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function attachment_accept_attr(): string
{
    return 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic,.heif,.webp,.png,.jpg,.jpeg,.txt,.rtf,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain';
}

function cleanup_uploaded_files(array $attachments): void
{
    foreach ($attachments as $attachment) {
        $path = (string) ($attachment['path'] ?? '');
        if ($path === '') {
            continue;
        }
        $full = root_path($path);
        if (str_starts_with($full, root_path('storage/uploads/')) && is_file($full)) {
            @unlink($full);
        }
    }
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format(DATE_ATOM);
}

function sanitize_filename(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name) ?? 'file';
    $name = trim($name, '-. ');
    return $name !== '' ? $name : 'file';
}

function slugify(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
    return trim($value, '-') ?: 'item';
}

function generate_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function validate_date(?string $value): string
{
    $value = trim((string) $value);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Date invalide.');
    }
    return $value;
}

function validate_month(?string $value): string
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        throw new InvalidArgumentException('Mois invalide.');
    }
    return $value;
}

function normalize_amount(?string $value): string
{
    $value = str_replace(',', '.', trim((string) $value));
    if ($value === '' || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Montant invalide.');
    }
    return number_format((float) $value, 2, '.', '');
}

function money(string $value): string
{
    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function payment_modes(): array
{
    return ['cb' => 'CB', 'cash' => 'Espèce', 'transfer' => 'Virement'];
}

function leave_types(): array
{
    return ['paid' => 'Congés payés', 'sick' => 'Maladie', 'other' => 'Autres'];
}

function leave_type_label(string $type): string
{
    return leave_types()[$type] ?? $type;
}

function leave_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Accordé',
        'rejected' => 'Refusé',
        default => 'Demandé',
    };
}

function leave_status_class(string $status): string
{
    return match ($status) {
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        default => 'status-pending',
    };
}

function all_expenses(): array
{
    return load_records('expenses');
}

function all_tickets(): array
{
    return load_records('tickets');
}

function all_navigo(): array
{
    return load_records('navigo');
}

function all_leaves(): array
{
    return load_records('leaves');
}

function records_for_employee(string $type, string $employeeId): array
{
    $records = match ($type) {
        'expenses' => all_expenses(),
        'tickets' => all_tickets(),
        'navigo' => all_navigo(),
        'leaves' => all_leaves(),
        default => [],
    };
    $records = array_values(array_filter($records, static fn(array $row): bool => (string) ($row['employee_id'] ?? '') === $employeeId));
    usort($records, static function (array $a, array $b): int {
        $aDate = (string) ($a['date'] ?? $a['start_date'] ?? $a['month'] ?? '');
        $bDate = (string) ($b['date'] ?? $b['start_date'] ?? $b['month'] ?? '');
        return strcmp($bDate, $aDate);
    });
    return $records;
}

function find_record(string $type, string $id): ?array
{
    foreach (match ($type) {
        'expenses' => all_expenses(),
        'tickets' => all_tickets(),
        'navigo' => all_navigo(),
        'leaves' => all_leaves(),
        default => [],
    } as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

function replace_record(string $type, string $id, callable $callback): void
{
    mutate_records($type, static function (array &$records) use ($id, $callback): void {
        $updated = false;
        foreach ($records as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $records[$i] = $callback($row);
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            throw new RuntimeException('Élément introuvable.');
        }
    });
}

function delete_record(string $type, string $id): void
{
    $attachmentsToDelete = [];
    mutate_records($type, static function (array &$records) use ($id, $type, &$attachmentsToDelete): void {
        $found = false;
        $kept = [];
        foreach ($records as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $found = true;
                if ($type === 'expenses' && is_array($row['attachments'] ?? null)) {
                    $attachmentsToDelete = $row['attachments'];
                }
                continue;
            }
            $kept[] = $row;
        }
        if (!$found) {
            throw new RuntimeException('Élément introuvable.');
        }
        $records = $kept;
    });
    if ($attachmentsToDelete !== []) {
        cleanup_uploaded_files($attachmentsToDelete);
    }
}

function assert_can_access_record(array $user, string $type, array $record): void
{
    if (($user['role'] ?? '') === 'admin') {
        return;
    }
    if ((string) ($record['employee_id'] ?? '') !== (string) ($user['id'] ?? '')) {
        throw new RuntimeException('Accès refusé.');
    }
}

function upload_bucket(): string
{
    $bucket = date('Y/m');
    $path = root_path('storage/uploads/' . $bucket);
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Impossible de créer le dossier d\'upload.');
    }
    return $bucket;
}

function normalize_files_array(array $file): array
{
    $normalized = [];
    if (!isset($file['name'])) {
        return $normalized;
    }
    if (is_array($file['name'])) {
        foreach ($file['name'] as $i => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $file['type'][$i] ?? '',
                'tmp_name' => $file['tmp_name'][$i] ?? '',
                'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$i] ?? 0,
            ];
        }
        return $normalized;
    }
    $normalized[] = $file;
    return $normalized;
}

function store_uploaded_files(array $files): array
{
    $saved = [];
    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Échec de l\'upload.');
        }
        $name = sanitize_filename((string) $file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('Fichier d\'upload invalide.');
        }
        $bucket = upload_bucket();
        $stored = bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
        $targetRelative = 'storage/uploads/' . $bucket . '/' . $stored;
        $target = root_path($targetRelative);
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('Impossible de déplacer le fichier.');
        }
        $saved[] = [
            'original_name' => $name,
            'path' => $targetRelative,
            'size' => (int) ($file['size'] ?? 0),
        ];
    }
    return $saved;
}

function expense_row_attachments(string $rowKey): array
{
    if (!isset($_FILES['attachment_' . $rowKey])) {
        return [];
    }
    return store_uploaded_files(normalize_files_array($_FILES['attachment_' . $rowKey]));
}

function remove_files(array $attachments, array $removePaths): array
{
    $removePaths = array_values(array_filter(array_map('strval', $removePaths)));
    if ($removePaths === []) {
        return $attachments;
    }
    $kept = [];
    foreach ($attachments as $attachment) {
        $path = (string) ($attachment['path'] ?? '');
        if (in_array($path, $removePaths, true)) {
            $full = root_path($path);
            if (str_starts_with($full, root_path('storage/uploads/')) && is_file($full)) {
                @unlink($full);
            }
            continue;
        }
        $kept[] = $attachment;
    }
    return $kept;
}

function attachment_names(array $attachments): string
{
    $names = [];
    foreach ($attachments as $attachment) {
        $name = trim((string) ($attachment['original_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names ? implode(' | ', $names) : '—';
}

function save_expenses_batch(array $employee): int
{
    $rowIds = $_POST['row_id'] ?? [];
    if (!is_array($rowIds) || $rowIds === []) {
        throw new InvalidArgumentException('Aucune ligne à enregistrer.');
    }
    $dates = $_POST['date'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $ttc = $_POST['amount_ttc'] ?? [];
    $ht = $_POST['amount_ht'] ?? [];
    $payments = $_POST['payment_mode'] ?? [];

    $pendingRecords = [];
    $newAttachments = [];
    foreach ($rowIds as $i => $rowId) {
        $date = trim((string) ($dates[$i] ?? ''));
        $description = trim((string) ($descriptions[$i] ?? ''));
        $amountTtc = trim((string) ($ttc[$i] ?? ''));
        $amountHt = trim((string) ($ht[$i] ?? ''));
        $payment = (string) ($payments[$i] ?? '');
        if ($date === '' && $description === '' && $amountTtc === '' && $amountHt === '') {
            continue;
        }
        if (!array_key_exists($payment, payment_modes())) {
            cleanup_uploaded_files($newAttachments);
            throw new InvalidArgumentException('Mode de paiement invalide.');
        }
        $attachments = expense_row_attachments((string) $rowId);
        $newAttachments = array_merge($newAttachments, $attachments);
        $pendingRecords[] = [
            'id' => generate_id('exp'),
            'employee_id' => $employee['id'],
            'date' => validate_date($date),
            'description' => $description,
            'amount_ttc' => normalize_amount($amountTtc),
            'amount_ht' => normalize_amount($amountHt),
            'payment_mode' => $payment,
            'attachments' => $attachments,
            'created_at' => now_iso(),
            'updated_at' => now_iso(),
        ];
    }
    if ($pendingRecords === []) {
        throw new InvalidArgumentException('Aucune ligne exploitable.');
    }
    try {
        mutate_records('expenses', static function (array &$records) use ($pendingRecords): int {
            foreach ($pendingRecords as $row) {
                $records[] = $row;
            }
            return count($pendingRecords);
        });
    } catch (Throwable $e) {
        cleanup_uploaded_files($newAttachments);
        throw $e;
    }
    return count($pendingRecords);
}

function update_expense(array $user, string $id): void
{
    $record = find_record('expenses', $id);
    if (!$record) {
        throw new RuntimeException('Note de frais introuvable.');
    }
    assert_can_access_record($user, 'expenses', $record);
    $payment = (string) ($_POST['payment_mode'] ?? '');
    if (!array_key_exists($payment, payment_modes())) {
        throw new InvalidArgumentException('Mode de paiement invalide.');
    }
    $existing = is_array($record['attachments'] ?? null) ? $record['attachments'] : [];
    $existing = remove_files($existing, $_POST['remove_attachments'] ?? []);
    $new = expense_row_attachments('edit');
    $merged = array_merge($existing, $new);

    try {
        replace_record('expenses', $id, static function (array $row) use ($merged, $payment): array {
            $row['date'] = validate_date((string) ($_POST['date'] ?? ''));
            $row['description'] = trim((string) ($_POST['description'] ?? ''));
            $row['amount_ttc'] = normalize_amount((string) ($_POST['amount_ttc'] ?? ''));
            $row['amount_ht'] = normalize_amount((string) ($_POST['amount_ht'] ?? ''));
            $row['payment_mode'] = $payment;
            $row['attachments'] = $merged;
            $row['updated_at'] = now_iso();
            return $row;
        });
    } catch (Throwable $e) {
        cleanup_uploaded_files($new);
        throw $e;
    }
}

function save_ticket(array $employee): void
{
    $month = validate_month((string) ($_POST['month'] ?? ''));
    $eligible = max(0, (int) ($_POST['eligible_days'] ?? 0));
    $withdrawals = max(0, (int) ($_POST['withdrawals'] ?? 0));
    mutate_records('tickets', static function (array &$records) use ($employee, $month, $eligible, $withdrawals): void {
        $updated = false;
        foreach ($records as &$row) {
            if ((string) ($row['employee_id'] ?? '') === $employee['id'] && (string) ($row['month'] ?? '') === $month) {
                $row['eligible_days'] = $eligible;
                $row['withdrawals'] = $withdrawals;
                $row['updated_at'] = now_iso();
                $updated = true;
                break;
            }
        }
        unset($row);
        if (!$updated) {
            $records[] = [
                'id' => generate_id('tr'),
                'employee_id' => $employee['id'],
                'month' => $month,
                'eligible_days' => $eligible,
                'withdrawals' => $withdrawals,
                'created_at' => now_iso(),
                'updated_at' => now_iso(),
            ];
        }
    });
}

function update_ticket(array $user, string $id): void
{
    $record = find_record('tickets', $id);
    if (!$record) {
        throw new RuntimeException('Déclaration introuvable.');
    }
    assert_can_access_record($user, 'tickets', $record);
    replace_record('tickets', $id, static function (array $row): array {
        $row['month'] = validate_month((string) ($_POST['month'] ?? ''));
        $row['eligible_days'] = max(0, (int) ($_POST['eligible_days'] ?? 0));
        $row['withdrawals'] = max(0, (int) ($_POST['withdrawals'] ?? 0));
        $row['updated_at'] = now_iso();
        return $row;
    });
}

function save_navigo(array $employee): void
{
    $month = validate_month((string) ($_POST['month'] ?? ''));
    $hasNavigo = !empty($_POST['has_navigo']);
    mutate_records('navigo', static function (array &$records) use ($employee, $month, $hasNavigo): void {
        $updated = false;
        foreach ($records as &$row) {
            if ((string) ($row['employee_id'] ?? '') === $employee['id'] && (string) ($row['month'] ?? '') === $month) {
                $row['has_navigo'] = $hasNavigo;
                $row['updated_at'] = now_iso();
                $updated = true;
                break;
            }
        }
        unset($row);
        if (!$updated) {
            $records[] = [
                'id' => generate_id('nav'),
                'employee_id' => $employee['id'],
                'month' => $month,
                'has_navigo' => $hasNavigo,
                'created_at' => now_iso(),
                'updated_at' => now_iso(),
            ];
        }
    });
}

function update_navigo(array $user, string $id): void
{
    $record = find_record('navigo', $id);
    if (!$record) {
        throw new RuntimeException('Déclaration introuvable.');
    }
    assert_can_access_record($user, 'navigo', $record);
    replace_record('navigo', $id, static function (array $row): array {
        $row['month'] = validate_month((string) ($_POST['month'] ?? ''));
        $row['has_navigo'] = !empty($_POST['has_navigo']);
        $row['updated_at'] = now_iso();
        return $row;
    });
}

function leave_default_status(string $type): string
{
    return $type === 'paid' ? 'pending' : 'approved';
}

function save_leaves_batch(array $employee): int
{
    $rowIds = $_POST['row_id'] ?? [];
    if (!is_array($rowIds) || $rowIds === []) {
        throw new InvalidArgumentException('Aucune ligne à enregistrer.');
    }
    $types = $_POST['leave_type'] ?? [];
    $starts = $_POST['start_date'] ?? [];
    $ends = $_POST['end_date'] ?? [];

    $pendingRecords = [];
    foreach ($rowIds as $i => $rowId) {
        $type = (string) ($types[$i] ?? '');
        $start = trim((string) ($starts[$i] ?? ''));
        $end = trim((string) ($ends[$i] ?? ''));
        if ($type === '' && $start === '' && $end === '') {
            continue;
        }
        if (!array_key_exists($type, leave_types())) {
            throw new InvalidArgumentException('Type de congé invalide.');
        }
        $start = validate_date($start);
        $end = validate_date($end);
        if ($end < $start) {
            throw new InvalidArgumentException('La date de fin doit être postérieure ou égale à la date de début.');
        }
        $pendingRecords[] = [
            'id' => generate_id('leave'),
            'employee_id' => $employee['id'],
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'estimated_days' => business_days_between($start, $end, employee_off_weekdays($employee['id'])),
            'status' => leave_default_status($type),
            'admin_comment' => '',
            'created_at' => now_iso(),
            'updated_at' => now_iso(),
        ];
    }
    if ($pendingRecords === []) {
        throw new InvalidArgumentException('Aucune ligne exploitable.');
    }
    mutate_records('leaves', static function (array &$records) use ($pendingRecords): void {
        foreach ($pendingRecords as $row) {
            $records[] = $row;
        }
    });
    return count($pendingRecords);
}

function update_leave(array $user, string $id): void
{
    $record = find_record('leaves', $id);
    if (!$record) {
        throw new RuntimeException('Congé introuvable.');
    }
    assert_can_access_record($user, 'leaves', $record);
    $type = (string) ($_POST['leave_type'] ?? '');
    if (!array_key_exists($type, leave_types())) {
        throw new InvalidArgumentException('Type de congé invalide.');
    }
    $targetEmployeeId = is_admin() ? (string) ($_POST['employee_id'] ?? $record['employee_id']) : (string) $record['employee_id'];
    if (!employee_by_id($targetEmployeeId)) {
        throw new InvalidArgumentException('Employé invalide.');
    }
    $start = validate_date((string) ($_POST['start_date'] ?? ''));
    $end = validate_date((string) ($_POST['end_date'] ?? ''));
    if ($end < $start) {
        throw new InvalidArgumentException('La date de fin doit être postérieure ou égale à la date de début.');
    }
    $estimated = business_days_between($start, $end, employee_off_weekdays($targetEmployeeId));
    replace_record('leaves', $id, static function (array $row) use ($type, $start, $end, $estimated, $targetEmployeeId): array {
        $row['employee_id'] = $targetEmployeeId;
        $row['leave_type'] = $type;
        $row['start_date'] = $start;
        $row['end_date'] = $end;
        $row['estimated_days'] = $estimated;
        if (is_admin()) {
            $status = (string) ($_POST['status'] ?? $row['status']);
            if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $status = $row['status'];
            }
            $row['status'] = $status;
            $row['admin_comment'] = trim((string) ($_POST['admin_comment'] ?? ''));
        } elseif ($type !== 'paid') {
            $row['status'] = 'approved';
        }
        $row['updated_at'] = now_iso();
        return $row;
    });
}

function set_leave_status(string $id, string $status, string $comment): void
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Statut invalide.');
    }
    replace_record('leaves', $id, static function (array $row) use ($status, $comment): array {
        $row['status'] = $status;
        $row['admin_comment'] = $comment;
        $row['updated_at'] = now_iso();
        return $row;
    });
}

function default_ticket_days_for_month(string $employeeId, string $month): int
{
    [$year, $mon] = array_map('intval', explode('-', validate_month($month)));
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    $end = $start->modify('last day of this month');
    return business_days_between($start->format('Y-m-d'), $end->format('Y-m-d'), employee_off_weekdays($employeeId));
}

function easter_sunday_ymd(int $year): string
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function french_public_holidays(int $year): array
{
    $fixed = [
        sprintf('%04d-01-01', $year),
        sprintf('%04d-05-01', $year),
        sprintf('%04d-05-08', $year),
        sprintf('%04d-07-14', $year),
        sprintf('%04d-08-15', $year),
        sprintf('%04d-11-01', $year),
        sprintf('%04d-11-11', $year),
        sprintf('%04d-12-25', $year),
    ];
    $easter = new DateTimeImmutable(easter_sunday_ymd($year));
    $mobile = [
        $easter->modify('+1 day')->format('Y-m-d'),
        $easter->modify('+39 days')->format('Y-m-d'),
        $easter->modify('+50 days')->format('Y-m-d'),
    ];
    $days = array_values(array_unique(array_merge($fixed, $mobile)));
    sort($days);
    return $days;
}

function date_is_public_holiday(string $date): bool
{
    return in_array($date, french_public_holidays((int) substr($date, 0, 4)), true);
}

function business_days_between(string $startDate, string $endDate, array $offWeekdays): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $days = 0;
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $weekday = (int) $cursor->format('N');
        $date = $cursor->format('Y-m-d');
        if (in_array($weekday, $offWeekdays, true)) {
            continue;
        }
        if (date_is_public_holiday($date)) {
            continue;
        }
        $days++;
    }
    return $days;
}

function team_calendar_month(?string $requested = null): string
{
    $value = $requested !== null ? $requested : (string) ($_GET['month'] ?? date('Y-m'));
    try {
        return validate_month($value);
    } catch (Throwable) {
        return date('Y-m');
    }
}

function month_calendar(string $month): array
{
    $month = validate_month($month);
    [$year, $mon] = array_map('intval', explode('-', $month));
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    $last = $first->modify('last day of this month');
    $gridStart = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
    $gridEnd = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');

    $leavesByDate = [];
    foreach (all_leaves() as $leave) {
        $start = new DateTimeImmutable((string) $leave['start_date']);
        $end = new DateTimeImmutable((string) $leave['end_date']);
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $leavesByDate[$date][] = $leave;
        }
    }

    $weeks = [];
    $currentWeek = [];
    for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        $currentWeek[] = [
            'date' => $date,
            'day' => (int) $cursor->format('j'),
            'weekday' => (int) $cursor->format('N'),
            'in_month' => $cursor->format('Y-m') === $month,
            'holiday' => date_is_public_holiday($date),
            'entries' => $leavesByDate[$date] ?? [],
        ];
        if (count($currentWeek) === 7) {
            $weeks[] = $currentWeek;
            $currentWeek = [];
        }
    }

    return [
        'month' => $month,
        'label' => month_label_fr($month),
        'prev' => $first->modify('-1 month')->format('Y-m'),
        'next' => $first->modify('+1 month')->format('Y-m'),
        'weeks' => $weeks,
    ];
}

function month_label_fr(string $month): string
{
    [$year, $mon] = array_map('intval', explode('-', $month));
    $labels = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
    return ($labels[$mon] ?? $month) . ' ' . $year;
}

function leave_chip_label(array $leave): string
{
    $first = explode(' ', employee_name((string) $leave['employee_id']))[0] ?? '';
    return $first;
}

function pending_leaves(): array
{
    return array_values(array_filter(all_leaves(), static fn(array $row): bool => (string) ($row['status'] ?? '') === 'pending'));
}

function notes_total_for_employee(string $employeeId): array
{
    $ttc = 0.0;
    $ht = 0.0;
    foreach (records_for_employee('expenses', $employeeId) as $row) {
        $ttc += (float) ($row['amount_ttc'] ?? 0);
        $ht += (float) ($row['amount_ht'] ?? 0);
    }
    $tva = $ttc - $ht;
    return [
        'ttc' => number_format($ttc, 2, '.', ''),
        'ht' => number_format($ht, 2, '.', ''),
        'tva' => number_format($tva, 2, '.', ''),
    ];
}

function employee_summary_for_month(string $employeeId, string $month): array
{
    $ticket = null;
    foreach (records_for_employee('tickets', $employeeId) as $row) {
        if ((string) ($row['month'] ?? '') === $month) {
            $ticket = $row;
            break;
        }
    }
    $navigo = null;
    foreach (records_for_employee('navigo', $employeeId) as $row) {
        if ((string) ($row['month'] ?? '') === $month) {
            $navigo = $row;
            break;
        }
    }
    return ['ticket' => $ticket, 'navigo' => $navigo];
}

function transport_count(array $ticket): int
{
    $eligible = max(0, (int) ($ticket['eligible_days'] ?? 0));
    $withdrawals = max(0, (int) ($ticket['withdrawals'] ?? 0));
    return max(0, $eligible - $withdrawals);
}

function month_start_end(string $month): array
{
    $month = validate_month($month);
    [$year, $mon] = array_map('intval', explode('-', $month));
    $start = sprintf('%04d-%02d-01', $year, $mon);
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    return [$start, $end];
}

function leave_days_in_month(string $employeeId, string $month): int
{
    [$monthStart, $monthEnd] = month_start_end($month);
    $days = [];
    foreach (records_for_employee('leaves', $employeeId) as $leave) {
        if ((string) ($leave['status'] ?? '') === 'rejected') {
            continue;
        }
        $start = max($monthStart, (string) ($leave['start_date'] ?? ''));
        $end = min($monthEnd, (string) ($leave['end_date'] ?? ''));
        if ($end < $start) {
            continue;
        }
        $cursor = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
        for (; $cursor <= $last; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $weekday = (int) $cursor->format('N');
            if (in_array($weekday, employee_off_weekdays($employeeId), true) || date_is_public_holiday($date)) {
                continue;
            }
            $days[$date] = true;
        }
    }
    return count($days);
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function word_rgb(string $hex, string $fallback = '111111'): string
{
    $hex = trim($hex);
    if ($hex === '') {
        return strtoupper($fallback);
    }
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[A-Fa-f0-9]{6}$/', $hex)) {
        return strtoupper($fallback);
    }
    return strtoupper($hex);
}

function word_text_run(string $text, array $options = []): string
{
    $parts = [];
    $font = (string) ($options['font'] ?? 'Arial');
    $size = (int) ($options['size'] ?? 22);
    $color = word_rgb((string) ($options['color'] ?? '#111111'));
    $parts[] = '<w:rFonts w:ascii="' . xml_escape($font) . '" w:hAnsi="' . xml_escape($font) . '" w:eastAsia="' . xml_escape($font) . '"/>';
    $parts[] = '<w:sz w:val="' . $size . '"/>';
    $parts[] = '<w:szCs w:val="' . $size . '"/>';
    $parts[] = '<w:color w:val="' . $color . '"/>';
    if (!empty($options['bold'])) {
        $parts[] = '<w:b/>';
        $parts[] = '<w:bCs/>';
    }
    if (!empty($options['caps'])) {
        $parts[] = '<w:caps/>';
    }
    if (!empty($options['italic'])) {
        $parts[] = '<w:i/>';
        $parts[] = '<w:iCs/>';
    }
    if (!empty($options['small_caps'])) {
        $parts[] = '<w:smallCaps/>';
    }
    if (!empty($options['underline'])) {
        $parts[] = '<w:u w:val="single"/>';
    }
    if (isset($options['spacing'])) {
        $parts[] = '<w:spacing w:val="' . (int) $options['spacing'] . '"/>';
    }
    return '<w:r><w:rPr>' . implode('', $parts) . '</w:rPr><w:t xml:space="preserve">' . xml_escape($text) . '</w:t></w:r>';
}

function word_paragraph(array $runs, array $options = []): string
{
    $pPr = [];
    $align = (string) ($options['align'] ?? 'left');
    $jcMap = ['left' => 'left', 'center' => 'center', 'right' => 'right'];
    $pPr[] = '<w:jc w:val="' . ($jcMap[$align] ?? 'left') . '"/>';
    $before = (int) ($options['space_before'] ?? 0);
    $after = (int) ($options['space_after'] ?? 0);
    $line = (int) ($options['line'] ?? 276);
    $pPr[] = '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $line . '" w:lineRule="auto"/>';
    if (!empty($options['keep_next'])) {
        $pPr[] = '<w:keepNext/>';
    }
    if (!empty($options['page_break_before'])) {
        $pPr[] = '<w:pageBreakBefore/>';
    }
    if (!empty($options['border_bottom'])) {
        $color = word_rgb((string) ($options['border_bottom_color'] ?? '#d7d7d7'));
        $pPr[] = '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="6" w:color="' . $color . '"/></w:pBdr>';
    }
    $xmlRuns = '';
    foreach ($runs as $run) {
        $xmlRuns .= word_text_run((string) ($run['text'] ?? ''), $run);
    }
    return '<w:p><w:pPr>' . implode('', $pPr) . '</w:pPr>' . $xmlRuns . '</w:p>';
}

function word_single_paragraph(string $text, array $options = []): string
{
    $run = [
        'text' => $text,
        'font' => $options['font'] ?? 'Arial',
        'size' => $options['size'] ?? 22,
        'color' => $options['color'] ?? '#111111',
        'bold' => $options['bold'] ?? false,
        'caps' => $options['caps'] ?? false,
        'italic' => $options['italic'] ?? false,
        'small_caps' => $options['small_caps'] ?? false,
        'spacing' => $options['tracking'] ?? null,
    ];
    return word_paragraph([$run], $options);
}

function word_empty_paragraph(int $after = 80): string
{
    return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/></w:pPr></w:p>';
}

function word_cell(array $paragraphs, array $options = []): string
{
    $width = (int) ($options['width'] ?? 0);
    $fill = isset($options['fill']) ? word_rgb((string) $options['fill']) : null;
    $align = (string) ($options['align'] ?? 'left');
    $valign = (string) ($options['valign'] ?? 'center');
    $borderColor = word_rgb((string) ($options['border_color'] ?? '#d7d7d7'));
    $margins = $options['margins'] ?? ['top' => 90, 'left' => 120, 'bottom' => 90, 'right' => 120];
    $tcPr = [];
    if ($width > 0) {
        $tcPr[] = '<w:tcW w:w="' . $width . '" w:type="dxa"/>';
    }
    if ($fill) {
        $tcPr[] = '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>';
    }
    $tcPr[] = '<w:vAlign w:val="' . xml_escape($valign) . '"/>';
    $tcPr[] = '<w:tcBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '</w:tcBorders>';
    $tcPr[] = '<w:tcMar>'
        . '<w:top w:w="' . (int) ($margins['top'] ?? 90) . '" w:type="dxa"/>'
        . '<w:left w:w="' . (int) ($margins['left'] ?? 120) . '" w:type="dxa"/>'
        . '<w:bottom w:w="' . (int) ($margins['bottom'] ?? 90) . '" w:type="dxa"/>'
        . '<w:right w:w="' . (int) ($margins['right'] ?? 120) . '" w:type="dxa"/>'
        . '</w:tcMar>';

    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= $paragraph;
    }
    if ($body === '') {
        $body = word_single_paragraph('', ['size' => 18]);
    }
    return '<w:tc><w:tcPr>' . implode('', $tcPr) . '</w:tcPr>' . $body . '</w:tc>';
}

function word_table(array $rows, array $options = []): string
{
    $tableWidth = (int) ($options['width'] ?? 10400);
    $layout = (string) ($options['layout'] ?? 'fixed');
    $borderColor = word_rgb((string) ($options['border_color'] ?? '#d7d7d7'));
    $cellSpacing = (int) ($options['cell_spacing'] ?? 0);
    $tbl = '<w:tbl><w:tblPr>'
        . '<w:tblW w:w="' . $tableWidth . '" w:type="dxa"/>'
        . '<w:tblLayout w:type="' . xml_escape($layout) . '"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="' . $borderColor . '"/>'
        . '</w:tblBorders>';
    if ($cellSpacing > 0) {
        $tbl .= '<w:tblCellSpacing w:w="' . $cellSpacing . '" w:type="dxa"/>';
    }
    $tbl .= '</w:tblPr>';
    foreach ($rows as $rowIndex => $row) {
        $tbl .= '<w:tr>' . (!empty($options['repeat_header']) && $rowIndex === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach ($row as $cell) {
            $tbl .= word_cell((array) ($cell['paragraphs'] ?? []), $cell);
        }
        $tbl .= '</w:tr>';
    }
    $tbl .= '</w:tbl>';
    return $tbl;
}

function zip_store(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', $name);
        $content = (string) $content;
        $crc = crc32($content);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $size = strlen($content);
        $dosTime = 0;
        $dosDate = 33;
        $local = pack('VvvvvvVVVvv',
            0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0
        ) . $name . $content;
        $data .= $local;
        $central .= pack('VvvvvvvVVVvvvvvVV',
            0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size,
            strlen($name), 0, 0, 0, 0, 32, $offset
        ) . $name;
        $offset += strlen($local);
    }
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    return $data . $central . $eocd;
}

function docx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults>'
        . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="111111"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
        . '</w:docDefaults>'
        . '</w:styles>';
}

function docx_logo_binary(): ?string
{
    $path = root_path((string) (app_config()['docx_logo_path'] ?? 'app/assets/logo_mak.png'));
    return is_file($path) ? file_get_contents($path) ?: null : null;
}

function word_page_break_paragraph(): string
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

function word_image_paragraph(string $relId, string $filename, int $cx, int $cy, array $options = []): string
{
    $align = (string) ($options['align'] ?? 'left');
    $before = (int) ($options['space_before'] ?? 0);
    $after = (int) ($options['space_after'] ?? 140);
    $docPrId = (int) ($options['docpr_id'] ?? 1);
    $pPr = '<w:pPr><w:jc w:val="' . xml_escape($align) . '"/><w:spacing w:before="' . $before . '" w:after="' . $after . '"/></w:pPr>';
    return '<w:p>' . $pPr
        . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
        . '<wp:docPr id="' . $docPrId . '" name="' . xml_escape($filename) . '"/>'
        . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic>'
        . '<pic:nvPicPr><pic:cNvPr id="0" name="' . xml_escape($filename) . '"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . xml_escape($relId) . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic>'
        . '</wp:inline></w:drawing></w:r></w:p>';
}

function docx_shell_command_available(string $command): bool
{
    $command = preg_replace('/[^a-z0-9_\-]/i', '', $command) ?? '';
    if ($command === '' || !function_exists('shell_exec')) {
        return false;
    }
    $result = @shell_exec('command -v ' . $command . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

function docx_temp_dir(string $prefix = 'mak_docx_'): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    for ($i = 0; $i < 5; $i++) {
        $dir = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        if (@mkdir($dir, 0700, true)) {
            return $dir;
        }
    }
    throw new RuntimeException('Impossible de créer un dossier temporaire DOCX.');
}

function docx_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            docx_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function docx_media_from_binary(string $binary, string $preferredFilename = 'preview'): ?array
{
    if ($binary === '') {
        return null;
    }
    $size = @getimagesizefromstring($binary);
    if (!is_array($size) || empty($size[0]) || empty($size[1])) {
        return null;
    }
    $mime = strtolower((string) ($size['mime'] ?? ''));
    $map = [
        'image/jpeg' => ['ext' => 'jpeg', 'content_type' => 'image/jpeg'],
        'image/png' => ['ext' => 'png', 'content_type' => 'image/png'],
        'image/gif' => ['ext' => 'gif', 'content_type' => 'image/gif'],
        'image/bmp' => ['ext' => 'bmp', 'content_type' => 'image/bmp'],
    ];
    if (!isset($map[$mime])) {
        return null;
    }
    $ratio = ((int) $size[0]) / max(1, (int) $size[1]);
    return [
        'binary' => $binary,
        'width_px' => (int) $size[0],
        'height_px' => (int) $size[1],
        'extension' => $map[$mime]['ext'],
        'content_type' => $map[$mime]['content_type'],
        'layout' => $ratio <= 0.82 ? 'half' : 'full',
        'filename' => $preferredFilename,
    ];
}

function docx_image_media_from_attachment(array $attachment): ?array
{
    $relative = (string) ($attachment['path'] ?? '');
    if ($relative === '') {
        return null;
    }
    $full = root_path($relative);
    if (!is_file($full)) {
        return null;
    }
    $binary = file_get_contents($full);
    if ($binary === false || $binary === '') {
        return null;
    }
    return docx_media_from_binary($binary, (string) ($attachment['original_name'] ?? 'image'));
}

function docx_pdf_preview_pages_from_attachment(array $attachment): array
{
    $relative = (string) ($attachment['path'] ?? '');
    if ($relative === '') {
        return [];
    }
    $full = root_path($relative);
    if (!is_file($full)) {
        return [];
    }

    $tmpDir = docx_temp_dir('mak_pdf_preview_');
    $pages = [];
    try {
        $generated = false;
        if (docx_shell_command_available('pdftoppm')) {
            $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
            $cmd = 'pdftoppm -png -r 144 ' . escapeshellarg($full) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
            @shell_exec($cmd);
            $generated = count(glob($prefix . '-*.png') ?: []) > 0;
        }
        if (!$generated && docx_shell_command_available('gs')) {
            $pattern = $tmpDir . DIRECTORY_SEPARATOR . 'page-%03d.png';
            $cmd = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r144 -o ' . escapeshellarg($pattern) . ' ' . escapeshellarg($full) . ' 2>/dev/null';
            @shell_exec($cmd);
            $generated = count(glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.png') ?: []) > 0;
        }
        if (!$generated) {
            return [];
        }
        $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.png') ?: [];
        natsort($files);
        $total = count($files);
        $baseName = pathinfo((string) ($attachment['original_name'] ?? 'document.pdf'), PATHINFO_FILENAME) ?: 'document';
        $pageNo = 1;
        foreach ($files as $file) {
            $binary = @file_get_contents($file);
            if (!is_string($binary) || $binary === '') {
                $pageNo++;
                continue;
            }
            $media = docx_media_from_binary($binary, $baseName . ' — page ' . $pageNo . '.png');
            if ($media !== null) {
                $media['page_number'] = $pageNo;
                $media['page_total'] = $total;
                $pages[] = $media;
            }
            $pageNo++;
        }
    } finally {
        docx_rrmdir($tmpDir);
    }
    return $pages;
}

function docx_appendix_from_attachment(array $attachment, int $annex): array
{
    $label = 'Annexe ' . $annex;
    $filename = (string) ($attachment['original_name'] ?? ('piece-' . $annex));
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $image = docx_image_media_from_attachment($attachment);
    if ($image !== null) {
        return [
            'title' => $label,
            'filename' => $filename,
            'type' => 'preview',
            'source_type' => 'image',
            'preview_pages' => [$image],
        ];
    }

    if ($ext === 'pdf') {
        $pages = docx_pdf_preview_pages_from_attachment($attachment);
        if ($pages !== []) {
            return [
                'title' => $label,
                'filename' => $filename,
                'type' => 'preview',
                'source_type' => 'pdf',
                'preview_pages' => $pages,
            ];
        }
    }

    return [
        'title' => $label,
        'filename' => $filename,
        'type' => 'file',
        'source_type' => $ext === 'pdf' ? 'pdf' : 'file',
        'preview_pages' => [],
    ];
}

function docx_fit_image_emu(int $widthPx, int $heightPx, int $maxWidthEmu, int $maxHeightEmu): array
{
    $widthPx = max(1, $widthPx);
    $heightPx = max(1, $heightPx);
    $emuPerPx = 9525;
    $cx = $widthPx * $emuPerPx;
    $cy = $heightPx * $emuPerPx;
    $scale = min($maxWidthEmu / $cx, $maxHeightEmu / $cy, 1.0);
    return [
        max(1, (int) floor($cx * $scale)),
        max(1, (int) floor($cy * $scale)),
    ];
}

function docx_page_content_box_emu(): array
{
    $pageWidthTwips = 11906;
    $pageHeightTwips = 16838;
    $marginLeftTwips = 820;
    $marginRightTwips = 820;
    $marginTopTwips = 820;
    $marginBottomTwips = 820;
    $emuPerTwip = 635;

    $usableWidthEmu = (int) (($pageWidthTwips - $marginLeftTwips - $marginRightTwips) * $emuPerTwip);
    $usableHeightEmu = (int) (($pageHeightTwips - $marginTopTwips - $marginBottomTwips) * $emuPerTwip);

    return [$usableWidthEmu, $usableHeightEmu];
}

function notes_docx_bundle(string $employeeId): array
{
    $rows = [];
    $appendices = [];
    $annex = 1;
    foreach (records_for_employee('expenses', $employeeId) as $row) {
        $refs = [];
        foreach ((array) ($row['attachments'] ?? []) as $attachment) {
            $refs[] = 'Annexe ' . $annex;
            $appendices[] = docx_appendix_from_attachment((array) $attachment, $annex);
            $annex++;
        }
        $rows[] = [
            (string) ($row['date'] ?? ''),
            (string) ($row['description'] ?? ''),
            money((string) ($row['amount_ttc'] ?? '0')),
            money((string) ($row['amount_ht'] ?? '0')),
            payment_modes()[(string) ($row['payment_mode'] ?? '')] ?? '',
            $refs ? implode(' · ', $refs) : '—',
        ];
    }
    $totals = notes_total_for_employee($employeeId);
    return [
        'table' => [
            'headers' => ['Date', 'Descriptif', 'TTC', 'HT', 'Paiement', 'Justificatif'],
            'rows' => $rows,
            'widths' => [1200, 3600, 1100, 1100, 1200, 2200],
        ],
        'summary' => [
            ['Total TTC', money($totals['ttc'])],
            ['Total HT', money($totals['ht'])],
            ['Total TVA', money($totals['tva'])],
        ],
        'appendices' => $appendices,
    ];
}

function docx_document(array $spec): string
{
    $config = app_config();
    $brand = $config['brand'] ?? [];
    $primary = word_rgb((string) ($brand['primary'] ?? '#1f3561'));
    $ink = word_rgb((string) ($brand['ink'] ?? '#111111'));
    $line = word_rgb((string) ($brand['line'] ?? '#d7d7d7'));
    $soft = word_rgb((string) ($brand['soft'] ?? '#f4f4f4'));
    $gallery = (string) ($config['gallery_name'] ?? 'MAK Galerie');
    $logoBinary = docx_logo_binary();

    $title = (string) ($spec['title'] ?? 'Document');
    $subtitle = (string) ($spec['subtitle'] ?? '');
    $meta = is_array($spec['meta'] ?? null) ? $spec['meta'] : [];
    $summary = is_array($spec['summary'] ?? null) ? $spec['summary'] : [];
    $table = is_array($spec['table'] ?? null) ? $spec['table'] : [];
    $notes = is_array($spec['notes'] ?? null) ? $spec['notes'] : [];
    $appendices = is_array($spec['appendices'] ?? null) ? $spec['appendices'] : [];

    $mediaParts = [];
    $nextRel = 2;
    $nextDocPr = 2;
    $logoRel = null;
    if ($logoBinary !== null) {
        $logoRel = 'rId' . $nextRel++;
        $mediaParts[] = [
            'rel_id' => $logoRel,
            'target' => 'media/logo_mak.png',
            'content_type' => 'image/png',
            'binary' => $logoBinary,
        ];
    }
    foreach ($appendices as $index => $appendix) {
        $pages = is_array($appendix['preview_pages'] ?? null) ? $appendix['preview_pages'] : [];
        foreach ($pages as $pageIndex => $media) {
            if (!is_array($media)) {
                continue;
            }
            $relId = 'rId' . $nextRel++;
            $docPrId = $nextDocPr++;
            $target = 'media/annexe_' . ($index + 1) . '_p' . ($pageIndex + 1) . '.' . (string) ($media['extension'] ?? 'jpeg');
            $appendices[$index]['preview_pages'][$pageIndex]['rel_id'] = $relId;
            $appendices[$index]['preview_pages'][$pageIndex]['docpr_id'] = $docPrId;
            $appendices[$index]['preview_pages'][$pageIndex]['target'] = $target;
            $mediaParts[] = [
                'rel_id' => $relId,
                'target' => $target,
                'content_type' => (string) ($media['content_type'] ?? 'image/jpeg'),
                'binary' => (string) ($media['binary'] ?? ''),
            ];
        }
    }

    $body = '';
    $v2Layout = (string) ($spec['layout_v2'] ?? (($spec['title'] ?? '') === 'Notes de frais' ? 'expenses' : ''));
    if ($v2Layout !== '' && function_exists('rh2_docx_body')) {
        $body = rh2_docx_body(array_replace($spec, ['layout_v2' => $v2Layout]), $logoRel);
    } else {
    if ($logoRel !== null) {
        $body .= word_image_paragraph($logoRel, 'logo_mak.png', 792000, 792000, ['space_after' => 120, 'docpr_id' => 1]);
    } else {
        $body .= word_single_paragraph($gallery, [
            'size' => 16,
            'color' => '#' . $primary,
            'bold' => true,
            'caps' => true,
            'tracking' => 120,
            'space_after' => 70,
            'keep_next' => true,
        ]);
    }
    $body .= word_single_paragraph('', [
        'border_bottom' => true,
        'border_bottom_color' => '#' . $primary,
        'space_after' => 240,
    ]);
    $body .= word_single_paragraph($title, [
        'size' => 34,
        'color' => '#' . $ink,
        'bold' => true,
        'space_after' => 40,
        'keep_next' => true,
    ]);
    if ($subtitle !== '') {
        if (str_contains($subtitle, ', le ')) {
            [$namePart, $datePart] = explode(', le ', $subtitle, 2);
            $body .= word_paragraph([
                ['text' => $namePart, 'size' => 22, 'color' => '#' . $primary],
                ['text' => ', le ' . $datePart, 'size' => 22, 'color' => '#' . $ink],
            ], ['space_after' => 180, 'keep_next' => true]);
        } else {
            $body .= word_single_paragraph($subtitle, [
                'size' => 22,
                'color' => '#' . $primary,
                'space_after' => 180,
                'keep_next' => true,
            ]);
        }
    }

    if ($meta !== []) {
        $metaRows = [];
        foreach ($meta as $item) {
            $metaRows[] = [
                ['width' => 2100, 'fill' => '#' . $soft, 'paragraphs' => [word_single_paragraph((string) ($item[0] ?? ''), ['size' => 16, 'bold' => true, 'caps' => true, 'tracking' => 55])]],
                ['width' => 5200, 'paragraphs' => [word_single_paragraph((string) ($item[1] ?? ''), ['size' => 18])]],
            ];
        }
        $body .= word_table($metaRows, ['width' => 7300, 'border_color' => '#' . $line]);
        $body .= word_empty_paragraph(180);
    }

    if ($summary !== []) {
        $summaryRows = [[]];
        foreach ($summary as $item) {
            $summaryRows[0][] = [
                'width' => 2400,
                'fill' => '#' . $soft,
                'paragraphs' => [
                    word_single_paragraph((string) ($item[0] ?? ''), ['size' => 14, 'caps' => true, 'tracking' => 55, 'color' => '#' . $primary, 'bold' => true, 'align' => 'center', 'space_after' => 40]),
                    word_single_paragraph((string) ($item[1] ?? ''), ['size' => 22, 'bold' => true, 'align' => 'center', 'space_after' => 10]),
                ],
            ];
        }
        $body .= word_table($summaryRows, ['width' => 10400, 'border_color' => '#' . $line, 'cell_spacing' => 70]);
        $body .= word_empty_paragraph(220);
    }

    if ($table !== []) {
        $tableTitle = (string) ($table['title'] ?? 'Détail');
        if ($tableTitle !== '') {
            $body .= word_single_paragraph($tableTitle, ['size' => 18, 'bold' => true, 'caps' => true, 'tracking' => 55, 'color' => '#' . $primary, 'space_after' => 80]);
        }
        $rowsXml = [];
        $headerRow = [];
        foreach (($table['headers'] ?? []) as $i => $header) {
            $headerRow[] = [
                'width' => (int) (($table['widths'][$i] ?? 1600)),
                'fill' => '#' . $primary,
                'border_color' => '#' . $primary,
                'paragraphs' => [word_single_paragraph((string) $header, ['size' => (int) ($table['font_size'] ?? 16), 'bold' => true, 'color' => '#ffffff', 'align' => 'center', 'space_after' => 10])],
                'align' => 'center',
            ];
        }
        $rowsXml[] = $headerRow;
        foreach (($table['rows'] ?? []) as $rowIndex => $row) {
            $isTotal = !empty($table['total_row']) && $rowIndex === count($table['rows']) - 1;
            $fill = $isTotal || $rowIndex % 2 !== 0 ? '#' . $soft : '#ffffff';
            $cells = [];
            foreach ((array) $row as $i => $cellValue) {
                $align = in_array($i, $table['left_columns'] ?? [1], true) ? 'left' : 'center';
                $paragraphs = [];
                $lines = is_array($cellValue) ? $cellValue : [['text' => (string) $cellValue]];
                foreach ($lines as $item) {
                    $paragraphs[] = word_single_paragraph((string) $item['text'], [
                        'size' => (int) ($item['size'] ?? $table['font_size'] ?? 16), 'align' => $align,
                        'space_after' => (int) ($item['space_after'] ?? 20),
                        'color' => (string) ($item['color'] ?? '#111111'),
                        'keep_next' => !empty($item['keep_next']),
                        'bold' => $isTotal || !empty($item['bold']),
                    ]);
                }
                $cells[] = [
                    'width' => (int) (($table['widths'][$i] ?? 1600)),
                    'fill' => $fill,
                    'border_color' => '#' . $line,
                    'paragraphs' => $paragraphs,
                    'align' => $align,
                    'margins' => $table['cell_margins'] ?? ['top' => 90, 'left' => 120, 'bottom' => 90, 'right' => 120],
                ];
            }
            $rowsXml[] = $cells;
        }
        $body .= word_table($rowsXml, ['width' => (int) ($table['width'] ?? 10400), 'border_color' => '#' . $line, 'repeat_header' => !empty($table['repeat_header'])]);
        $body .= word_empty_paragraph(180);
    }

    foreach ($notes as $note) {
        if ((string) $note === '') {
            continue;
        }
        $body .= word_single_paragraph((string) $note, [
            'size' => 16,
            'color' => '#666666',
            'italic' => true,
            'space_after' => 40,
        ]);
    }

    // Optional independent tables for monthly HR reporting only.
    foreach (($spec['sections'] ?? []) as $section) {
        $extraTable = $section['table'];
        $body .= word_single_paragraph((string) $section['title'], [
            'size' => 28, 'bold' => true, 'color' => '#' . $primary,
            'page_break_before' => true, 'keep_next' => true, 'space_after' => 140,
        ]);
        $body .= word_single_paragraph((string) $section['intro'], ['size' => 20, 'space_after' => 220, 'keep_next' => true]);
        $extraRows = [];
        $headerRow = [];
        foreach ($extraTable['headers'] as $i => $header) {
            $headerRow[] = ['width' => $extraTable['widths'][$i], 'fill' => '#' . $primary, 'border_color' => '#' . $primary,
                'paragraphs' => [word_single_paragraph($header, ['size' => 19, 'bold' => true, 'color' => '#ffffff', 'align' => $i === 2 ? 'center' : 'left'])]];
        }
        $extraRows[] = $headerRow;
        foreach ($extraTable['rows'] as $j => $row) {
            $last = $j === count($extraTable['rows']) - 1;
            $cells = [];
            foreach ($row as $i => $value) {
                $cells[] = ['width' => $extraTable['widths'][$i], 'fill' => $last || $j % 2 ? '#' . $soft : '#ffffff', 'border_color' => '#' . $line,
                    'paragraphs' => [word_single_paragraph((string) $value, ['size' => 20, 'bold' => $last || $i === 2, 'align' => $i === 2 ? 'center' : 'left', 'space_after' => 25])]];
            }
            $extraRows[] = $cells;
        }
        $body .= word_table($extraRows, ['width' => 10400, 'border_color' => '#' . $line]);
        $body .= word_empty_paragraph(180);
        $body .= word_single_paragraph((string) $section['note'], ['size' => 18, 'color' => '#666666']);
    }

    }

    if ($appendices !== []) {
        [$usableWidthEmu, $usableHeightEmu] = docx_page_content_box_emu();
        $imageMaxWidthEmu = $usableWidthEmu;
        $imageMaxHeightEmu = $usableHeightEmu - 1050000;

        foreach ($appendices as $idx => $appendix) {
            $pages = is_array($appendix['preview_pages'] ?? null) ? $appendix['preview_pages'] : [];
            $sourceType = (string) ($appendix['source_type'] ?? 'file');

            if ($pages !== []) {
                $pageTotal = count($pages);

                foreach ($pages as $pageIndex => $pageMedia) {
                    if (!is_array($pageMedia) || !isset($pageMedia['rel_id'], $pageMedia['docpr_id'])) {
                        continue;
                    }

                    $body .= word_page_break_paragraph();

                    if ($v2Layout === 'expenses' && function_exists('rh2_appendix_header')) {
                        $body .= rh2_appendix_header($appendix, $idx);
                    } else {
                    $body .= word_single_paragraph((string) ($appendix['title'] ?? ('Annexe ' . ($idx + 1))), [
                        'size' => 18,
                        'color' => '#' . $ink,
                        'space_after' => 20,
                        'keep_next' => true,
                    ]);

                    $body .= word_single_paragraph((string) ($appendix['filename'] ?? ''), [
                        'size' => 13,
                        'color' => '#666666',
                        'space_after' => $sourceType === 'pdf' && $pageTotal > 1 ? 16 : 40,
                        'keep_next' => true,
                    ]);
                    }

                    if ($sourceType === 'pdf' && $pageTotal > 1) {
                        $body .= word_single_paragraph('Page ' . ($pageIndex + 1) . ' / ' . $pageTotal, [
                            'size' => 12,
                            'color' => '#666666',
                            'space_after' => 30,
                            'align' => 'center',
                            'keep_next' => true,
                        ]);
                    }

                    [$cx, $cy] = docx_fit_image_emu(
                        (int) ($pageMedia['width_px'] ?? 1200),
                        (int) ($pageMedia['height_px'] ?? 1200),
                        $imageMaxWidthEmu,
                        $imageMaxHeightEmu
                    );

                    $body .= word_image_paragraph(
                        (string) $pageMedia['rel_id'],
                        (string) ($pageMedia['filename'] ?? ('annexe-' . ($idx + 1))),
                        $cx,
                        $cy,
                        [
                            'align' => 'center',
                            'space_after' => 0,
                            'docpr_id' => (int) $pageMedia['docpr_id'],
                        ]
                    );
                }
            } else {
                $body .= word_page_break_paragraph();

                if ($v2Layout === 'expenses' && function_exists('rh2_appendix_header')) {
                    $body .= rh2_appendix_header($appendix, $idx);
                } else {
                $body .= word_single_paragraph((string) ($appendix['title'] ?? ('Annexe ' . ($idx + 1))), [
                    'size' => 18,
                    'color' => '#' . $ink,
                    'space_after' => 20,
                ]);

                $body .= word_single_paragraph((string) ($appendix['filename'] ?? ''), [
                    'size' => 13,
                    'color' => '#666666',
                    'space_after' => 80,
                ]);
                }

                $body .= word_single_paragraph(
                    $sourceType === 'pdf'
                        ? 'Aperçu PDF non intégré dans le document Word.'
                        : 'Aperçu non intégré dans le document Word.',
                    [
                        'size' => 15,
                        'italic' => true,
                        'color' => '#666666',
                        'space_after' => 120,
                    ]
                );
            }
        }
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" '
        . 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
        . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" '
        . 'xmlns:v="urn:schemas-microsoft-com:vml" '
        . 'xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
        . 'xmlns:w10="urn:schemas-microsoft-com:office:word" '
        . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        . 'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" '
        . 'xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" '
        . 'xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" '
        . 'xmlns:wne="http://schemas.microsoft.com/office/2006/wordml" '
        . 'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 wp14">'
        . '<w:body>' . $body
        . '<w:sectPr>' . (!empty($spec['landscape']) ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>' : '<w:pgSz w:w="11906" w:h="16838"/>') . '<w:pgMar w:top="900" w:right="820" w:bottom="900" w:left="820" w:header="500" w:footer="500" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $declared = [];
    foreach ($mediaParts as $mediaPart) {
        $ext = strtolower(pathinfo((string) $mediaPart['target'], PATHINFO_EXTENSION));
        if ($ext !== '' && !isset($declared[$ext])) {
            $declared[$ext] = true;
            $contentTypes .= '<Default Extension="' . xml_escape($ext) . '" ContentType="' . xml_escape((string) $mediaPart['content_type']) . '"/>';
        }
    }
    $contentTypes .= '</Types>';

    $documentRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    foreach ($mediaParts as $mediaPart) {
        $documentRels .= '<Relationship Id="' . xml_escape((string) $mediaPart['rel_id']) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . xml_escape((string) $mediaPart['target']) . '"/>';
    }
    $documentRels .= '</Relationships>';

    $files = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'word/document.xml' => $document,
        'word/styles.xml' => docx_styles_xml(),
        'word/_rels/document.xml.rels' => $documentRels,
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . xml_escape($title) . '</dc:title><dc:creator>' . xml_escape($gallery) . '</dc:creator><cp:lastModifiedBy>' . xml_escape($gallery) . '</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>' . xml_escape($gallery) . '</Application></Properties>',
    ];
    foreach ($mediaParts as $mediaPart) {
        $files['word/' . (string) $mediaPart['target']] = (string) $mediaPart['binary'];
    }
    return zip_store($files);
}

function response_docx(string $filename, array $spec): never
{
    $binary = docx_document($spec);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($filename) . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

function notes_docx_data(string $employeeId): array
{
    return notes_docx_bundle($employeeId)['table'];
}

function transport_docx_data(string $month): array
{
    $rows = [];
    foreach (employees() as $employee) {
        $summary = employee_summary_for_month($employee['id'], $month);
        $ticket = $summary['ticket'];
        $navigo = $summary['navigo'];
        $leaveDays = leave_days_in_month($employee['id'], $month);
        $rows[] = [
            $employee['name'],
            month_label_fr($month),
            $ticket ? (string) ($ticket['eligible_days'] ?? 0) : '0',
            (string) $leaveDays,
            $ticket ? (string) ($ticket['withdrawals'] ?? 0) : '0',
            $ticket ? (string) transport_count($ticket) : '0',
            ($navigo && !empty($navigo['has_navigo'])) ? 'Oui' : 'Non',
        ];
    }
    return [
        'headers' => ['Collaborateur', 'Mois', 'Jours ouvrant droit', 'Congés posés', 'Retraits', 'Titres restants', 'Navigo'],
        'rows' => $rows,
        'widths' => [2000, 1400, 1600, 1400, 1000, 1500, 900],
    ];
}

function pdf_escape(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function pdf_color_triplet(string $hex): string
{
    [$r, $g, $b] = hex_to_rgb_triplet($hex);
    return sprintf('%.3F %.3F %.3F', $r / 255, $g / 255, $b / 255);
}

function pdf_text_line(float $x, float $y, string $text, int $fontSize = 10, string $hex = '#111111', string $font = 'F1'): string
{
    return 'BT /' . $font . ' ' . $fontSize . ' Tf ' . pdf_color_triplet($hex) . ' rg 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . pdf_escape($text) . ') Tj ET';
}

function pdf_rect(float $x, float $y, float $w, float $h, string $strokeHex = '#d7d7d7', ?string $fillHex = null, float $lineWidth = 0.7): string
{
    $cmd = '';
    if ($fillHex !== null) {
        $cmd .= pdf_color_triplet($fillHex) . ' rg ';
    }
    $cmd .= pdf_color_triplet($strokeHex) . ' RG ' . number_format($lineWidth, 2, '.', '') . ' w ';
    $cmd .= number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . ' re ';
    $cmd .= $fillHex !== null ? 'B' : 'S';
    return $cmd;
}

function pdf_line(float $x1, float $y1, float $x2, float $y2, string $hex = '#d7d7d7', float $lineWidth = 0.7): string
{
    return pdf_color_triplet($hex) . ' RG ' . number_format($lineWidth, 2, '.', '') . ' w ' . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . ' l S';
}

function calendar_pdf_months(string $startMonth): array
{
    $first = validate_month($startMonth);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $first . '-01');
    return [$first, $dt->modify('+1 month')->format('Y-m')];
}

function draw_calendar_month_pdf(string $month, float $x, float $y, float $w, float $h): string
{
    $calendar = month_calendar($month);
    $weekdayLabels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    $commands = [];
    $commands[] = pdf_rect($x, $y, $w, $h, '#d7d7d7', '#ffffff', 0.9);
    $commands[] = pdf_rect($x, $y + $h - 26, $w, 26, '#1f3561', '#1f3561', 0.9);
    $commands[] = pdf_text_line($x + 10, $y + $h - 18, month_label_fr($month), 12, '#ffffff', 'F2');

    $weekdayY = $y + $h - 46;
    $cellW = $w / 7.0;
    $cellH = ($h - 52) / 6.0;
    foreach ($weekdayLabels as $i => $label) {
        $cx = $x + $i * $cellW;
        $commands[] = pdf_rect($cx, $weekdayY, $cellW, 20, '#d7d7d7', '#f4f4f4', 0.6);
        $commands[] = pdf_text_line($cx + 8, $weekdayY + 6, $label, 8, '#5b6270', 'F2');
    }

    $gridTop = $weekdayY;
    foreach ($calendar['weeks'] as $rowIndex => $week) {
        foreach ($week as $colIndex => $day) {
            $cx = $x + $colIndex * $cellW;
            $cy = $gridTop - ($rowIndex + 1) * $cellH;
            $fill = !$day['in_month'] ? '#f7f7f7' : ($day['holiday'] ? '#fbefef' : '#ffffff');
            $commands[] = pdf_rect($cx, $cy, $cellW, $cellH, '#d7d7d7', $fill, 0.6);
            $commands[] = pdf_text_line($cx + 6, $cy + $cellH - 12, (string) $day['day'], 8, $day['holiday'] ? '#8c2f2f' : '#111111', 'F2');
            $maxEntries = 3;
            $entryY = $cy + $cellH - 24;
            $entries = array_slice($day['entries'], 0, $maxEntries);
            foreach ($entries as $entry) {
                $base = employee_accent((string) ($entry['employee_id'] ?? ''));
                $fillChip = (string) ($entry['status'] ?? 'pending') === 'approved' ? $base : tint_hex($base, 0.58);
                $textHex = (string) ($entry['status'] ?? 'pending') === 'approved' ? '#ffffff' : '#1f3561';
                $commands[] = pdf_rect($cx + 4, $entryY - 1, $cellW - 8, 11, $fillChip, $fillChip, 0.4);
                $commands[] = pdf_text_line($cx + 7, $entryY + 2, leave_chip_label($entry), 6, $textHex, 'F1');
                $entryY -= 13;
            }
            if (count($day['entries']) > $maxEntries) {
                $commands[] = pdf_text_line($cx + 7, $entryY + 2, '+' . (count($day['entries']) - $maxEntries), 6, '#555555', 'F1');
            }
        }
    }
    return implode("\n", $commands);
}

function calendar_pdf_binary(string $month): string
{
    [$m1, $m2] = calendar_pdf_months($month);
    $commands = [];
    $commands[] = pdf_rect(0, 0, 842, 595, '#ffffff', '#ffffff', 0);
    $commands[] = pdf_text_line(34, 563, 'Calendrier équipe', 18, '#111111', 'F2');

    $legendX = 550;
    foreach (employees() as $idx => $employee) {
        $y = 576 - ($idx * 13);
        $accent = employee_accent($employee['id']);
        $commands[] = pdf_rect($legendX, $y - 3, 10, 10, $accent, $accent, 0.4);
        $commands[] = pdf_text_line($legendX + 16, $y, $employee['first_name'], 8, '#344255', 'F1');
    }

    $commands[] = draw_calendar_month_pdf($m1, 28, 38, 388, 488);
    $commands[] = draw_calendar_month_pdf($m2, 426, 38, 388, 488);

    $stream = implode("\n", $commands);
    $pdf = "%PDF-1.4\n";
    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>",
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
    ];
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function response_pdf(string $filename, string $title, string $month): never
{
    $binary = calendar_pdf_binary($month);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($filename) . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}
// Load optional document renderers after the legacy helpers so live note-export overrides remain intact.
require_once __DIR__ . '/export_layout_v2.php';
