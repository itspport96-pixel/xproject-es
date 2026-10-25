<?php

declare(strict_types=1);

namespace App\Shared\Services;

use PDO;
use PDOException;
use App\Shared\Exceptions\DatabaseException;
use App\Shared\Exceptions\SecurityException;

class DatabaseService
{
    private ?PDO $connection = null;
    private array $config;

    private const SAFE_IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Sesion MySQL por defecto (v1.19.1): la app no depende del my.ini de la
     * maquina. bootstrap.php pasa DB_SQL_MODE / DB_TIME_ZONE del .env y aqui
     * se aplican con validacion (si el .env trae basura, la conexion falla en
     * vez de inyectar SQL). El sql_mode estricto es obligatorio: ver AGENTS.md
     * regla 12 (sin el, MariaDB guarda silenciosamente ENUM invalidos, fechas
     * cero y truncados). time_zone: UTC o desfase horario, porque las zonas
     * con nombre exigen las tablas de zona horaria cargadas en el servidor.
     */
    private const DEFAULT_SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';
    private const DEFAULT_TIME_ZONE = '+00:00';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Permite inyectar una conexión ya abierta (tests con SQLite, pool externo).
     * Sólo cambia el origen de la conexión: query/insert/transaction no varían.
     */
    public function setConnection(PDO $connection): void
    {
        $this->connection = $connection;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new DatabaseException("Query failed: {$e->getMessage()}", (int)$e->getCode(), $e);
        }
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $data): string
    {
        $this->validateIdentifier($table, 'table');

        $columns = array_keys($data);
        foreach ($columns as $col) {
            $this->validateIdentifier($col, 'column');
        }

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})";

        $this->query($sql, array_values($data));
        return $this->getConnection()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $this->validateIdentifier($table, 'table');

        $columns = array_keys($data);
        foreach ($columns as $col) {
            $this->validateIdentifier($col, 'column');
        }

        $set = implode(', ', array_map(fn($col) => "{$col} = ?", $columns));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $this->validateIdentifier($table, 'table');

        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    private function validateIdentifier(string $identifier, string $type): void
    {
        if (!preg_match(self::SAFE_IDENTIFIER_PATTERN, $identifier)) {
            throw new SecurityException(
                "Invalid {$type} identifier: '{$identifier}'. Only alphanumeric characters and underscores are allowed."
            );
        }
    }

    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    public function rollback(): bool
    {
        return $this->getConnection()->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function connect(): void
    {
        try {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};charset={$this->config['charset']}";

            $sqlMode = (string) ($this->config['sql_mode'] ?? self::DEFAULT_SQL_MODE);
            if (preg_match('/^[A-Za-z0-9_,]+$/D', $sqlMode) !== 1) {
                throw new SecurityException("Invalid sql_mode configuration: '{$sqlMode}'.");
            }

            $timeZone = (string) ($this->config['time_zone'] ?? self::DEFAULT_TIME_ZONE);
            if (preg_match('/^(?:UTC|[+-][0-9]{2}:[0-9]{2})$/D', $timeZone) !== 1) {
                throw new SecurityException(
                    "Invalid time_zone configuration: '{$timeZone}'. Use UTC or an offset such as +00:00."
                );
            }

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    // v1.13.10: el modo estricto hace que MariaDB RECHACE
                    // inserciones invalidas (ENUM fuera de miembro, fecha cero,
                    // truncado) en vez de guardarlas silenciosamente como '' o
                    // NULL. Sin esto, una carga manual con encoding erroneo
                    // corrompia datos y la hidratacion del dominio fallaba (ver
                    // CHANGELOG 1.13.10). Se fija aqui ademas del GLOBAL para
                    // que la app siga estricta aunque se relaje el servidor.
                    // v1.19.1: valores de .env (DB_SQL_MODE / DB_TIME_ZONE) con
                    // default en codigo y validados arriba: la sesion queda
                    // igual en cualquier maquina sin depender de my.ini.
                    PDO::MYSQL_ATTR_INIT_COMMAND =>
                        "SET sql_mode='{$sqlMode}', time_zone='{$timeZone}'",
                ]
            );
        } catch (PDOException $e) {
            throw new DatabaseException("Database connection failed: {$e->getMessage()}", (int)$e->getCode(), $e);
        }
    }

    public function disconnect(): void
    {
        $this->connection = null;
    }
}
