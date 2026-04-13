<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
logout_user();
redirect('index.php');
