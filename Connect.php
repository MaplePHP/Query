<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Utility\Attr;
use mysqli;

/**
 * @method static select(string $columns, string|array|MigrateInterface $table)
 * @method static table(string $string)
 */
class Connect
{
    private $handler;
    private static array $inst;
    public static string $current = "default";
    private $db;

    private function __construct($handler)
    {
        $this->handler = $handler;
    }

    /**
     * Prevent cloning the instance
     * @return void
     */
    private function __clone() {
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
        $inst->setConnKey(self::$current);
        return $inst::$name(...$arguments);
    }

    /**
     * Set connection handler
     * @param $handler
     * @param string|null $key
     * @return self
     */
    public static function setHandler($handler, ?string $key = null): self
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
     * Get default instance or secondary instances with key
     * @param string|null $key
     * @return self
     * @throws ConnectException
     */
    public static function getInstance(?string $key = null): self
    {
        $key = self::getKey($key);
        if(!self::hasInstance($key)) {
            throw new ConnectException("Connection Error: No active connection or connection instance found.");
        }
        self::$current = $key;
        return self::$inst[$key];
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
     * Get the possible connection key
     * @param string|null $key
     * @return string
     */
    private static function getKey(?string $key = null): string
    {
        $key = (is_null($key)) ? "default" : $key;
        return $key;
    }

    /**
     * Access the connection handler
     * @return mixed
     */
    function getHandler() {
        return $this->handler;
    }

    /**
     * Get database type
     * @return string
     */
    public function getType(): string
    {
        return $this->handler->getType();
    }
    /**
     * Get current table prefix
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->handler->getPrefix();
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
     * Connect to database
     * @return void
     */
    public function execute(): void
    {
        $this->db = $this->handler->execute();
    }

    /**
     * Get current DB connection
     */
    public function DB(): mixed
    {
        return $this->db;
    }

    /**
     * Query sql string
     * @param  string $sql
     * @return object|array|bool
     */
    public function query(string $sql): object|array|bool
    {
        return $this->db->query($sql);
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
     * Select a new database
     * @param  string      $databaseName
     * @param  string|null $prefix Expected table prefix (NOT database prefix)
     * @return void
     */
    /*
     public static function selectDB(string $databaseName, ?string $prefix = null): void
    {
        mysqli_select_db(static::$selectedDB, $databaseName);
        if (!is_null($prefix)) {
            static::setPrefix($prefix);
        }
    }
     */

    /**
     * Execute multiple quries at once (e.g. from a sql file)
     * @param  string $sql
     * @param  object|null &$mysqli
     * @return array
     */
    public function multiQuery(string $sql, object &$mysqli = null): array
    {
        return $this->handler->multiQuery($sql, $mysqli);
    }

    /**
     * Start Transaction
     * @return mysqli
     */
    public function transaction(): mixed
    {
        return $this->handler->transaction();
    }

    /**
     * Profile mysql speed
     */
    public static function startProfile(): void
    {
        Connect::query("set profiling=1");
    }

    /**
     * Close profile and print results
     */
    public static function endProfile($html = true): string|array
    {
        $totalDur = 0;
        $result = Connect::query("show profiles");

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

    /**
    * Create Mysql variable
    * @param string $key   Variable key
    * @param string $value Variable value
    */
    public static function setVariable(string $key, string $value): AttrInterface
    {
        $escapedVarName = Attr::value("@{$key}")->enclose(false)->encode(false);
        $escapedValue = (($value instanceof AttrInterface) ? $value : Attr::value($value));

        self::$mysqlVars[$key] = clone $escapedValue;
        Connect::query("SET {$escapedVarName} = {$escapedValue}");
        return $escapedVarName;
    }

    /**
     * Get Mysql variable
     * @param string $key   Variable key
     */
    public static function getVariable(string $key): AttrInterface
    {
        if (!self::hasVariable($key)) {
            throw new ConnectException("DB MySQL variable is not set.", 1);
        }
        return Attr::value("@{$key}")->enclose(false)->encode(false);
    }

    /**
     * Get Mysql variable
     * @param string $key   Variable key
     */
    public static function getVariableValue(string $key): string
    {
        if (!self::hasVariable($key)) {
            throw new ConnectException("DB MySQL variable is not set.", 1);
        }
        return self::$mysqlVars[$key]->enclose(false)->encode(false);
    }

    /**
     * Has Mysql variable
     * @param string $key   Variable key
     */
    public static function hasVariable(string $key): bool
    {
        return (isset(self::$mysqlVars[$key]));
    }
}
