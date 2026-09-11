<?php
// api/tests/bootstrap.php
require_once __DIR__ . '/../vendor/autoload.php';

putenv('DB_HOST=localhost');
putenv('DB_NAME=securechat_test');
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('JWT_SECRET=test-secret-key-32chars-padding!!');
