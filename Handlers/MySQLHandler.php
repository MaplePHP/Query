<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers;

use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use mysqli;

class MySQLHandler implements HandlerInterface
{
    private string $server;
    private string $user;
    private string $pass;
    private string $dbname;
    private ?string $charSetName;
    private string $charset = "utf8mb4";
    private int $port;
    private string $prefix = "";
    private mysqli $connection;

    public function __construct(string $server, string $user, string $pass, string $dbname, int $port = 3306)
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
        return "mysql";
    }

    /**
     * Set MySqli charset
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
     * Connect to database
     * @return mysqli
     * @throws ConnectException
     */
    public function execute(): mysqli
    {
        $this->connection = new mysqli($this->server, $this->user, $this->pass, $this->dbname, $this->port);
        if (mysqli_connect_error()) {
            throw new ConnectException('Failed to connect to MySQL: ' . mysqli_connect_error(), 1);
        }
        if (!mysqli_set_charset($this->connection, $this->charset)) {
            throw new ConnectException("Error loading character set " . $this->charset . ": " . mysqli_error($this->connection), 2);
        }
        $this->charSetName = mysqli_character_set_name($this->connection);
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
     * @return object|array|bool
     */
    public function query(string $sql): object|array|bool
    {
        return $this->connection->query($sql);
    }


    /**
     * Close MySQL Connection
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
        return $this->connection->real_escape_string($value);
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
        $db = $this->connection;
        if (mysqli_multi_query($db, $sql)) {
            do {
                $result = mysqli_use_result($db);
                if (!mysqli_more_results($db)) {
                    break;
                }
                if (!mysqli_next_result($db) || mysqli_errno($db)) {
                    $err[$count] = mysqli_error($db);
                    break;
                }
                $count++;
            } while (true);
            if ($result) {
                mysqli_free_result($result);
            }
        } else {
            $err[$count] = mysqli_error($db);
        }
        return $err;
    }

    /**
     * Start Transaction
     * @return mysqli
     */
    public function transaction(): mysqli
    {
        $this->connection->begin_transaction();
        return $this->connection;
    }
}
