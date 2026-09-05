CREATE DATABASE gabe_system;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_empleado VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    numero_telefono VARCHAR(20) NULL,
    rol ENUM('admin', 'usuario') NOT NULL DEFAULT 'usuario',
    password VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar un usuario Administrador por defecto (Contraseña: admin123)
INSERT INTO usuarios (numero_empleado, nombre, apellido, numero_telefono, rol, password) 
VALUES ('ADMIN01', 'Admin', 'Sistema', '5551234567', 'admin', '$2y$10$e.xK7fC6Nl3O/vW2.3G2aeO3xG5uS5sY8I6u5Fk9aY9Y9Y9Y9Y9Y9');