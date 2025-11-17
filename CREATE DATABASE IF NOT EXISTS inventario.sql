CREATE DATABASE IF NOT EXISTS inventario_escolar;
USE inventario_escolar;

CREATE TABLE IF NOT EXISTS inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nivel ENUM('Inicial', 'Primaria', 'Secundaria') NOT NULL,
    aula_funcional VARCHAR(100),
    denominacion VARCHAR(100),
    marca VARCHAR(50),
    modelo VARCHAR(50),
    tipo VARCHAR(50),
    color VARCHAR(30),
    serie VARCHAR(50),
    largo DECIMAL(6,2),
    ancho DECIMAL(6,2),
    alto DECIMAL(6,2),
    documento_alta VARCHAR(100),
    fecha_compra DATE,
    numero_documento VARCHAR(50),
    estado VARCHAR(30),
    procedencia VARCHAR(50),
    observaciones VARCHAR(255),
    usuario_responsable VARCHAR(100),
    ubicacion VARCHAR(100),
    fecha_registro DATE
);