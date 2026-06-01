Projeto simples em PHP para pagina de vaquinha tipo Vakinha.

1) Crie o banco de dados `vakinha_db` no MySQL.
2) Rode o SQL de criacao da tabela:

CREATE TABLE campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  category VARCHAR(100) DEFAULT 'Saúde / Tratamentos',
  city VARCHAR(100),
  state VARCHAR(2),
  goal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  raised_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  pix_key VARCHAR(255),
  pix_description VARCHAR(255),
  cover_image VARCHAR(255),
  description LONGTEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
);

3) Ajuste `app/config.php` com os dados do seu banco.
4) Suba tudo para o servidor (por exemplo em /var/www/html/vakinha-clone).
5) Acesse /public/admin/index.php para criar sua primeira campanha.
6) A pagina publica da campanha fica em /public/campaign.php?slug=seu-slug.
