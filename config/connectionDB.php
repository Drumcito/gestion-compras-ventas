<?php
date_default_timezone_set('America/Mexico_City');

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {

        // Las credenciales viven en config/credenciales.local.php, que no se
        // versiona. Así el mismo código sirve en local y en el hosting sin que
        // ninguna contraseña llegue al repositorio.
        // Para configurarlo: copia credenciales.example.php como
        // credenciales.local.php y edita los valores.
        $archivo = __DIR__ . '/credenciales.local.php';

        if (!file_exists($archivo)) {
            die('Falta config/credenciales.local.php. Copia credenciales.example.php '
                . 'con ese nombre y pon ahí los datos de tu base de datos.');
        }

        $cfg = require $archivo;

        $host    = $cfg['host'];
        $db      = $cfg['db'];
        $user    = $cfg['user'];
        $pass    = $cfg['pass'];
        $charset = $cfg['charset'];

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";


        try {
            $this->connection = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            $this->connection->exec("SET time_zone = '-06:00'");
            $this->connection->exec("SET NAMES utf8mb4");

        } catch (PDOException $e) {
            die('Error de conexion a la base de datos: ' . $e->getMessage());
        }
    }

    public static function getConnection()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance->connection;
    }
}
$pdo = Database::getConnection();