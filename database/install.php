<?php
/**
 * Instala as tabelas no Neon PostgreSQL.
 * Execute uma vez: php database/install.php
 */
require_once __DIR__ . '/../app/Database.php';

$statements = [
    "CREATE TABLE IF NOT EXISTS campaigns (
      id              SERIAL PRIMARY KEY,
      title           VARCHAR(255) NOT NULL,
      subtitle        VARCHAR(255),
      slug            VARCHAR(255) NOT NULL,
      category        VARCHAR(100) DEFAULT 'Saúde / Tratamentos',
      city            VARCHAR(100),
      state           VARCHAR(2),
      min_amount      DECIMAL(10,2) NOT NULL DEFAULT 25.00,
      goal_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      raised_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      pix_key         VARCHAR(255),
      pix_description VARCHAR(255),
      facebook_pixel_id     VARCHAR(255),
      facebook_access_token VARCHAR(255),
      tiktok_pixel_id       VARCHAR(64),
      tiktok_access_token   VARCHAR(255),
      utmify_api_token      VARCHAR(255),
      cover_image     VARCHAR(255),
      description     TEXT,
      hearts_received INTEGER NOT NULL DEFAULT 0,
      supporters      INTEGER NOT NULL DEFAULT 0,
      is_active       BOOLEAN NOT NULL DEFAULT TRUE,
      created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at      TIMESTAMP
    )",

    "CREATE UNIQUE INDEX IF NOT EXISTS campaigns_slug_unique ON campaigns (slug)",

    "CREATE TABLE IF NOT EXISTS orders (
      id              SERIAL PRIMARY KEY,
      campaign_id     INTEGER NOT NULL REFERENCES campaigns(id),
      txid            VARCHAR(64) NOT NULL UNIQUE,
      buyer_name      VARCHAR(255),
      buyer_email     VARCHAR(255),
      buyer_cpf       VARCHAR(20),
      amount          DECIMAL(12,2) NOT NULL,
      status          VARCHAR(20) NOT NULL DEFAULT 'pendente'
                        CHECK (status IN ('pendente', 'pago', 'paid', 'pending', 'waiting_payment', 'created')),
      created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      paid_at         TIMESTAMP,
      webhook_payload TEXT
    )",

    "CREATE INDEX IF NOT EXISTS idx_orders_campaign_id ON orders (campaign_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders (created_at)",
    "CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status)",

    "CREATE OR REPLACE FUNCTION set_updated_at()
     RETURNS TRIGGER AS \$\$
     BEGIN
       NEW.updated_at = CURRENT_TIMESTAMP;
       RETURN NEW;
     END;
     \$\$ LANGUAGE plpgsql",

    "DROP TRIGGER IF EXISTS campaigns_updated_at ON campaigns",

    "CREATE TRIGGER campaigns_updated_at
       BEFORE UPDATE ON campaigns
       FOR EACH ROW EXECUTE PROCEDURE set_updated_at()",
];

try {
    $pdo = Database::getConnection();

    foreach ($statements as $i => $sql) {
        $pdo->exec($sql);
        echo 'OK [' . ($i + 1) . '/' . count($statements) . ']' . PHP_EOL;
    }

    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")
                  ->fetchAll(PDO::FETCH_COLUMN);
    echo PHP_EOL . 'Tabelas criadas: ' . implode(', ', $tables) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
