<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Utility\Attr;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Exceptions\DBQueryException;

/**
 * @psalm-taint-source
 */
abstract class AbstractDB implements DBInterface
{
    // Whitelists
    protected const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
    protected const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types
    protected const VIEW_PREFIX_NAME = "view"; // View prefix

    protected $table;
    protected $alias;
    protected $columns;
    protected $mig;
    protected $compare = "=";
    protected $whereAnd = "AND";
    protected $whereNot = false;
    protected $whereIndex = 0;
    protected $whereProtocol = [];
    protected $fkData;
    protected $joinedTables;


    /**
     * Build SELECT sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function select(): self;

    /**
     * Build INSERT sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function insert(): self;

    /**
     * Build UPDATE sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function update(): self;

    /**
     * Build DELETE sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function delete(): self;


    /**
     * Build CREATE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function createView(): self;

    /**
     * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function replaceView(): self;


    /**
     * Build DROP VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function dropView(): self;

    /**
     * Build DROP VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    abstract protected function showView(): self;

    /**
     * Access Mysql DB connection
     * @return \mysqli
     */
    public function connect()
    {
        return Connect::getInstance()->DB();
    }

    /**
     * Get current instance Table name with prefix attached
     * @return string
     */
    public function getTable(bool $withAlias = false): string
    {
        $alias = ($withAlias && !is_null($this->alias)) ? " {$this->alias}" : "";
        return Connect::getInstance()->getHandler()->getPrefix() . $this->table . $alias;
    }

    /**
     * Get current instance Columns
     * @return array
     */
    public function getColumns(): array
    {
        if(is_string($this->columns)) {
            return explode(",", $this->columns);
        }
        if (!is_null($this->mig) && !$this->mig->columns($this->columns)) {
            throw new DBValidationException($this->mig->getMessage(), 1);
        }
        return $this->columns;
    }

    /**
     * Get new Attr instance
     * @param  array|string|int|float $value
     * @return AttrInterface
     */
    protected function getAttr(array|string|int|float $value): AttrInterface
    {
        return new Attr($value);
    }


    /**
     * Will reset Where input
     * @return void
     */
    protected function resetWhere(): void
    {
        $this->whereAnd = "AND";
        $this->compare = "=";
    }

    /**
     * Whitelist comparison operators
     * @param  string $val
     * @return string
     */
    protected function operator(string $val): string
    {
        $val = trim($val);
        if (in_array($val, $this::OPERATORS)) {
            return $val;
        }
        return "=";
    }

    /**
     * Whitelist mysql sort directions
     * @param  string $val
     * @return string
     */
    protected function orderSort(string $val): string
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
    protected function joinTypes(string $val): string
    {
        $val = trim($val);
        if (in_array($val, $this::JOIN_TYPES)) {
            return $val;
        }
        return "INNER";
    }

    /**
     * Sperate Alias
     * @param  string|array  $data
     * @return array
     */
    final protected function sperateAlias(string|array $data): array
    {
        $alias = null;
        $table = $data;
        if (is_array($data)) {
            if (count($data) !== 2) {
                throw new DBQueryException("If you specify Table as array then it should look " .
                    "like this [TABLE_NAME, ALIAS]", 1);
            }
            $alias = array_pop($data);
            $table = reset($data);
        }
        return ["alias" => $alias, "table" => $table];
    }

    /**
     * Propegate where data structure
     * @param string|AttrInterface           $key
     * @param string|int|float|AttrInterface $val
     * @param array|null                     &$data static value
     */
    final protected function setWhereData(string|AttrInterface $key, string|int|float|AttrInterface $val, ?array &$data): void
    {
        if (is_null($data)) {
            $data = array();
        }
        $key = (string)$this->prep($key, false);
        $val = $this->prep($val);

        if (!is_null($this->mig) && !$this->mig->where($key, $val)) {
            throw new DBValidationException($this->mig->getMessage(), 1);
        }

        //$data[$this->whereIndex][$this->whereAnd][$this->compare][$key][] = $val;
        $data[$this->whereIndex][$this->whereAnd][$key][] = [
            "not" => $this->whereNot,
            "operator" => $this->compare,
            "value" => $val
        ];

        $this->whereProtocol[$key][] = $val;
        $this->resetWhere();
    }

    /**
     * Build Where data
     * @param  array $array
     * @return string
     */
    final protected function whereArrToStr(array $array): string
    {
        $out = "";
        $count = 0;
        foreach ($array as $key => $arr) {
            foreach ($arr as $col => $a) {
                if (is_array($a)) {
                    foreach ($a as $int => $row) {
                        if ($count > 0) {
                            $out .= "{$key} ";
                        }
                        if ($row['not'] === true) {
                            $out .= "NOT ";
                        }
                        $out .= "{$col} {$row['operator']} {$row['value']} ";
                        $count++;
                    }
                    
                } else {
                    $out .= "{$key} {$a} ";
                    $count++;
                }
            }
        }

        return $out;
    }
    
    /**
     * Get the Main FK data protocol
     * @return array
     */
    final protected function getMainFKData(): array
    {
        if (is_null($this->fkData)) {
            $this->fkData = array();
            foreach ($this->mig->getMig()->getData() as $col => $row) {
                if (isset($row['fk'])) {
                    foreach ($row['fk'] as $a) {
                        $this->fkData[$col][$a['table']][] = $a['column'];
                    }
                }
            }
        }
        return $this->fkData;
    }

    /**
     * Mysql Prep/protect string
     * @param mixed $val
     * @param bool $enclose
     * @return AttrInterface
     */
    final protected function prep(mixed $val, bool $enclose = true): AttrInterface
    {
        if ($val instanceof AttrInterface) {
            return $val;
        }
        $val = $this->getAttr($val);
        $val->enclose($enclose);
        return $val;
    }

    /**
     * Mysql Prep/protect array items
     * @param  array    $arr
     * @param  bool     $enclose
     * @return array
     */
    final protected function prepArr(array $arr, bool $enclose = true): array
    {
        $new = array();
        foreach ($arr as $pKey => $pVal) {
            $key = (string)$this->prep($pKey, false);
            $new[$key] = (string)$this->prep($pVal, $enclose);
        }
        return $new;
    }

    /**
     * Use vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
     * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
     * @param  array     $arr     Mysql prep values
     * @return string
     */
    final protected function sprint(string $str, array $arr = array()): string
    {
        return vsprintf($str, $this->prepArr($arr, false));
    }

    /**
     * Use to loop camel case method columns
     * @param  array    $camelCaseArr
     * @param  array    $valArr
     * @param  callable $call
     * @return void
     */
    final protected function camelLoop(array $camelCaseArr, array $valArr, callable $call): void
    {
        foreach ($camelCaseArr as $k => $col) {
            $col = lcfirst($col);
            $value = ($valArr[$k] ?? null);
            $call($col, $value);
        }
    }

    /**
     * Will extract camle case to array
     * @param  string $value string value with possible camel cases
     * @return array
     */
    final protected function extractCamelCase(string $value): array
    {
        return preg_split('#([A-Z][^A-Z]*)#', $value, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Build join data from Migrate data
     * @param MigrateInterface $mig
     * @param string $type Join type (INNER, LEFT, ...)
     * @return array
     * @throws ConnectException
     */
    final protected function buildJoinFromMig(MigrateInterface $mig, string $type): array
    {
        $joinArr = array();
        $prefix = Connect::getInstance()->getHandler()->getPrefix();
        $main = $this->getMainFKData();
        $data = $mig->getData();
        $this->mig->mergeData($data);
        $migTable = $mig->getTable();

        foreach ($data as $col => $row) {
            if (isset($row['fk'])) {
                foreach ($row['fk'] as $a) {
                    if ($a['table'] === (string)$this->table) {
                        $joinArr[] = "$type JOIN " . $prefix . $migTable . " " . $migTable .
                        " ON (" . $migTable . ".$col = {$a['table']}.{$a['column']})";
                    }
                }
            } else {
                foreach ($main as $c => $a) {
                    foreach ($a as $t => $d) {
                        if (in_array($col, $d)) {
                            $joinArr[] = "$type JOIN " . $prefix . $migTable . " " . $migTable .
                            " ON ($t.$col = $this->alias.$c)";
                        }
                    }
                }
            }

            $this->joinedTables[$migTable] = $prefix . $migTable;
        }
        return $joinArr;
    }


    /**
     * Build on YB to col sql string part
     * @return string|null
     */
    protected function getAllQueryTables(): ?string
    {
        if (!is_null($this->joinedTables)) {
            $columns = $this->joinedTables;
            array_unshift($columns, $this->getTable());
            return implode(",", $columns);
        }
        return null;
    }

    /**
     * Query result
     * @param string|self $sql
     * @param string|null $method
     * @param array $args
     * @return array|object|bool|string
     * @throws DBQueryException
     */
    final protected function query(string|self $sql, ?string $method = null, array $args = []): array|object|bool|string
    {
        $query = new Query($sql);
        $query->setPluck($this->pluck);
        if (!is_null($method)) {
            if (method_exists($query, $method)) {
                return call_user_func_array([$query, $method], $args);
            }
            throw new DBQueryException("Method \"$method\" does not exists!", 1);
        }
        return $query;
    }
}