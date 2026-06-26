<?php
if (!class_exists('DB')) {
    class DB {
        private static $pdo = null;

        public static function getConnection(): PDO {
            if (self::$pdo === null) {
                $host = getenv('DB_HOST') ?: 'mysql22.conoha.ne.jp';
                $db = getenv('DB_NAME') ?: '1vk24_onlineclass';
                $user = getenv('DB_USER') ?: '1vk24_onlineclass';
                $pass = getenv('DB_PASS') ?: 'qweasd123!!';
                $port = getenv('DB_PORT') ?: 3306;

                $dsn = "mysql:host={$host};dbname={$db};port={$port};charset=utf8mb4;connect_timeout=5";

                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ];

                self::$pdo = new PDO($dsn, $user, $pass, $options);
            }

            return self::$pdo;
        }
    }
}
