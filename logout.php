<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php'; // gives redirect_with_message() and auth_logout()

auth_logout();
redirect_with_message('login.php', 'Signed out successfully.', 'success');
