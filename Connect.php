<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use Exception;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\ResultException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;

/**
 * Connect a singleton connection class
 *
 * WIll not be __callStatic in future!
 * @method static select(string $columns, string|array|MigrateInterface $table)
 * @method static table(string $string)
 * @method static insert(string $string)
 */
class Connect implements ConnectInterface
{
    private HandlerInterface $handler;
    private static array $inst;
    public static string $current = "default";
    private ?ConnectInterface $connection = null;

    /**
     * @param HandlerInterface $handler
     */
    private function __construct(HandlerInterface $handler)
    {
        $this->handler = $handler;
    }

    /**
     * This will prevent cloning the instance
     * @return void
     */
    private function __clone(): void
    {
    }

    /**
     * Access the database main class
     * @param string $method
     * @param array $arguments
     * @return object|false
     * @throws ConnectException
     */
    public function __call(string $method, array $arguments): object|false
    {
        if(is_null($this->connection)) {
            throw new ConnectException("The connection has not been initialized yet.");
        }
        return call_user_func_array([$this->connection, $method], $arguments);
    }

    /**
     * Get default instance or secondary instances with key
     * @param string|null $key
     * @return self
     * @throws ConnectException
     */
    public static function getInstance(?string $key = null): self
    {

        /*
        var_dump("WTFTTTT", $key);
        echo "\n\n\n";
        $beg = debug_backtrace();
        foreach($beg as $test) {
            var_dump(($test['file'] ?? "noFile"), ($test['line'] ?? "noLine"), ($test['class'] ?? "noClass"), ($test['function'] ?? "noFunction"));
        }
         */


        $key = self::getKey($key);
        if(!self::hasInstance($key)) {
            throw new ConnectException("Connection Error: No active connection or connection instance found.");
        }
        self::$current = $key;
        return self::$inst[$key];
    }

    /**
     * Access query builder instance
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $inst = new DB();
        $inst->setConnKey(static::$current);
        return $inst::$name(...$arguments);
    }

    /**
     * Set connection handler
     * @param HandlerInterface $handler
     * @param string|null $key
     * @return self
     */
    public static function setHandler(HandlerInterface $handler, ?string $key = null): self
    {
        $key = self::getKey($key);
        if(self::hasInstance($key)) {
            throw new InvalidArgumentException("A handler is already connected with key \"$key\"!");
        }
        self::$inst[$key] = new self($handler);
        return self::$inst[$key];
    }

    /**
     * Remove a handler
     * @param string $key
     * @return void
     */
    public static function removeHandler(string $key): void
    {
        if($key === "default") {
            throw new InvalidArgumentException("You can not remove the default handler!");
        }
        if(!self::hasInstance($key)) {
            throw new InvalidArgumentException("The handler with with key \"$key\" does not exist!");
        }
        unset(self::$inst[$key]);
        if(self::$current === $key) {
            self::$current = "default";
        }
    }

    /**
     * Check if default instance or secondary instances exist for key
     * @param string|null $key
     * @return bool
     */
    public static function hasInstance(?string $key = null): bool
    {
        $key = self::getKey($key);
        return (isset(self::$inst[$key]) && (self::$inst[$key] instanceof self));
    }

    /**
     * Connect to database
     * The ConnectInterface instance will be null before execute
     * @return void
     * @throws ConnectException
     */
    public function execute(): void
    {
        try {
            $this->connection = $this->handler->execute();
        } catch(Exception $e) {
            throw new ConnectException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get current DB connection
     * DEPRECATED: Use connection instead!
     */
    public function DB(): ConnectInterface
    {
        return $this->connection();
    }

    /**
     * Get current DB connection
     */
    public function connection(): ConnectInterface
    {
        return $this->connection;
    }

    /**
     * Access the connection handler
     * @return HandlerInterface
     */
    function getHandler(): HandlerInterface
    {
        return $this->handler;
    }

    /**
     * Check if a connections is open
     * @return bool
     */
    public function hasConnection(): bool
    {
        return $this->handler->hasConnection();
    }

    /**
     * Protect/prep database values from injections
     * @param  string $value
     * @return string
     */
    public function prep(string $value): string
    {
        return $this->handler->prep($value);
    }

    /**
     * Query sql string
     * @param string $query
     * @param int $result_mode
     * @return object|array|bool
     * @throws ResultException
     */
    public function query(string $query, int $result_mode = 0): object|array|bool
    {
        try {
            return $this->connection->query($query);
        } catch (Exception $e) {
            throw new ResultException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Begin transaction
     * @return bool
     */
    function begin_transaction(): bool
    {
        return $this->connection->begin_transaction();
    }

    /**
     * Commit transaction
     * @return bool
     */
    function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     * @return bool
     */
    function rollback(): bool
    {
        return $this->connection->rollback();
    }

    /**
     * Returns the value generated for an AI column by the last query
     * @param string|null $column Is only used with PostgreSQL!
     * @return int
     */
    function insert_id(?string $column = null): int
    {
        return $this->connection->insert_id($column);
    }

    /**
     * Close connection
     * @return bool
     */
    function close(): true
    {
        return $this->connection->close();
    }

    /**
     * Start Transaction will return instance of ConnectInterface instead of bool
     * @return ConnectInterface
     * @throws ConnectException
     */
    public function transaction(): ConnectInterface
    {
        if(!$this->begin_transaction()) {
            $errorMsg = "Couldn't start transaction!";
            if(!empty($this->connection->error)) {
                $errorMsg = "The transaction error: " . $this->connection->error;
            }
            throw new ConnectException($errorMsg);
        }
        return $this->connection;
    }

    /**
     * Get the possible connection key
     * @param string|null $key
     * @return string
     */
    private static function getKey(?string $key = null): string
    {
        return (is_null($key)) ? "default" : $key;
    }

    /**
     * MOVE TO HANDLERS
     * This method will be CHANGED soon
     * @param string|null $key
     * @return self
     * @throws ConnectException|ResultException
     */
    public static function startProfile(?string $key = null): self
    {
        $inst = self::getInstance($key);
        $inst->query("set profiling=1");
        return $inst;
    }

    /**
     * MOVE TO HANDLERS
     * This method will be CHANGED soon
     * Close profile and print results
     * Expects startProfile
     * @throws ResultException
     */
    public function endProfile($html = true): string|array
    {
        $totalDur = 0;
        $result = $this->query("show profiles");

        $output = "";
        if ($html) {
            $output .= "<p style=\"color: red;\">";
        }

        if (is_object($result)) while ($row = $result->fetch_object()) {
            $dur = round($row->Duration, 4) * 1000;
            $totalDur += $dur;
            $output .= $row->Query_ID . ' - <strong>' . $dur . ' ms</strong> - ' . $row->Query . "<br>\n";
        }
        $total = round($totalDur, 4);

        if ($html) {
            $output .= "Total: " . $total . " ms\n";
            $output .= "</p>";
            return $output;
        } else {
            return array("row" => $output, "total" => $total);
        }
    }
}
