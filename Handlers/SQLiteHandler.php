<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers;

use Exception;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use SQLite3;
use SQLite3Result;

class SQLiteHandler implements HandlerInterface
{
    private string $database;
    private ?string $charSetName = null;
    private string $charset = "UTF-8";
    private string $prefix = "";
    private SQLite3 $connection;

    public function __construct(string $database)
    {
        $this->database = $database;
    }

    /**
     * Get database type
     * @return string
     */
    public function getType(): string
    {
        return "sqlite";
    }

    /**
     * Set SQLite charset
     * @param string $charset
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * Set table prefix
     * @param string $prefix
     */
    public function setPrefix(string $prefix): void
    {
        if (strlen($prefix) > 0 && !str_ends_with($prefix, "_")) {
            throw new InvalidArgumentException("The Prefix has to end with an underscore e.g. (prefix\"_\")!", 1);
        }
        $this->prefix = $prefix;
    }

    /**
     * Connect to database
     * @return SQLite3
     * @throws ConnectException
     */
    public function execute(): SQLite3
    {
        try {
            $this->connection = new SQLite3($this->database);
            
        } catch (Exception $e) {
            throw new ConnectException('Failed to connect to SQLite: ' . $e->getMessage(), 1);
        }
        return $this->connection;
    }

    /**
     * Get selected database name
     * @return string
     */
    public function getDBName(): string
    {
        return $this->database;
    }

    /**
     * Get current Character set
     * @return string|null
     */
    public function getCharSetName(): ?string
    {
        return $this->charSetName;
    }

    /**
     * Get current table prefix
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Query sql string
     * @param  string $sql
     * @return SQLite3Result|false
     */
    public function query(string $sql): bool|SQLite3Result
    {
        return $this->connection->query($sql);
    }

    /**
     * Close SQLite Connection
     * @return void
     */
    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * Protect/prep database values from injections
     * @param  string $value
     * @return string
     */
    public function prep(string $value): string
    {
        return SQLite3::escapeString($value);
    }

    /**
     * Execute multiple queries at once (e.g. from a sql file)
     * @param  string $sql
     * @param  object|null &$db
     * @return array
     */
    public function multiQuery(string $sql, object &$db = null): array
    {
        $count = 0;
        $err = array();
        $queries = explode(";", $sql);
        $db = $this->connection;
        foreach ($queries as $query) {
            if (trim($query) !== "") {
                try {
                    $db->exec($query);
                } catch (Exception $e) {
                    $err[$count] = $e->getMessage();
                    break;
                }
                $count++;
            }
        }
        return $err;
    }

    /**
     * Start Transaction
     * @return SQLite3
     */
    public function transaction(): SQLite3
    {
        $this->connection->exec('BEGIN TRANSACTION');
        return $this->connection;
    }
}
