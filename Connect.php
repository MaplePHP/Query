<?php

/**
 * Wazabii MySqli - Funktion
 * Version: 3.0
 * Copyright: All right reserved for Creative Army
 */

namespace PHPFuse\Query;

use PHPFuse\Query\Exceptions\ConnectException;
use PHPFuse\Query\Interfaces\AttrInterface;
use mysqli;

class Connect
{
    private $server;
    private $user;
    private $pass;
    private $dbname;
    private $charset = "utf8mb4";
    private static $self;
    private static $prefix;
    private static $selectedDB;
    private static $mysqlVars;

    public function __construct($server, $user, $pass, $dbname)
    {
        $this->server = $server;
        $this->user = $user;
        $this->pass = $pass;
        $this->dbname = $dbname;
        self::$self = $this;
    }

    /**
     * Get current instance
     * @return self
     */
    public static function inst(): self
    {
        return self::$self;
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
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * Connect to database
     * @return void
     */
    public function execute(): void
    {
        self::$selectedDB = new mysqli($this->server, $this->user, $this->pass, $this->dbname);
        if (mysqli_connect_error()) {
            die('Failed to connect to MySQL: ' . mysqli_connect_error());
            throw new ConnectException('Failed to connect to MySQL: ' . mysqli_connect_error(), 1);
        }
        if (!is_null($this->charset) && !mysqli_set_charset(self::$selectedDB, $this->charset)) {
            throw new ConnectException("Error loading character set " . $this->charset . ": " . mysqli_error(self::$selectedDB), 2);
        }
        mysqli_character_set_name(self::$selectedDB);
    }

    /**
     * Get current DB connection
     */
    public static function DB(): mysqli
    {
        return static::$selectedDB;
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
     * Get current table prefix
     * @return string
     */
    public static function getPrefix(): string
    {
        return static::$prefix;
    }

    /**
     * Query sql string
     * @param  string $sql
     * @return object|array|bool
     */
    public static function query(string $sql): object|array|bool
    {
        return static::DB()->query($sql);
    }

    /**
     * Protect/prep database values from injections
     * @param  string $value
     * @return string
     */
    public static function prep(string $value): string
    {
        return static::DB()->real_escape_string($value);
    }

    /**
     * Select a new database
     * @param  string      $DB
     * @param  string|null $prefix Expected table prefix (NOT database prefix)
     * @return void
     */
    public static function selectDB(string $databaseName, ?string $prefix = null): void
    {
        mysqli_select_db(static::$selectedDB, $databaseName);
        if (!is_null($prefix)) {
            static::setPrefix($prefix);
        }
    }

    /**
     * Execute multiple quries at once (e.g. from a sql file)
     * @param  string $sql
     * @param  object|null &$mysqli
     * @return array
     */
    public static function multiQuery(string $sql, object &$mysqli = null): array
    {
        $count = 0;
        $err = array();
        $mysqli = self::$selectedDB;
        if (mysqli_multi_query($mysqli, $sql)) {
            do {
                if ($result = mysqli_use_result($mysqli)) {
                    /*
                    while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
                    }
                     */
                }

                if (!mysqli_more_results($mysqli)) {
                    break;
                }
                if (!mysqli_next_result($mysqli) || mysqli_errno($mysqli)) {
                    $err[$count] = mysqli_error($mysqli);
                    break;
                }
                $count++;
            } while (true);
            if ($result) {
                mysqli_free_result($result);
            }
        } else {
            $err[$count] = mysqli_error($mysqli);
        }

        //mysqli_close($mysqli);
        return $err;
    }

    /**
     * Start Transaction
     * @return Transaction instance. You can use instance to call: inst->rollback() OR inst->commit()
     */
    public static function beginTransaction()
    {
        Connect::DB()->begin_transaction();
        return Connect::DB();
    }


    // Same as @beginTransaction
    public static function transaction()
    {
        return self::beginTransaction();
    }

    /**
     * Commit transaction
     * @return void
     */
    public static function commit(): void
    {
        Connect::DB()->commit();
    }

    /**
     * Rollback transaction
     * @return void
     */
    public static function rollback(): void
    {
        Connect::DB()->rollback();
    }

    /**
     * Get current table prefix
     * @return string
     */
    public static function prefix(): string
    {
        return static::getPrefix();
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
        while ($row = $result->fetch_object()) {
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
        $escapedVarName = self::withAttr("@{$key}", ["enclose" => false, "encode" => false]);
        $escapedValue = (($value instanceof AttrInterface) ? $value : self::withAttr($value));

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
        return self::withAttr("@{$key}", ["enclose" => false, "encode" => false]);
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
