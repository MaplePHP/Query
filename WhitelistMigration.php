<?php

/**
 * Wazabii DB - For main queries
 */

namespace PHPFuse\Query;

use PHPFuse\Query\Interfaces\AttrInterface;
use PHPFuse\Query\Interfaces\MigrateInterface;
use PHPFuse\Query\Helpers\Attr;

class WhitelistMigration
{
    public const TYPE_NUMERIC = [
        "TINYINT",
        "SMALLINT",
        "MEDIUMINT",
        "INT",
        "BIGINT",
        "DECIMAL",
        "FLOAT",
        "DOUBLE",
        "REAL",
        "BIT",
        "BOOLEAN"
    ];

    private $mig;
    private $data;
    private $message;
    //private $key;

    /**
     * WhitelistMigration will take the migration files and use them to make a whitelist validation
     * It kinda works like a custom built CSP filter but for the Database!
     * @param MigrateInterface $mig
     */
    public function __construct(MigrateInterface $mig)
    {
        $this->mig = $mig;
        $this->data = $mig->getData();
    }

    /**
     * Get MigrateInterface
     * @return MigrateInterface
     */
    public function getMig(): MigrateInterface
    {
        return $this->mig;
    }

    /**
     * Merge data add more columns to the mix
     * @param  array $arr
     * @return void
     */
    public function mergeData(array $arr): void
    {
        $this->data = array_merge($this->data, $arr);
    }

    /**
     * Get table
     * @return string
     */
    public function getTable(): string
    {
        return $this->mig->getTable();
    }

    /**
     * Get possible error message
     * @return string
     */
    public function getMessage(): ?string
    {
        return (string)Attr::value($this->message)->prep(false)->enclose(false)->encode(true);
    }

    /**
     * Validate: Whitelist columns
     * @return bool
     */
    public function columns(array $columns): bool
    {
        foreach ($columns as $name) {
            if (($colPrefix = strpos($name, ".")) !== false) {
                $name = substr($name, $colPrefix + 1);
            }
            if ($name !== "*") {
                if (!isset($this->data[$name])) {
                    $this->message = "The column ({$name}) do not exists in database table.";
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validate: Whitelist where data
     * @param  string        $key   database column
     * @param  AttrInterface $value column value
     * @return bool
     */
    public function where(string $key, AttrInterface $value): bool
    {
        $inst = clone $value;
        $value = (string)$inst->enclose(false);

        // Get column without possible alias
        if (($colPrefix = strpos($key, ".")) !== false) {
            $key = substr($key, $colPrefix + 1);
        }

        // Key is assosiated with a Internal MySQL variable then return that
        // value to check if the variable type is of the right type
        if (Connect::hasVariable($key)) {
            $value = (string)Connect::getVariableValue($key);
        }

        if (!isset($this->data[$key])) {
            $this->message = "The column ({$key}) do not exists in database table.";
            return false;
        }

        $type = strtoupper($this->data[$key]['type']);
        $length = (int)($this->data[$key]['length'] ?? 0);

        if ((in_array($type, self::TYPE_NUMERIC) && !is_numeric($value)) || !is_string($value)) {
            $this->message = "The database column ({$key}) value type is not supported.";
            return false;
        } elseif ($length < strlen($value)) {
            $this->message = "The database column ({$key}) value length is longer than ({$length}).";
            return false;
        }
        return true;
    }
}
