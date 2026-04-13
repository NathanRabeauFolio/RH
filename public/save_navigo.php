<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
$employee = require_login('employee');
try {
    save_navigo($employee);
    flash_set('success', 'Navigo enregistré.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect('index.php');
