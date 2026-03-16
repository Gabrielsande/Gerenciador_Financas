<?php
// ============================================================
//  FinanceOS — Conexão com o Banco de Dados
//  Ajuste as credenciais abaixo conforme seu ambiente
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'financeos');
define('DB_USER',    'financeos_user');
define('DB_PASS',    'F1n@nc3OS#2026!');
define('DB_PORT',    '3306');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['erro' => 'Falha na conexão com o banco de dados: ' . $e->getMessage()]));
    }

    return $pdo;
}
