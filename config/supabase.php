<?php
// Lipa City Colleges Payroll — Supabase config.
// Fill via environment variables on hosting, or edit the defaults for local dev.
// SQL to run once: supabase_schema.sql (Supabase Dashboard > SQL Editor).

$SUPABASE_URL = getenv('SUPABASE_URL') ?: '';
$SUPABASE_PUBLISHABLE_KEY = getenv('SUPABASE_PUBLISHABLE_KEY') ?: '';

// Direct Postgres connection (PHP PDO). Requires the database password from
// Supabase Dashboard > Project Settings > Database. Pooling (port 6543) is
// recommended for web apps. Leave empty to keep using local MySQL in
// config/database.php.
$SUPABASE_DB_HOST = getenv('SUPABASE_DB_HOST') ?: ''; // e.g. aws-0-ap-southeast-1.pooler.supabase.com
$SUPABASE_DB_PORT = getenv('SUPABASE_DB_PORT') ?: '6543';
$SUPABASE_DB_NAME = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$SUPABASE_DB_USER = getenv('SUPABASE_DB_USER') ?: 'postgres';
$SUPABASE_DB_PASS = getenv('SUPABASE_DB_PASS') ?: '';

function supabase_rest(string $path, string $method = 'GET', $body = null) {
    global $SUPABASE_URL, $SUPABASE_PUBLISHABLE_KEY;
    $url = rtrim($SUPABASE_URL, '/') . $path;
    $ch = curl_init($url);
    $headers = [
        'apikey: ' . $SUPABASE_PUBLISHABLE_KEY,
        'Authorization: Bearer ' . $SUPABASE_PUBLISHABLE_KEY,
        'Content-Type: application/json',
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp ? json_decode($resp, true) : null];
}
