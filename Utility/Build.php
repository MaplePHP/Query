<?php

namespace MaplePHP\Query\Utility;

use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Connect;

class Build
{
   
    protected const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
    protected const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types
    protected const VIEW_PREFIX_NAME = "view"; // View prefix

    private $select;

    protected $table;
    protected $join;
    protected $joinedTables;
    protected $limit;
    protected $offset;

    protected $fkData;
    protected $mig;

    protected $attr;


    
    public function __construct(object|array|null $obj = null)
    {

        $this->attr = new \stdClass();
        if (!is_null($obj)) foreach($obj as $key => $value) {
            $this->attr->{$key} = $value;
        }
    }


    /**
     * Will build where string
     * @param  string $prefix
     * @param  array  $where
     * @return string
     */
    public function where(string $prefix, ?array $where): string
    {
        $out = "";
        if (!is_null($where)) {
            $out = " {$prefix}";
            $index = 0;
            foreach ($where as $array) {
                $firstAnd = key($array);
                $out .= (($index > 0) ? " {$firstAnd}" : "") . " (";
                $out .= $this->whereArrToStr($array);
                $out .= ")";
                $index++;
            }
        }
        return $out;
    }


    /**
     * Build joins
     * @return string
     */
    public function join(
        string|array|MigrateInterface $table,
        string|array $where = null,
        array $sprint = array(),
        string $type = "INNER"
    ): string
    {
        if ($table instanceof MigrateInterface) {
            $this->join = array_merge($this->join, $this->buildJoinFromMig($table, $type));
        } else {
            $this->buildJoinFromArgs($table, $where, $sprint, $type);
        }
        return (is_array($this->join)) ? " " . implode(" ", $this->join) : "";
    }

    /**
     * Build limit
     * @return string
     */
    public function limit(): string
    {
        if (is_null($this->attr->limit) && !is_null($this->attr->offset)) {
            $this->attr->limit = 1;
        }
        $offset = (!is_null($this->attr->offset)) ? ",{$this->attr->offset}" : "";
        return (!is_null($this->attr->limit)) ? " LIMIT {$this->attr->limit}{$offset}" : "";
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
            foreach ($arr as $operator => $a) {
                if (is_array($a)) {
                    foreach ($a as $col => $b) {
                        foreach ($b as $val) {
                            if ($count > 0) {
                                $out .= "{$key} ";
                            }
                            $out .= "{$col} {$operator} {$val} ";
                            $count++;
                        }
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
     * Build join data from Migrate data
     * @param  MigrateInterface $mig
     * @param  string           $type Join type (INNER, LEFT, ...)
     * @return array
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
                    if ($a['table'] === (string)$this->attr->table) {
                        $joinArr[] = "{$type} JOIN " . $prefix . $migTable . " " . $migTable .
                        " ON (" . $migTable . ".{$col} = {$a['table']}.{$a['column']})";
                    }
                }
            } else {
                foreach ($main as $c => $a) {
                    foreach ($a as $t => $d) {
                        if (in_array($col, $d)) {
                            $joinArr[] = "{$type} JOIN " . $prefix . $migTable . " " . $migTable .
                            " ON ({$t}.{$col} = {$this->attr->alias}.{$c})";
                        }
                    }
                }
            }

            $this->joinedTables[$migTable] = $prefix . $migTable;
        }
        return $joinArr;
    }


    protected function buildJoinFromArgs(
        string|array $table,
        string|array $where,
        array $sprint = array(),
        string $type = "INNER"
    ): void
    {
        if (is_null($where)) {
            throw new \InvalidArgumentException("You need to specify the argumnet 2 (where) value!", 1);
        }

        $prefix = Connect::getInstance()->getHandler()->getPrefix();
        $arr = $this->sperateAlias($table);
        $table = (string)$this->prep($arr['table'], false);
        $alias = (!is_null($arr['alias'])) ? " {$arr['alias']}" : " {$table}";

        if (is_array($where)) {
            $data = array();
            foreach ($where as $key => $val) {
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        $this->setWhereData($k, $v, $data);
                    }
                } else {
                    $this->setWhereData($key, $val, $data);
                }
            }
            $out = $this->buildWhere("", $data);
        } else {
            $out = $this->sprint($where, $sprint);
        }
        $type = $this->joinTypes(strtoupper($type)); // Whitelist
        $this->join[] = "{$type} JOIN {$prefix}{$table}{$alias} ON " . $out;
        $this->joinedTables[$table] = "{$prefix}{$table}";
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
     * Sperate Alias
     * @param  string|array  $data
     * @return array
     */
    final protected function sperateAlias(string|array|DBInterface $data): array
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
     * Mysql Prep/protect string
     * @param  mixed $val
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
     * Get new Attr instance
     * @param  array|string|int|float $value
     * @return AttrInterface
     */
    protected function getAttr(array|string|int|float $value): AttrInterface
    {
        return new Attr($value);
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
}
