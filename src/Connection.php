<?php

namespace App;

use PDO;
use Dotenv\Dotenv;

class Connection
{
    private static ?PDO $connection = null;

    /**
     * Получить подключение к базе данных
     * @return PDO
     * @throws \RuntimeException
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::createConnection();
        }

        return self::$connection;
    }

    /**
     * Создать новое подключение к базе данных
     * @return PDO
     * @throws \RuntimeException
     */
    private static function createConnection(): PDO
    {
        // Загружаем .env файл
        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();

        $databaseUrl = $_ENV['DATABASE_URL'] ?? '';

        if ($databaseUrl === '') {
            throw new \RuntimeException('DATABASE_URL is not defined');
        }

        $url = parse_url($databaseUrl);

        if ($url === false) {
            throw new \RuntimeException('DATABASE_URL has invalid format');
        }

        $host = $url['host'] ?? 'localhost';
        $port = $url['port'] ?? 5432;
        $dbName = ltrim($url['path'] ?? '', '/');
        $user = $url['user'] ?? '';
        $password = $url['pass'] ?? '';

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";

        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
