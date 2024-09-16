<?php

namespace MaplePHP\Query\Utility;

use BadMethodCallException;
use InvalidArgumentException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\DBInterface;


class Helpers {

    protected const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
    protected const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types


    /**
     * Whitelist comparison operators
     * @param  string $val
     * @return string
     */
    public static function operator(string $val): string
    {
        $val = trim($val);
        if (in_array($val, static::OPERATORS)) {
            return $val;
        }
        return "=";
    }

    /**
     * Whitelist mysql sort directions
     * @param  string $val
     * @return string
     */
    public static function orderSort(string $val): string
    {
        $val = strtoupper($val);
        if ($val === "ASC" || $val === "DESC") {
            return $val;
        }
        return "ASC";
    }

    /**
     * Whitelist mysql join types
     * @param  string $val
     * @return string
     */
    public static function joinTypes(string $val): string
    {
        $val = trim($val);
        if (in_array($val, static::JOIN_TYPES)) {
            return $val;
        }
        return "INNER";
    }

    /**
     * Prepare order by
     * @param  array $arr
     * @return array
     */
    public static function getOrderBy(array $arr): array
    {
        $new = [];
        foreach($arr as $row) {
            $new[] = "{$row['column']} {$row['sort']}";
        }
        return $new;
    }


    public static function buildJoinData(DBInterface $inst, string|array $where): array
    {
        $data = array();
        if (is_array($where)) {
            foreach ($where as $key => $val) {
                if (is_array($val)) {
                    foreach ($val as $grpKey => $grpVal) {
                        if(!($grpVal instanceof AttrInterface)) {
                            $grpVal = "%s";
                        }
                        $inst->setWhereData($grpKey, $grpVal, $data);
                    }
                } else {
                    if(!($val instanceof AttrInterface)) {
                        $val = "%s";
                    }
                    $inst->setWhereData($key, $val, $data);
                }
            }
        }

        return $data;
    }

    function validateIdentifiers($column): bool
    {
        return (preg_match('/^[a-zA-Z0-9_]+$/', $column) !== false);
    }

    /**
     * Mysql Prep/protect string
     * @param mixed $val
     * @param bool $enclose
     * @return AttrInterface
     */
    public static function prep(mixed $val, bool $enclose = true): AttrInterface
    {
        if ($val instanceof AttrInterface) {
            return $val;
        }
        $val = static::getAttr($val);
        $val->enclose($enclose);
        return $val;
    }

    /**
     * Mysql Prep/protect array items
     * @param  array    $arr
     * @param  bool     $enclose
     * @return array
     */
    public static function prepArr(array $arr, bool $enclose = true): array
    {
        $new = array();
        foreach ($arr as $pKey => $pVal) {
            $key = (string)static::prep($pKey, false);
            $new[$key] = (string)static::prep($pVal, $enclose);
        }
        return $new;
    }

    /**
     * MOVE TO DTO ARR
     * Will extract camelcase to array
     * @param  string $value string value with possible camel cases
     * @return array
     */
    public static function extractCamelCase(string $value): array
    {
        return preg_split('#([A-Z][^A-Z]*)#', $value, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Use to loop camel case method columns
     * @param  array    $camelCaseArr
     * @param  array    $valArr
     * @param  callable $call
     * @return void
     */
    public static function camelLoop(array $camelCaseArr, array $valArr, callable $call): void
    {
        foreach ($camelCaseArr as $k => $col) {
            $col = lcfirst($col);
            $value = ($valArr[$k] ?? null);
            $call($col, $value);
        }
    }

    /**
     * Get new Attr instance
     * @param  array|string|int|float $value
     * @return AttrInterface
     */
    public static function getAttr(array|string|int|float $value): AttrInterface
    {
        return new Attr($value);
    }

    /**
     * Access Query Attr class
     * @param array|string|int|float $value
     * @param array|null $args
     * @return AttrInterface
     */
    public static function withAttr(array|string|int|float $value, ?array $args = null): AttrInterface
    {
        $inst = static::getAttr($value);
        if (!is_null($args)) {
            foreach ($args as $method => $arg) {
                if (!method_exists($inst, $method)) {
                    throw new BadMethodCallException("The Query Attr method \"" .htmlspecialchars($method, ENT_QUOTES). "\" does not exists!", 1);
                }
                $inst = call_user_func_array([$inst, $method], (!is_array($arg) ? [$arg] : $arg));
            }
        }
        return $inst;
    }

    /**
     * Separate Alias
     * @param string|array $data
     * @return array
     * @throws InvalidArgumentException
     */
    public static function separateAlias(string|array $data): array
    {
        $alias = null;
        $table = $data;
        if (is_array($data)) {
            if (count($data) !== 2) {
                throw new InvalidArgumentException("If you specify Table as array then it should look " .
                    "like this [TABLE_NAME, ALIAS]", 1);
            }
            $alias = array_pop($data);
            $table = reset($data);
        }
        return ["alias" => $alias, "table" => $table];
    }


    /**
     * Will add a alias to a MySQL table
     * @param string $table
     * @param string|null $alias
     * @return string
     */
    public static function addAlias(string|AttrInterface $table, null|string|AttrInterface $alias = null, string $command = ""): string
    {
        if(!is_null($alias)) {
            $table .= ($command ? " {$command} " : " ") . $alias;
        }
        return $table;
    }

    /**
     * Will add a alias to a MySQL table
     * @param string $table
     * @param string|null $alias
     * @return string
     */
    public static function toAlias(string|AttrInterface $table, null|string|AttrInterface $alias = null): string
    {
        if(!is_null($alias)) {
            $table .= " AS " . $alias;
        }
        return $table;
    }

}