<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
require_login('admin');
download_login_journal_csv();
