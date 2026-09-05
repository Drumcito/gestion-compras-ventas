<?php
require_once __DIR__ . '/config/connectionDB.php';

try {
    $pdo = Database::getConnection();

    // Contraseña común para las pruebas
    $passTextoPlano = 'admin123';
    $hashSeguro = password_hash($passTextoPlano, PASSWORD_BCRYPT);

    // Limpiar tabla por si acaso
    $pdo->exec("DELETE FROM usuarios");

    // Insertar Administrador
    $stmt1 = $pdo->prepare("INSERT INTO usuarios (numero_empleado, nombre, apellido, numero_telefono, rol, password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt1->execute(['ADMIN01', 'Admin', 'Sistema', '5551234567', 'admin', $hashSeguro]);

    // Insertar Usuario Empleado
    $stmt2 = $pdo->prepare("INSERT INTO usuarios (numero_empleado, nombre, apellido, numero_telefono, rol, password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt2->execute(['EMP-002', 'Carlos', 'Mendoza', '5559876543', 'vendedor', $hashSeguro]);

    echo "<h2 style='color:green;'>¡Usuarios creados con éxito!</h2>";
    echo "<p>Contraseña para ambos: <b>admin123</b></p>";
    echo "<ul>";
    echo "<li><b>Admin:</b> ADMIN01</li>";
    echo "<li><b>Empleado:</b> EMP-002</li>";
    echo "</ul>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>Error al crear usuarios: " . $e->getMessage() . "</h2>";
}