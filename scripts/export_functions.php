<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'config.php',
    'helpers.php',
    'auth.php',
    'tasks.php',
    'task_new.php',
    'task_edit.php',
    'rooms.php',
    'upload.php',
];

$result = [];
foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        continue;
    }
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    $tokens = token_get_all($code);
    $functions = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $name = null;
            $params = '';
            $j = $i + 1;
            while ($j < $count) {
                $next = $tokens[$j];
                if (is_string($next) && $next === '&') {
                    $j++;
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];
                    $j++;
                    break;
                }
                if (is_string($next) && $next === '(') {
                    // Anonymous function, skip
                    $name = null;
                    break;
                }
                $j++;
            }
            if ($name === null) {
                continue;
            }
            while ($j < $count && !(is_string($tokens[$j]) && $tokens[$j] === '(')) {
                $j++;
            }
            if ($j >= $count) {
                continue;
            }
            $level = 0;
            $buffer = '';
            for (; $j < $count; $j++) {
                $current = $tokens[$j];
                if (is_string($current)) {
                    $buffer .= $current;
                    if ($current === '(') {
                        $level++;
                    } elseif ($current === ')') {
                        $level--;
                        if ($level === 0) {
                            break;
                        }
                    }
                } else {
                    $buffer .= $current[1];
                }
            }
            $signature = $name . $buffer;
            $functions[$name] = $signature;
            $i = $j;
        }
    }
    $result[$file] = $functions;
}

echo json_encode([
    'generated_at' => date('c'),
    'files' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
