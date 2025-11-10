<?php
/**
 * Shared helpers for generating public room tokens and QR codes across exports.
 * These helpers are intentionally namespaced via function_exists checks so that
 * including this file multiple times stays safe.
 */

if (!function_exists('ensure_public_room_token_tables')) {
    function ensure_public_room_token_tables(PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS public_room_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                room_id BIGINT UNSIGNED NOT NULL,
                token VARBINARY(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                use_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                UNIQUE KEY uniq_token (token),
                INDEX idx_room_exp (room_id, expires_at),
                INDEX idx_exp (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS public_room_hits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token_id BIGINT UNSIGNED NOT NULL,
                room_id BIGINT UNSIGNED NOT NULL,
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip VARBINARY(16) NULL,
                ua VARCHAR(255) NULL,
                INDEX idx_token (token_id),
                INDEX idx_room (room_id),
                CONSTRAINT fk_room_hits_token FOREIGN KEY (token_id)
                  REFERENCES public_room_tokens(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('base_url_for_pdf')) {
    function base_url_for_pdf(): string {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        if (strpos($host, ':') === false) {
            $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
            if (($https && $port !== 443) || (!$https && $port !== 80)) {
                $host .= ':' . $port;
            }
        }
        return $scheme . '://' . $host;
    }
}

if (!function_exists('random_token')) {
    function random_token(): string {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}

if (!function_exists('qr_data_uri')) {
    function qr_data_uri(string $url, int $size = 160): ?string {
        static $cache = [];
        $size = max(120, min(300, $size));
        $key  = $url . '|' . $size;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        if (class_exists('QRcode')) {
            ob_start();
            QRcode::png($url, null, 'H', 8, 1);
            $png = ob_get_clean();
            $data = 'data:image/png;base64,' . base64_encode($png);
            return $cache[$key] = $data;
        }

        $endpoint = 'https://quickchart.io/qr';
        $qs = http_build_query([
            'text'   => $url,
            'size'   => $size,
            'margin' => 1,
            'format' => 'png',
        ]);
        $png = @file_get_contents($endpoint . '?' . $qs);
        if ($png === false) {
            return null;
        }

        $data = 'data:image/png;base64,' . base64_encode($png);
        return $cache[$key] = $data;
    }
}

if (!function_exists('fetch_valid_room_tokens')) {
    function fetch_valid_room_tokens(PDO $pdo, array $roomIds): array {
        if (!$roomIds) {
            return [];
        }

        $in  = implode(',', array_fill(0, count($roomIds), '?'));
        $sql = "SELECT t1.*
                FROM public_room_tokens t1
                JOIN (
                  SELECT room_id, MAX(id) AS max_id
                  FROM public_room_tokens
                  WHERE revoked = 0 AND expires_at > NOW() AND room_id IN ($in)
                  GROUP BY room_id
                ) t2 ON t1.id = t2.max_id";
        $st = $pdo->prepare($sql);
        $st->execute($roomIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['room_id']] = $r;
        }
        return $out;
    }
}

if (!function_exists('insert_room_token')) {
    function insert_room_token(PDO $pdo, int $roomId, int $ttlDays): array {
        $token  = random_token();
        $expiry = (new DateTimeImmutable('now'))->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');
        $st = $pdo->prepare(
            'INSERT INTO public_room_tokens (room_id, token, expires_at) VALUES (:room, :tok, :exp)'
        );
        $st->execute([
            ':room' => $roomId,
            ':tok'  => $token,
            ':exp'  => $expiry,
        ]);
        $id = (int)$pdo->lastInsertId();
        return [
            'id'          => $id,
            'room_id'     => $roomId,
            'token'       => $token,
            'expires_at'  => $expiry,
            'revoked'     => 0,
            'use_count'   => 0,
            'last_used_at'=> null,
        ];
    }
}