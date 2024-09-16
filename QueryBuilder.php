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
use MaplePHP\Query\Utility\Helpers;
use MaplePHP\Query\Utility\Attr;

class QueryBuilder
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
        $where = $this->getWhere("WHERE", $this->db->__get('where'));
        $having = $this->getWhere("HAVING", $this->db->__get('having'));
        $order = $this->getOrder();
        $limit = $this->getLimit();
        $group = $this->getGroup();
        $union = $this->getUnion();

        return "{$explain}SELECT $noCache$distinct$columns FROM " .
            $this->getTable() . "$join$where$group$having$order$limit$union";
    }

    public function getTable(): string
    {
        return Helpers::addAlias($this->db->__get('table'), $this->db->__get('alias'));
    }

    public function sql(): string
    {

        return $this->select();
        /*
         if(is_null($this->sql)) {
            $sql = $this->buildSelect();
            $set = $this->set;

            if($this->prepare) {
                $set = array_pad([], count($this->set), "?");

            }
            $this->sql = vsprintf($sql, $set);
        }
        //array_pad([], count($whereSet), "?");
        //$rawSql = vsprintf($rawSql, $this->set);
        return $this->sql;
         */
    }

    /**
     * Optimizing Queries with EXPLAIN
     * @return string
     */
    protected function getExplain(): string
    {
        return ($this->db->__get('explain')) ? "EXPLAIN " : "";
    }

    /**
     * The SELECT DISTINCT statement is used to return only distinct (different) values
     * @return string
     */
    protected function getDistinct(): string
    {
        return ($this->db->__get('distinct')) ? "DISTINCT " : "";
    }

    /**
     * The server does not use the query cache.
     * @return string
     */
    protected function getNoCache(): string
    {
        return ($this->db->__get('noCache')) ? "SQL_NO_CACHE " : "";
    }

    /**
     * The SELECT columns
     * @return string
     */
    protected function getColumns(): string
    {
        if(is_null($this->db->__get('columns'))) {
            return "*";
        }
        $create = [];
        $columns = $this->db->__get('columns');
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
        return (!is_null($this->db->__get('order'))) ?
            " ORDER BY " . implode(",", Helpers::getOrderBy($this->db->__get('order'))) : "";
    }

    /**
     * The GROUP BY statement groups rows that have the same values into summary rows
     * @return string
     */
    protected function getGroup(): string
    {
        return (!is_null($this->db->__get('group'))) ? " GROUP BY " . implode(",", $this->db->__get('group')) : "";
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
        $data = $this->db->__get("join");
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
        $limit = $this->db->__get('limit');
        if (is_null($limit) && !is_null($this->db->__get('offset'))) {
            $limit = 1;
        }
        $limit = $this->getAttrValue($limit);
        $offset = (!is_null($this->db->__get("offset"))) ? "," . $this->getAttrValue($this->db->__get("offset")) : "";
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


    function getUnion(): string
    {
        $union = $this->db->__get('union');
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

    public function getAttrValue($value): ?string
    {
        if($this->db->__get('prepare')) {

            if($value instanceof AttrInterface && ($value->isType(Attr::VALUE_TYPE) ||
                    $value->isType(Attr::VALUE_TYPE_NUM) || $value->isType(Attr::VALUE_TYPE_STR))) {
                $this->set[] = $value->type(Attr::RAW_TYPE);
                return "?";
            }
        }
        return is_null($value) ? null : (string)$value;
    }

    public function getSet(): array
    {
        if(!$this->db->__get('prepare')) {
            throw new RuntimeException("Prepare method not available");
        }
        return $this->set;
    }

}
