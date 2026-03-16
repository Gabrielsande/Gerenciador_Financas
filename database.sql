-- ============================================================
--  FinanceOS — Banco de Dados MySQL
--  Criado em: 2026
-- ============================================================

CREATE DATABASE IF NOT EXISTS financeos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE financeos;

-- ------------------------------------------------------------
--  Tabela: usuarios
--  (base para multiusuário futuro)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(100)    NOT NULL,
  email      VARCHAR(150)    NOT NULL UNIQUE,
  senha      VARCHAR(255)    NOT NULL,  -- bcrypt hash
  criado_em  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Tabela: categorias
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorias (
  id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nome  VARCHAR(80)   NOT NULL UNIQUE,
  icone VARCHAR(10)   NOT NULL DEFAULT '🏷️',
  PRIMARY KEY (id)
) ENGINE=InnoDB;

-- Categorias padrão
INSERT INTO categorias (nome, icone) VALUES
  ('Salário',        '💼'),
  ('Freelance',      '💻'),
  ('Investimentos',  '📈'),
  ('Alimentação',    '🍽️'),
  ('Moradia',        '🏠'),
  ('Transporte',     '🚗'),
  ('Saúde',          '💊'),
  ('Lazer',          '🎮'),
  ('Educação',       '📚'),
  ('Outros',         '📦');

-- ------------------------------------------------------------
--  Tabela: lancamentos  (tabela principal)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lancamentos (
  id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  descricao    VARCHAR(255)      NOT NULL,
  valor        DECIMAL(15, 2)    NOT NULL CHECK (valor > 0),
  tipo         ENUM('receita','gasto') NOT NULL,
  categoria_id INT UNSIGNED      NULL,
  data         DATE              NOT NULL DEFAULT (CURRENT_DATE),
  observacao   TEXT              NULL,
  criado_em    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
  INDEX idx_tipo (tipo),
  INDEX idx_data (data),
  INDEX idx_categoria (categoria_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  View: resumo_mensal
--  Totais de receitas e gastos por mês
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW resumo_mensal AS
  SELECT
    DATE_FORMAT(data, '%Y-%m')  AS mes,
    tipo,
    COUNT(*)                    AS quantidade,
    SUM(valor)                  AS total
  FROM lancamentos
  GROUP BY mes, tipo
  ORDER BY mes DESC;

-- ------------------------------------------------------------
--  View: saldo_geral
--  Saldo atual (receitas - gastos)
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW saldo_geral AS
  SELECT
    COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0)  AS total_receitas,
    COALESCE(SUM(CASE WHEN tipo = 'gasto'   THEN valor ELSE 0 END), 0)  AS total_gastos,
    COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE -valor END), 0) AS saldo
  FROM lancamentos;

-- ------------------------------------------------------------
--  Dados de exemplo
-- ------------------------------------------------------------
INSERT INTO lancamentos (descricao, valor, tipo, categoria_id, data) VALUES
  ('Salário março',         4500.00, 'receita', 1, '2026-03-05'),
  ('Freelance — site',       800.00, 'receita', 2, '2026-03-08'),
  ('Aluguel',               1200.00, 'gasto',   5, '2026-03-01'),
  ('Supermercado',           350.00, 'gasto',   4, '2026-03-10'),
  ('Uber / transporte',       90.00, 'gasto',   6, '2026-03-11'),
  ('Plano de saúde',         180.00, 'gasto',   7, '2026-03-01'),
  ('Netflix + Spotify',       55.00, 'gasto',   8, '2026-03-03'),
  ('Curso online',           120.00, 'gasto',   9, '2026-03-12');

-- ============================================================
--  Usuário dedicado da aplicação
--  Execute como root do MySQL
-- ============================================================

-- Remove o usuário se já existir (evita erro ao re-executar)
DROP USER IF EXISTS 'financeos_user'@'localhost';

-- Cria o usuário com senha segura
CREATE USER 'financeos_user'@'localhost'
  IDENTIFIED BY 'F1n@nc3OS#2026!';

-- Concede apenas as permissões necessárias (princípio do mínimo privilégio)
-- SELECT, INSERT, UPDATE, DELETE nas tabelas da aplicação
GRANT SELECT, INSERT, UPDATE, DELETE
  ON financeos.lancamentos
  TO 'financeos_user'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON financeos.categorias
  TO 'financeos_user'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON financeos.usuarios
  TO 'financeos_user'@'localhost';

-- Acesso de leitura às views
GRANT SELECT
  ON financeos.saldo_geral
  TO 'financeos_user'@'localhost';

GRANT SELECT
  ON financeos.resumo_mensal
  TO 'financeos_user'@'localhost';

-- Aplica as permissões imediatamente
FLUSH PRIVILEGES;

-- ============================================================
--  Confirmação
-- ============================================================
SELECT 'Usuário financeos_user criado com sucesso!' AS status;
