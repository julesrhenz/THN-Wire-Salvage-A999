<?php
// config.php
declare(strict_types=1);

define('APP_NAME',   'WireSalvage AI');
define('APP_VER',    'v9-php');
define('DATA_DIR',   __DIR__ . '/data');
define('DB_PATH',    DATA_DIR . '/wiresalvage.db');
define('UPLOAD_DIR', DATA_DIR . '/uploads');

if (!is_dir(DATA_DIR))   @mkdir(DATA_DIR,   0775, true);
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);