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

use http\Exception\RuntimeException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Interfaces\QueryBuilderInterface;
use MaplePHP\Query\Utility\Helpers;
use MaplePHP\Query\Utility\Attr;

class QueryBuilder implements QueryBuilderInterface
{
    private DBInterface $db;
    private array $set = [];

    public function __construct(DBInterface $sql)
    {
        $this->db = $sql;
    }

    public function __toString(): string
    {
        return $this->sql();
    }

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

    public function getTable(): string
    {
        return Helpers::addAlias($this->db->table, $this->db->alias);
    }

    /**
     * Get sql code
     * @return string
     */
    public function sql(): string
    {
        return $this->select();
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
        if(is_null($this->db->columns)) {
            return "*";
        }
        $create = [];
        $columns = $this->db->columns;
        foreach($columns as $row) {
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
        return (!is_null($this->db->order)) ?
            " ORDER BY " . implode(",", Helpers::getOrderBy($this->db->order)) : "";
    }

    /**
     * The GROUP BY statement groups rows that have the same values into summary rows
     * @return string
     */
    protected function getGroup(): string
    {
        return (!is_null($this->db->group)) ? " GROUP BY " . implode(",", $this->db->group) : "";
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
        if (!is_null($where)) {
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
        foreach ($data as $row) {
            $table = Helpers::addAlias($row['table'], $row['alias']);
            $where = $this->getWhere("ON", $row['whereData']);
            $join .= " ". sprintf("%s JOIN %s%s", $row['type'], $table, $where);
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
        if (is_null($limit) && !is_null($this->db->offset)) {
            $limit = 1;
        }
        $limit = $this->getAttrValue($limit);
        $offset = (!is_null($this->db->offset)) ? "," . $this->getAttrValue($this->db->offset) : "";
        return (!is_null($limit)) ? " LIMIT $limit $offset" : "";
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
                        $out .= "{$row['column']} {$row['operator']} {$value} ";
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
     * Get Union sql
     * @return string
     */
    public function getUnion(): string
    {
        $union = $this->db->union;
        if(!is_null($union)) {

            $sql = "";
            foreach($union as $row) {
                $inst = new self($row['inst']);
                $sql .= "  UNION " . $inst->sql();
            }

            return $sql;
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
        if($this->db->prepare) {
            if($value instanceof AttrInterface && ($value->isType(Attr::VALUE_TYPE) ||
                    $value->isType(Attr::VALUE_TYPE_NUM) || $value->isType(Attr::VALUE_TYPE_STR))) {
                $this->set[] = $value->type(Attr::RAW_TYPE);
                return "?";
            }
        }
        return is_null($value) ? null : (string)$value;
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
