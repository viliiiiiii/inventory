<?php
require_once __DIR__ . '/helpers.php';

function db(): PDO
{
    return get_pdo();
}
