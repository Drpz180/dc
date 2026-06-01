<?php
// app/Database.php
require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $conn = null;

    public static function getConnection(): PDO {
        global $dbConfig;

        if (self::$conn === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            [$dsn, $user, $pass] = self::resolveConnection($dbConfig);

            self::$conn = new PDO($dsn, $user, $pass, $options);

            $timezone = $dbConfig['timezone'] ?? 'America/Sao_Paulo';
            self::$conn->exec("SET TIME ZONE '{$timezone}'");
        }

        return self::$conn;
    }

    /** @return array{0: string, 1: string, 2: string} [dsn, user, pass] */
    private static function resolveConnection(array $dbConfig): array
    {
        $user = $dbConfig['user'] ?? '';
        $pass = $dbConfig['pass'] ?? '';

        if (!empty($dbConfig['dsn'])) {
            $raw = $dbConfig['dsn'];
            if (str_starts_with($raw, 'postgresql://') || str_starts_with($raw, 'postgres://')) {
                $parts = parse_url($raw);
                if ($parts === false || empty($parts['host'])) {
                    throw new RuntimeException('DSN PostgreSQL inválido em dbConfig[dsn]');
                }
                $user = $parts['user'] ?? $user;
                $pass = $parts['pass'] ?? $pass;
                $host = $parts['host'];
                $port = (int)($parts['port'] ?? 5432);
                $dbname = ltrim($parts['path'] ?? '/neondb', '/');
                $query = [];
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                }
                $sslmode = $query['sslmode'] ?? 'require';
                $endpoint = $query['endpoint'] ?? ($dbConfig['endpoint'] ?? null);
                $dsn = self::buildPgsqlDsn($host, $port, $dbname, $sslmode, $endpoint);
                return [$dsn, $user, $pass];
            }
            return [$raw, $user, $pass];
        }

        $host     = $dbConfig['host'];
        $port     = (int)($dbConfig['port'] ?? 5432);
        $dbname   = $dbConfig['dbname'];
        $sslmode  = $dbConfig['sslmode'] ?? 'require';
        $endpoint = $dbConfig['endpoint'] ?? null;
        $dsn = self::buildPgsqlDsn($host, $port, $dbname, $sslmode, $endpoint);

        return [$dsn, $user, $pass];
    }

    /**
     * Monta DSN compatível com libpq antigo (XAMPP) + Neon.
     * libpq < 14 não envia SNI; o endpoint ID resolve o roteamento no Neon.
     */
    private static function buildPgsqlDsn(
        string $host,
        int $port,
        string $dbname,
        string $sslmode,
        ?string $endpointId = null
    ): string {
        $endpointId = $endpointId ?: self::extractNeonEndpointId($host);

        if ($endpointId !== null) {
            $dbname = "{$dbname} options=endpoint={$endpointId}";
        }

        return "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
    }

    /** Extrai ep-xxxx do hostname Neon (ex.: ep-foo-bar-pooler.region.aws.neon.tech → ep-foo-bar). */
    private static function extractNeonEndpointId(string $host): ?string
    {
        if (!str_contains($host, 'neon.tech')) {
            return null;
        }

        $label = explode('.', $host)[0] ?? '';
        if (str_ends_with($label, '-pooler')) {
            $label = substr($label, 0, -strlen('-pooler'));
        }

        return str_starts_with($label, 'ep-') ? $label : null;
    }
}
