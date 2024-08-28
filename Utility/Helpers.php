<?php

namespace MaplePHP\Query\Utility;

use BadMethodCallException;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Interfaces\AttrInterface;


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


}