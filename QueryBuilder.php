<?php

/**
 * Query SQL string Builder
 *
 * This class will access the protected objects as "readonly" from the DB class.
 * But as the readonly property only is available in 8.1, and I want this class
 * to be supported as for PHP 8.0+, This will be the solution for a couple of years
 */

declare(strict_types=1);

namespace MaplePHP\Query;

use RuntimeException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Interfaces\QueryBuilderInterface;
use MaplePHP\Query\Utility\Helpers;
use MaplePHP\Query\Utility\Attr;

class QueryBuilder implements QueryBuilderInterface
{
    private DBInterface $db;
    private array $set = [];
    private ?array $linkedTables = null;

    public function __construct(DBInterface $sql)
    {
        $this->db = $sql;
    }

    public function __toString(): string
    {
        return $this->sql();
    }

    /**
     * Main select builder
     * @return string
     * @throws ConnectException
     */
    public function select(): string
    {
        $explain = $this->getExplain();
        $noCache = $this->getNoCache();
        $columns = $this->getColumns();
        $distinct = $this->getDistinct();
        $join = $this->getJoin();
        $where = $this->getWhere("WHERE", $this->db->where);
        $having = $this->getWhere("HAVING", $this->db->having);
        $order = $this->getOrder();
        $limit = $this->getLimit();
        $group = $this->getGroup();
        $union = $this->getUnion();

        return "{$explain}SELECT $noCache$distinct$columns FROM " .
            $this->getTable() . "$join$where$group$having$order$limit$union";
    }

    /**
     * Main insert builder
     * @return string
     * @throws ConnectException
     */
    public function insert(): string
    {
        $explain = $this->getExplain();
        $set = $this->buildSet();
        $duplicate = $this->buildDuplicate();
        $returning = $this->buildReturning();

        return "{$explain}INSERT INTO " .
            $this->db->table . "$set$duplicate$returning";
    }

    /**
     * Main update builder
     * @return string
     * @throws ConnectException
     */
    public function update(): string
    {
        $explain = $this->getExplain();
        $join = $this->getJoin();
        $set = $this->buildSet();
        $where = $this->getWhere("WHERE", $this->db->where);
        $order = $this->getOrder();
        $limit = $this->getLimit();
        $returning = $this->buildReturning();
        return "{$explain}UPDATE " .
            $this->getTable() . "$join SET$set$where$order$limit$returning";
    }

    /**
     * Main delete builder
     * @return string
     * @throws ConnectException
     */
    public function delete(): string
    {
        $explain = $this->getExplain();
        $join = $this->getJoin();
        $linkedTables = $this->buildLinkedTables();
        $where = $this->getWhere("WHERE", $this->db->where);
        $limit = $this->getLimit();
        $returning = $this->buildReturning();
        return "{$explain}DELETE$linkedTables FROM " .
            $this->getTable() . "$join$where$limit$returning";
    }

    public function getTable(): string
    {
        if ($this->db->table === null) {
            throw new RuntimeException("Could not generate any SQL code because the database table is missing!");
        }
        return Helpers::addAlias($this->db->table, $this->db->alias);
    }

    /**
     * Get sql code
     * @return string
     * @throws ConnectException
     */
    public function sql(): string
    {
        return match ($this->db->queryType) {
            "insert" => $this->insert(),
            "update" => $this->update(),
            "delete" => $this->delete(),
            default => $this->select()
        };
    }

    /**
     * Optimizing Queries with EXPLAIN
     * @return string
     */
    protected function getExplain(): string
    {
        return ($this->db->explain) ? "EXPLAIN " : "";
    }

    /**
     * The SELECT DISTINCT statement is used to return only distinct (different) values
     * @return string
     */
    protected function getDistinct(): string
    {
        return ($this->db->distinct) ? "DISTINCT " : "";
    }

    /**
     * The server does not use the query cache.
     * DEPRECATED
     * @return string
     */
    protected function getNoCache(): string
    {
        return ($this->db->noCache) ? "SQL_NO_CACHE " : "";
    }

    /**
     * The SELECT columns
     * @return string
     */
    protected function getColumns(): string
    {
        if ($this->db->columns === null) {
            return "*";
        }
        $create = [];
        $columns = $this->db->columns;
        foreach ($columns as $row) {
            $create[] = Helpers::addAlias($row['column'], $row['alias'], "AS");
        }
        return implode(",", $create);
    }

    /**
     * Order rows by
     * @return string
     */
    protected function getOrder(): string
    {
        return ($this->db->order !== null) ?
            " ORDER BY " . implode(",", Helpers::getOrderBy($this->db->order)) : "";
    }

    /**
     * The GROUP BY statement groups rows that have the same values into summary rows
     * @return string
     */
    protected function getGroup(): string
    {
        return ($this->db->group !== null) ? " GROUP BY " . implode(",", $this->db->group) : "";
    }

    /**
     * Will build where string
     * @param string $prefix
     * @param array|null $where
     * @param array $set
     * @return string
     */
    protected function getWhere(string $prefix, ?array $where, array &$set = []): string
    {
        $out = "";
        if ($where !== null) {
            $out = " $prefix";
            $index = 0;
            foreach ($where as $array) {
                $firstAnd = key($array);
                $out .= (($index > 0) ? " $firstAnd" : "") . " (";
                $out .= $this->whereArrToStr($array, $set);
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
    protected function getJoin(): string
    {
        $join = "";
        $data = $this->db->join;
        $this->linkedTables = [];
        foreach ($data as $row) {
            $table = Helpers::addAlias($row['table'], $row['alias']);
            $where = $this->getWhere("ON", $row['whereData']);
            $join .= " ". sprintf("%s JOIN %s%s", $row['type'], $table, $where);
            $this->linkedTables[] = $row['alias'];
        }
        return $join;
    }

    /**
     * Build limit
     * @return string
     */
    protected function getLimit(): string
    {
        $limit = $this->db->limit;
        if ($limit === null && $this->db->offset !== null) {
            $limit = 1;
        }
        $limit = $this->getAttrValue($limit);
        $offset = ($this->db->offset !== null) ? "," . $this->getAttrValue($this->db->offset) : "";
        return ($limit !== null) ? " LIMIT $limit $offset" : "";
    }

    /**
     * Build Where data (CAN BE A HELPER?)
     * @param array $array
     * @param array $set
     * @return string
     */
    private function whereArrToStr(array $array, array &$set = []): string
    {
        $out = "";
        $count = 0;
        foreach ($array as $key => $arr) {
            foreach ($arr as $arrB) {
                if (is_array($arrB)) {
                    foreach ($arrB as $row) {
                        if ($count > 0) {
                            $out .= "$key ";
                        }
                        if ($row['not'] === true) {
                            $out .= "NOT ";
                        }

                        $value = $this->getAttrValue($row['value']);
                        $out .= "{$row['column']} {$row['operator']} $value ";
                        $set[] = $row['value'];
                        $count++;
                    }

                } else {
                    // Used to be used as RAW input but is not needed any more
                    die("DELETE???");
                    $out .= ($count) > 0 ? "$key $arrB " : $arrB;
                    $count++;
                }
            }
        }
        return rtrim($out, " ");
    }

    /**
     * Build Update and Insert set data
     * @return string
     */
    public function buildSet(): string
    {
        return " " . match ($this->db->queryType) {
            "insert" => $this->buildInsertSet($this->db->set),
            "update" => $this->buildUpdateSet($this->db->set),
            default => ""
        };
    }

    /**
     * Build on insert set sql string part
     * @param array $set
     * @return string
     */
    protected function buildInsertSet(array $set): string
    {

        $new = [];
        foreach ($set as $row) {
            $value = $this->getAttrValue($row['value']);
            $new[(string)$row['column']] = (string)$value;
        }

        if (count($new) <= 0) {
            throw new RuntimeException("There is nothing to insert, you need to specify a SET to insert.");
        }
        $columns = array_keys($new);
        $columns = implode(",", $columns);
        $values = implode(",", $new);
        return "($columns) VALUES ($values)";
    }

    /**
     * Build on update set sql string part
     * @param array $set
     * @return string
     */
    protected function buildUpdateSet(array $set): string
    {
        $new = [];
        foreach ($set as $key => $row) {
            $value = $this->getAttrValue($row['value']);
            $new[] = "{$row['column']} = $value";
        }
        if (count($new) <= 0) {
            throw new RuntimeException("There is nothing to update, you need to specify a SET to update.");
        }
        return implode(",", $new);
    }

    /**
     * Build on duplicate sql string part
     * @return string
     */
    private function buildDuplicate(): string
    {
        if (is_array($this->db->onDupKey)) {
            $set = (count($this->db->onDupKey) > 0) ? $this->db->onDupKey : $this->db->set;
            return " ON DUPLICATE KEY UPDATE " . $this->buildUpdateSet($set);
        }
        return "";
    }

    /**
     * Will build a returning value that can be fetched with insert id
     * This is a PostgreSQL specific function.
     * @return string
     * @throws ConnectException
     */
    private function buildReturning(): string
    {
        if ($this->db->returning !== null && $this->db->getHandler()->getType() === "postgresql") {
            return " RETURNING {$this->db->returning}";
        }
        return "";
    }

    /**
     * Get Union sql
     * @return string
     * @throws ConnectException
     */
    public function getUnion(): string
    {
        $union = $this->db->union;
        if ($union !== null) {

            $sql = "";
            foreach ($union as $row) {
                $inst = new self($row['inst']);
                $sql .= "  UNION " . $inst->sql();
            }

            return $sql;
        }
        return "";
    }

    /**
     * Find all table in all joins and main query
     * @return string
     */
    public function buildLinkedTables(): string
    {
        if ($this->linkedTables === null) {
            throw new \BadMethodCallException("You need to call buildJoin method before this method.");
        }

        if (count($this->linkedTables) > 0) {
            $columns = $this->linkedTables;
            array_unshift($columns, $this->db->alias);

            return " " . implode(",", $columns);
        }
        return "";
    }

    /**
     * Get attribute as value item
     * @param $value
     * @return string|null
     */
    public function getAttrValue($value): ?string
    {
        if ($this->db->prepare) {
            if ($value instanceof AttrInterface && ($value->isType(Attr::VALUE_TYPE) ||
                    $value->isType(Attr::VALUE_TYPE_NUM) || $value->isType(Attr::VALUE_TYPE_STR))) {
                $this->set[] = $value->type(Attr::RAW_TYPE);
                return "?";
            }
        }
        return $value === null ? null : (string)$value;
    }

    /**
     * Get set
     * @return array
     */
    public function getSet(): array
    {
        return $this->set;
    }

}
