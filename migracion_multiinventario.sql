-- SQL para multi-inventario anual y control de estado

-- 1. Tabla de inventarios (años)
CREATE TABLE inventarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anio INT NOT NULL,
    estado ENUM('activo','cerrado') NOT NULL DEFAULT 'activo',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. Modificar tabla de bienes/inventario para asociar con el año
ALTER TABLE inventario 
    ADD COLUMN inventario_id INT DEFAULT NULL,
    ADD CONSTRAINT fk_inventario_anio FOREIGN KEY (inventario_id) REFERENCES inventarios(id);

-- 3. Al crear un nuevo año, copiar los bienes del año anterior:
-- (Ejemplo en SQL, se debe automatizar en PHP)
-- INSERT INTO inventario (campos..., inventario_id)
-- SELECT campos..., NUEVO_ID FROM inventario WHERE inventario_id = ANTERIOR_ID;

-- 4. Solo permitir edición en el inventario con estado 'activo'.
-- (Controlar en PHP: si el año está cerrado, solo lectura)

-- 5. Filtrar y mostrar por año en el sistema (agregar select de año en los reportes y CRUD).

-- 6. Para migrar los datos actuales:
-- a) Crear un inventario para el año actual (ej. 2025)
INSERT INTO inventarios (anio, estado) VALUES (2025, 'activo');
-- b) Actualizar todos los registros existentes para asociarlos a ese inventario
UPDATE inventario SET inventario_id = (SELECT id FROM inventarios WHERE anio=2025);

-- Listo para multi-inventario anual profesional.
