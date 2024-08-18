<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers;

use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use MaplePHP\Query\Handlers\PostgreSQL\PostgreSQLConnect;
use MaplePHP\Query\Handlers\PostgreSQL\PostgreSQLResult;
use PgSql\Connection;

class PostgreSQLHandler implements HandlerInterface
{
    private string $server;
    private string $user;
    private string $pass;
    private string $dbname;
    private ?string $charSetName;
    private string $charset = "utf8";
    private int $port;
    private string $prefix = "";
    private PostgreSQLConnect $connection;

    public function __construct(string $server, string $user, string $pass, string $dbname, int $port = 5432)
    {
        $this->server = $server;
        $this->user = $user;
        $this->pass = $pass;
        $this->dbname = $dbname;
        $this->port = $port;
    }

    /**
     * Get database type
     * @return string
     */
    public function getType(): string
    {
        return "postgresql";
    }

    /**
     * Set MySql charset
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
            throw new InvalidArgumentException("The Prefix has to end with a underscore e.g. (prefix\"_\")!", 1);
        }
        $this->prefix = $prefix;
    }

    /**
     * Check if a connections is open
     * @return bool
     */
    public function hasConnection(): bool
    {
        return ($this->connection instanceof Connection);
    }

    /**
     * Connect to database
     * @return PostgreSQLConnect
     * @throws ConnectException
     */
    public function execute(): PostgreSQLConnect
    {

        $this->connection = new PostgreSQLConnect($this->server, $this->user, $this->pass, $this->dbname, $this->port);
        if (!is_null($this->connection->error)) {
            throw new ConnectException('Failed to connect to PostgreSQL: ' . $this->connection->error, 1);
        }
        $encoded = pg_set_client_encoding($this->connection->getConnection(), $this->charset);
        if ($encoded < 0) {
            throw new ConnectException("Error loading character set " . $this->charset, 2);
        }
        return $this->connection;
    }

    /**
     * Get selected database name
     * @return string
     */
    public function getDBName(): string
    {
        return $this->dbname;
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
     * @return object|bool
     */
    public function query(string $sql): object|bool
    {
        $result = new PostgreSQLResult($this->connection->getConnection());
        return $result->query($sql);
    }


    /**
     * Close MySQL Connection
     * @return void
     * @throws ConnectException
     */
    public function close(): void
    {
        if(!pg_close($this->connection->getConnection())) {
            throw new ConnectException("Failed to close pgsql connection:" . pg_last_error($this->connection->getConnection()), 1);
        }
    }


    /**
     * Protect/prep database values from injections
     * @param  string $value
     * @return string
     */
    public function prep(string $value): string
    {
        return pg_escape_string($this->connection->getConnection(), $value);
    }

    /**
     * Start Transaction
     * @return PostgreSQLConnect
     */
    public function transaction(): PostgreSQLConnect
    {
        $this->connection->begin_transaction();
        return $this->connection;
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
        $db = $this->connection->getConnection();
        // Split the SQL string into individual queries
        $queries = explode(';', $sql);
        // Loop through each query and execute it
        foreach ($queries as $query) {
            $query = trim($query); // Clean up whitespace
            if (empty($query)) {
                continue; // Skip empty queries
            }
            $result = pg_query($db, $query);
            if (!$result) {
                $err[$count] = pg_last_error($db);
                break; // Stop on the first error
            }
            $count++;
        }
        return $err;
    }
}
