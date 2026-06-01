-- Tabela orders (PostgreSQL / Neon)

CREATE TABLE IF NOT EXISTS orders (
  id              SERIAL PRIMARY KEY,
  campaign_id     INTEGER NOT NULL REFERENCES campaigns(id),
  txid            VARCHAR(64) NOT NULL UNIQUE,
  buyer_name      VARCHAR(255),
  buyer_email     VARCHAR(255),
  buyer_cpf       VARCHAR(20),
  amount          DECIMAL(12,2) NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'pendente'
                    CHECK (status IN ('pendente', 'pago')),
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         TIMESTAMP,
  webhook_payload TEXT
);

CREATE INDEX IF NOT EXISTS idx_orders_campaign_id ON orders (campaign_id);
