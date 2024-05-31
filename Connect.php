<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Utility\Attr;
use mysqli;

class Connect
{

    private $handler;
    private static array $inst;
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

    public static function __callStatic(string $name, array $arguments)
    {
        $inst = new DB();
        return $inst::$name(...$arguments);
    }

    public static function setHandler($handler, ?string $key = null): self
    {
        $key = self::getKey($key);
        if(!self::hasInstance($key)) {
            self::$inst[$key] = new self($handler);
        }
        return self::$inst[$key];
    }

    public static function getInstance(?string $key = null): self
    {
        $key = self::getKey($key);
        if(!self::hasInstance($key)) {
            throw new ConnectException("Connect Error: No Connection Found");
        }

        return self::$inst[$key];
    }

    private static function hasInstance(?string $key = null): bool
    {
        $key = self::getKey($key);
        return (isset(self::$inst[$key]) && (self::$inst[$key] instanceof self));
    }

    private static function getKey(?string $key = null): string
    {
        $key = (is_null($key)) ? "default" : $key;
        return $key;
    }

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
