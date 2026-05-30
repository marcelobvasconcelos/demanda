-- Script de inicialização do banco de dados para a Costureira (Sistema Demanda)

CREATE DATABASE IF NOT EXISTS `costureira_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `costureira_db`;

-- 1. Tabela de Usuários (Costureiras)
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `senha` VARCHAR(255) NOT NULL,
    `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de Clientes
CREATE TABLE IF NOT EXISTS `clientes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(255) NOT NULL,
    `telefone` VARCHAR(20),
    `email` VARCHAR(255),
    `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabela de Medidas de Clientes
CREATE TABLE IF NOT EXISTS `medidas_clientes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cliente_id` INT NOT NULL,
    `busto` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida do busto em cm',
    `cintura` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida da cintura em cm',
    `quadril` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida do quadril em cm',
    `comprimento` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida de comprimento geral em cm',
    `ombro_a_ombro` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida de ombro a ombro em cm',
    `altura_busto` DECIMAL(5,2) DEFAULT NULL COMMENT 'Medida de altura do busto em cm',
    `observacoes` TEXT,
    `data_medida` DATE NOT NULL,
    `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_medidas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela de Lojas Parceiras
CREATE TABLE IF NOT EXISTS `lojas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(255) NOT NULL,
    `contato_nome` VARCHAR(255) COMMENT 'Nome da pessoa de contato na loja',
    `telefone` VARCHAR(20),
    `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabela de Serviços (Gerais)
CREATE TABLE IF NOT EXISTS `servicos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `loja_id` INT DEFAULT NULL,
    `cliente_id` INT NOT NULL,
    `descricao` TEXT NOT NULL,
    `tipo` ENUM('conserto', 'roupa_completa') NOT NULL,
    `valor_total` DECIMAL(10,2) NOT NULL,
    `valor_pago` DECIMAL(10,2) DEFAULT 0.00,
    `status_pagamento` ENUM('pendente', 'pago_parcial', 'pago') DEFAULT 'pendente',
    `data_entrada` DATE NOT NULL,
    `data_entrega_prevista` DATE,
    `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_servicos_loja` FOREIGN KEY (`loja_id`) REFERENCES `lojas` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_servicos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabela de Remessas (Baseada no aplicativo antigo)
CREATE TABLE IF NOT EXISTS `remessas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `usuario_id` INT NOT NULL,
    `loja_id` INT DEFAULT NULL,
    `peca_servico` VARCHAR(255) NOT NULL,
    `preco_unitario` DECIMAL(10,2) NOT NULL,
    `quantidade` INT NOT NULL,
    `tamanho` ENUM('PP', 'P', 'M', 'G', 'GG', 'XG', 'outro') NOT NULL,
    `qtd_entregue` INT DEFAULT 0,
    `data_ultima_entrega` DATE DEFAULT NULL,
    `data_cadastro` DATE NOT NULL,
    CONSTRAINT `fk_remessas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_remessas_loja` FOREIGN KEY (`loja_id`) REFERENCES `lojas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabela de Lotes — espelho do Firestore com controle de sincronismo
-- O campo `id` usa o próprio ID do documento Firestore como chave primária,
-- garantindo que INSERT ... ON DUPLICATE KEY UPDATE nunca crie duplicatas.
CREATE TABLE IF NOT EXISTS `lotes` (
    -- Identificação (espelha o Firestore)
    `id`                  VARCHAR(50)     NOT NULL COMMENT 'ID do documento no Firestore',
    `mes_ano_referencia`  VARCHAR(50)     NOT NULL COMMENT 'Coleção mensal de origem: {mes}-{ano}{uid}',
    `usuario_uid`         VARCHAR(128)    NOT NULL COMMENT 'UID do usuário no Firebase Auth',
    `usuario_email`       VARCHAR(255)    NOT NULL,

    -- Dados do lote (compatíveis com os campos do app Flutter)
    `peca_servico`        VARCHAR(255)    NOT NULL,
    `quantidade`          INT             NOT NULL DEFAULT 0,
    `qtd_entregue`        INT             NOT NULL DEFAULT 0,
    `preco_unitario`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `tamanho`             VARCHAR(10)     NOT NULL DEFAULT '-',
    `valor_recebido`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `data_cadastro`       VARCHAR(30)     DEFAULT NULL COMMENT 'ISO 8601 vindo do Firestore',
    `data_entrega`        VARCHAR(30)     DEFAULT NULL COMMENT 'ISO 8601 da última entrega',

    -- Controle de sincronismo
    `sincronizado`        TINYINT(1)      NOT NULL DEFAULT 1
                          COMMENT '1 = idêntico ao Firestore | 0 = pendente de envio',
    `atualizado_em`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                          ON UPDATE CURRENT_TIMESTAMP(3)
                          COMMENT 'Timestamp da última alteração local (ms de precisão)',

    PRIMARY KEY (`id`),
    INDEX `idx_lotes_usuario`   (`usuario_uid`),
    INDEX `idx_lotes_mes_ano`   (`mes_ano_referencia`),
    INDEX `idx_lotes_sync`      (`sincronizado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Espelho local dos lotes do Firestore com fila de sincronização';


-- ==========================================
-- INSERÇÃO DE DADOS DE TESTE (MOCK DATA)
-- ==========================================

-- Usuários (Costureiras)
INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`) VALUES
(1, 'Maiara Vasconcelos', 'maiara@demanda.com', '123'),
(2, 'Gisele Bündchen', 'gisele@demanda.com', '123');

-- Clientes
INSERT INTO `clientes` (`id`, `nome`, `telefone`, `email`) VALUES
(1, 'Maria Silva', '(11) 98765-4321', 'maria.silva@email.com'),
(2, 'Ana Souza', '(11) 97654-3210', 'ana.souza@email.com'),
(3, 'Joana Santos', '(11) 96543-2109', 'joana.santos@email.com'),
(4, 'Patrícia Lima', '(11) 95432-1098', 'patricia.lima@email.com');

-- Medidas de Clientes
INSERT INTO `medidas_clientes` (`cliente_id`, `busto`, `cintura`, `quadril`, `comprimento`, `ombro_a_ombro`, `altura_busto`, `observacoes`, `data_medida`) VALUES
(1, 92.00, 74.00, 102.00, 98.00, 38.00, 26.00, 'Medidas iniciais para confecção de calça.', '2026-01-10'),
(1, 94.00, 76.00, 104.00, 98.00, 38.00, 26.00, 'Medida atualizada para ajuste de vestido.', '2026-05-15'),
(2, 88.00, 68.00, 94.00, 90.00, 36.00, 24.00, 'Tirada para confecção de vestido de crepe.', '2026-03-05'),
(3, 105.00, 88.00, 115.00, 105.00, 42.00, 29.00, 'Medidas para ajuste geral de saias e blusas corporativas.', '2026-04-12');

-- Lojas
INSERT INTO `lojas` (`id`, `nome`, `contato_nome`, `telefone`) VALUES
(1, 'Modas & Estilo', 'Carlos Vasconcelos', '(11) 3344-5566'),
(2, 'Boutique Elegance', 'Valéria Antunes', '(11) 4455-6677');

-- Serviços Gerais
INSERT INTO `servicos` (`loja_id`, `cliente_id`, `descricao`, `tipo`, `valor_total`, `valor_pago`, `status_pagamento`, `data_entrada`, `data_entrega_prevista`) VALUES
(NULL, 1, 'Ajuste de barra em calça jeans e ajuste de cós', 'conserto', 45.00, 45.00, 'pago', '2026-05-10', '2026-05-12'),
(NULL, 2, 'Confecção de Vestido de Festa longo em tecido Crepe Georgette', 'roupa_completa', 450.00, 200.00, 'pago_parcial', '2026-05-12', '2026-05-30'),
(NULL, 3, 'Troca de zíper invisível e ajuste de cintura em saia social de lã', 'conserto', 35.00, 0.00, 'pendente', '2026-05-18', '2026-05-22'),
(1, 4, 'Confecção de Blazer alfaiataria sob medida para uniforme de trabalho', 'roupa_completa', 280.00, 280.00, 'pago', '2026-05-14', '2026-05-28'),
(2, 2, 'Ajuste de ombros e encurtamento de mangas em casaco de lã premium', 'conserto', 80.00, 0.00, 'pendente', '2026-05-20', '2026-05-25');

-- Remessas Reais da Maiara Vasconcelos (de acordo com os prints de Maio de 2026)
-- Preços unitários calculados para somar exatamente R$ 2133.00 no total geral
INSERT INTO `remessas` (`usuario_id`, `loja_id`, `peca_servico`, `preco_unitario`, `quantidade`, `tamanho`, `qtd_entregue`, `data_ultima_entrega`, `data_cadastro`) VALUES
(1, NULL, 'vestido', 25.00, 8, 'M', 0, NULL, '2026-05-22'),
(1, NULL, 'vestido', 25.00, 10, 'P', 0, NULL, '2026-05-22'),
(1, 1, 'vestido', 25.00, 10, 'G', 10, '2026-05-22', '2026-05-19'),
(1, 1, 'camisa', 19.00, 7, 'XG', 7, '2026-05-19', '2026-05-13'),
(1, 2, 'camisa', 19.00, 17, 'GG', 17, '2026-05-19', '2026-05-13'),
(1, 1, 'camisa', 20.00, 32, 'G', 3, '2026-05-14', '2026-05-13'),
(1, NULL, 'camisa', 19.82, 17, 'M', 17, '2026-05-18', '2026-05-13');
