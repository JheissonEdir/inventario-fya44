-- Archivo: create_schema.sql
-- Esquema recomendado para Inventario Escolar (tablas: inventarios, inventario, syncs)
-- Importa esto en phpMyAdmin o ejecuta en tu servidor MySQL

CREATE TABLE IF NOT EXISTS `inventarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anio` INT NOT NULL,
  `estado` ENUM('activo','cerrado') NOT NULL DEFAULT 'activo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `anio_unico` (`anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventario` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nivel` VARCHAR(100) DEFAULT NULL,
  `aula_funcional` VARCHAR(255) DEFAULT NULL,
  `denominacion` VARCHAR(255) DEFAULT NULL,
  `marca` VARCHAR(150) DEFAULT NULL,
  `modelo` VARCHAR(150) DEFAULT NULL,
  `tipo` VARCHAR(80) DEFAULT NULL,
  `color` VARCHAR(80) DEFAULT NULL,
  `serie` VARCHAR(200) DEFAULT NULL,
  `largo` DOUBLE DEFAULT 0,
  `ancho` DOUBLE DEFAULT 0,
  `alto` DOUBLE DEFAULT 0,
  `documento_alta` VARCHAR(200) DEFAULT NULL,
  `fecha_compra` DATE DEFAULT NULL,
  `numero_documento` VARCHAR(120) DEFAULT NULL,
  `estado` VARCHAR(80) DEFAULT NULL,
  `procedencia` VARCHAR(150) DEFAULT NULL,
  `observaciones` TEXT DEFAULT NULL,
  `usuario_responsable` VARCHAR(200) DEFAULT NULL,
  `ubicacion` VARCHAR(200) DEFAULT NULL,
  `fecha_registro` DATE DEFAULT NULL,
  `inventario_id` INT UNSIGNED NOT NULL,
  `cantidad` INT DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_inventario_id` (`inventario_id`),
  KEY `idx_nivel_aula` (`nivel`,`aula_funcional`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syncs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts` DATETIME NOT NULL,
  `sync_id` VARCHAR(255) DEFAULT NULL,
  `file_hash` VARCHAR(64) DEFAULT NULL,
  `anio` INT DEFAULT NULL,
  `inventario_id` INT DEFAULT NULL,
  `importados` INT DEFAULT 0,
  `duplicados` INT DEFAULT 0,
  `source_user` VARCHAR(200) DEFAULT NULL,
  `uploader_ip` VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_syncid` (`sync_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices recomendados adicionales (opcional):
-- CREATE INDEX `idx_serie` ON `inventario` (`serie`(80));
-- CREATE INDEX `idx_fecha_registro` ON `inventario` (`fecha_registro`);

-- Fin del archivo
