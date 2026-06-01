<?php
// app/config.php — credenciais carregadas do arquivo .env na raiz do projeto

require_once __DIR__ . '/Env.php';
Env::load();

/**
 * No Render/Docker a raiz do site já é a pasta public/ — BASE_URL não deve terminar em /public.
 */
function app_resolve_base_url(): string
{
    $url = rtrim((string) Env::get('BASE_URL', ''), '/');
    if ($url !== '' && str_ends_with($url, '/public')) {
        $url = substr($url, 0, -7);
    }
    if ($url === '' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return $url ?: 'http://localhost';
}

/** Monta URL absoluta: url_path('admin/index.php') */
function url_path(string $path = ''): string
{
    $path = ltrim($path, '/');
    return rtrim(app_resolve_base_url(), '/') . ($path !== '' ? '/' . $path : '');
}

define('BASE_URL', app_resolve_base_url());

define('ADMIN_USER', Env::get('ADMIN_USER', 'admin'));
define('ADMIN_PASS', Env::get('ADMIN_PASS', ''));

$dbConfig = [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', '5432'),
    'dbname'   => Env::get('DB_NAME', 'neondb'),
    'user'     => Env::get('DB_USER', ''),
    'pass'     => Env::get('DB_PASS', ''),
    'sslmode'  => Env::get('DB_SSLMODE', 'require'),
    'timezone' => Env::get('DB_TIMEZONE', 'America/Sao_Paulo'),
];

if ($dsn = Env::get('DB_DSN')) {
    $dbConfig['dsn'] = $dsn;
}

if ($endpoint = Env::get('DB_ENDPOINT')) {
    $dbConfig['endpoint'] = $endpoint;
}

define('PIX_CLIENT_ID', Env::get('PIX_CLIENT_ID', ''));
define('PIX_CLIENT_SECRET', Env::get('PIX_CLIENT_SECRET', ''));
define('PIX_BASE_URL', Env::get('PIX_BASE_URL', ''));
define('PIX_KEY', Env::get('PIX_KEY', ''));

define('UMBRELLA_BASE_URL', Env::get('UMBRELLA_BASE_URL', 'https://api-gateway.umbrellapag.com/api'));
define('UMBRELLA_API_KEY', Env::get('UMBRELLA_API_KEY', ''));
define('UMBRELLA_USER_AGENT', Env::get('UMBRELLA_USER_AGENT', 'UMBRELLAB2B/1.0'));

define('AUREAPAG_BASE_URL', Env::get('AUREAPAG_BASE_URL', 'https://api.aureapag.com.br/api'));
define('AUREAPAG_API_TOKEN', Env::get('AUREAPAG_API_TOKEN', ''));
define('AUREAPAG_OFFER_HASH', Env::get('AUREAPAG_OFFER_HASH', ''));
define('AUREAPAG_PRODUCT_HASH', Env::get('AUREAPAG_PRODUCT_HASH', ''));
