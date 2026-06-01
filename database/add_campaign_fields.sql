-- Migração de campos em campaigns (PostgreSQL / Neon)

ALTER TABLE campaigns DROP COLUMN IF EXISTS pixel_token;
ALTER TABLE campaigns DROP COLUMN IF EXISTS utmify_token;

ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS min_amount DECIMAL(10,2) NOT NULL DEFAULT 35.00;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS facebook_pixel_id VARCHAR(255);
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS facebook_access_token VARCHAR(255);
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS utmify_api_token VARCHAR(255);
