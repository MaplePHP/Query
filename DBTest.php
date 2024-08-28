<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use MaplePHP\Query\Utility\Helpers;
use MaplePHP\Query\Exceptions\ResultException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Utility\WhitelistMigration;

/**
 * @method pluck(string $string)
 */
class DBTest
{

    private ConnectInterface $connection;
    private ?WhitelistMigration $migration = null;

    private string $prefix;
    private string|AttrInterface $table;
    private ?string $alias = null;
    private ?string $pluck = null;
    private string|array $columns;
    private string|array $order;
    private string $compare = "=";
    private bool $whereNot = false;
    private string $whereAnd = "AND";
    private int $whereIndex = 0;
    private ?array $where;
    private int $limit;
    private int $offset;
    private array $group;
    private string $returning;
    private array $joinedTables;

    /**
     * @throws ConnectException
     */
    public function __construct(HandlerInterface $handler)
    {
        $this->connection = $handler->execute();
        $this->prefix = $handler->getPrefix();
    }

    /**
     * Used to make methods into dynamic shortcuts
     * @param string $method
     * @param array $args
     * @return array|bool|object|string
     * @throws ResultException
     */
    public function __call(string $method, array $args): array|bool|object|string
    {
        $camelCaseArr = Helpers::extractCamelCase($method);
        $shift = array_shift($camelCaseArr);

        switch ($shift) {
            case "pluck": // Columns??
                $args = ($args[0] ?? "");
                if (str_contains($args, ",")) {
                    throw new ResultException("Your only allowed to pluck one database column!");
                }

                $pluck = explode(".", $args);
                $this->pluck = trim(end($pluck));
                $this->columns($args);
                break;
            case "where":
            case "having":
                Helpers::camelLoop($camelCaseArr, $args, function ($col, $val) use ($shift) {
                    $this->{$shift}($col, $val);
                });
                break;
            case "order":
                if ($camelCaseArr[0] === "By") {
                    array_shift($camelCaseArr);
                }
                $ace = end($camelCaseArr);
                foreach ($args as $val) {
                    $this->order($val, $ace);
                }
                break;
            case "join":
                $this->join($args[0], ($args[1] ?? null), ($args[2] ?? []), $camelCaseArr[0]);
                break;
            default:
                return $this->query($this, $method, $args);
        }
        return $this;
    }

    /**
     * @param string|array|MigrateInterface $table
     * @return DBTest
     */
    public function table(string|array|MigrateInterface $table): self
    {
        $inst = clone $this;

        if ($table instanceof MigrateInterface) {
            $inst->migration = new WhitelistMigration($table);
            $table = $inst->migration->getTable();
        }

        $table = Helpers::separateAlias($table);
        $inst->alias = $table['alias'];
        $inst->table = Helpers::getAttr($table['table'])->enclose(false);
        if (is_null($inst->alias)) {
            $inst->alias = $inst->table;
        }

        return $inst;
    }

    /**
     * Select protected mysql columns
     * @param  string|array $columns
     * @return self
     */
    public function columns(string|array|AttrInterface ...$columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Change where compare operator from default "=".
     * Will change back to default after where method is triggered
     * @param  string $operator once of (">", ">=", "<", "<>", "!=", "<=", "<=>")
     * @return self
     */
    public function compare(string $operator): self
    {
        $this->compare = Helpers::operator($operator);
        return $this;
    }

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function and(): self
    {
        $this->whereAnd = "AND";
        return $this;
    }

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function or(): self
    {
        $this->whereAnd = "OR";
        return $this;
    }

    /**
     * Chaining with where "NOT" ???
     * @return self
     */
    public function not(): self
    {
        $this->whereNot = true;
        return $this;
    }


    /**
     * Create protected MySQL WHERE input
     * Supports dynamic method name calls like: whereIdStatus(1, 0)
     * @param string|AttrInterface $key Mysql column
     * @param string|int|float|AttrInterface $val Equals to value
     * @param string|null $operator Change comparison operator from default "=".
     * @return self
     */
    public function where(string|AttrInterface $key, string|int|float|AttrInterface $val, ?string $operator = null): self
    {
        // Whitelist operator
        if (!is_null($operator)) {
            $this->compare = Helpers::operator($operator);
        }
        $this->setWhereData($key, $val, $this->where);
        return $this;
    }

    /**
     * Set Mysql ORDER
     * @param string|AttrInterface $col Mysql Column
     * @param string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
     * @return self
     */
    public function order(string|AttrInterface $col, string $sort = "ASC"): self
    {
        // PREP AT BUILD
        //$col = $this->prep($col, false);
        /*
        if (!is_null($this->migration) && !$this->migration->columns([(string)$col])) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */
        $sort = Helpers::orderSort($sort); // Whitelist
        $this->order[] = "$col $sort";
        return $this;
    }

    /**
     * Add a limit and maybe an offset
     * @param  int      $limit
     * @param  int|null $offset
     * @return self
     */
    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        if (!is_null($offset)) {
            $this->offset = $offset;
        }
        return $this;
    }

    /**
     * Add an offset (if limit is not set then it will automatically become "1").
     * @param  int    $offset
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }


    /**
     * Add group
     * @param array $columns
     * @return self
     */
    public function group(array ...$columns): self
    {
        /*
         if (!is_null($this->migration) && !$this->migration->columns($columns)) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */
        $this->group = $columns;
        return $this;
    }

    /**
     * Postgre specific function
     * @param string $column
     * @return $this
     */
    public function returning(string $column): self
    {
        $this->returning = $column;
        return $this;
    }

    /**
     * Mysql JOIN query (Default: INNER)
     * @param string|array|MigrateInterface $table Mysql table name (if array e.g. [TABLE_NAME, ALIAS]) or MigrateInterface instance
     * @param string|array|null $where Where data (as array or string e.g. string is raw)
     * @param array $sprint Use sprint to prep data
     * @param string $type Type of join
     * @return self
     * @throws ConnectException
     * @throws ResultException
     * @throws DBValidationException
     */
    public function join(
        string|array|MigrateInterface $table,
        string|array $where = null,
        array $sprint = array(),
        string $type = "INNER"
    ): self {

        if ($table instanceof MigrateInterface) {
            $this->join = array_merge($this->join, $this->buildJoinFromMig($table, $type));
        } else {
            if (is_null($where)) {
                throw new ResultException("You need to specify the argument 2 (where) value!", 1);
            }

            $prefix = $this->connInst()->getHandler()->getPrefix();
            $arr = Helpers::separateAlias($table);
            $table = $arr['table'];
            $alias = (!is_null($arr['alias'])) ? " {$arr['alias']}" : " $table";

            $data = array();
            if (is_array($where)) {
                foreach ($where as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $grpKey => $grpVal) {
                            if(!($grpVal instanceof AttrInterface)) {
                                $grpVal = Helpers::withAttr($grpVal)->enclose(false);
                            }
                            $this->setWhereData($grpKey, $grpVal, $data);
                        }
                    } else {
                        if(!($val instanceof AttrInterface)) {
                            $val = Helpers::withAttr($val)->enclose(false);
                        }
                        $this->setWhereData($key, $val, $data);
                    }
                }
                //$out = $this->buildWhere("", $data);
            } else {
                //$out = $this->sprint($where, $sprint);
            }
            $type = Helpers::joinTypes(strtoupper($type)); // Whitelist

            $this->join[] = [
                "type" => $type,
                "prefix" => $prefix,
                "table" => $this->prefix . $table,
                "alias" => $alias,
                "where" => $where,
                "whereData" => $data,
                "sprint" => $sprint
            ];
            $this->joinedTables[$table] = "$prefix$table";
        }
        return $this;
    }
    
    function buildSelect() {

    }

    /**
     HELPERS
     */


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
        $prefix = $this->connInst()->getHandler()->getPrefix();
        $main = $this->getMainFKData();
        $data = $mig->getData();
        $this->migration->mergeData($data);
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
     * Propagate where data structure
     * @param string|AttrInterface $key
     * @param string|int|float|AttrInterface $val
     * @param array|null &$data static value
     */
    final protected function setWhereData(string|AttrInterface $key, string|int|float|AttrInterface $val, ?array &$data): void
    {
        if (is_null($data)) {
            $data = array();
        }
        /*
        $key = (string)$this->prep($key, false);
        $val = $this->prep($val);
        if (!is_null($this->migration) && !$this->migration->where($key, $val)) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */

        $data[$this->whereIndex][$this->whereAnd][$key][] = [
            "not" => $this->whereNot,
            "operator" => $this->compare,
            "value" => $val
        ];

        $this->resetWhere();
    }


    /**
     * Group mysql WHERE inputs
     * @param  callable $call  Every method where placed inside callback will be grouped.
     * @return self
     */
    public function whereBind(callable $call): self
    {
        if (!is_null($this->where)) {
            $this->whereIndex++;
        }
        $this->resetWhere();
        $call($this);
        $this->whereIndex++;
        return $this;
    }


    /**
     * Will reset Where input
     * @return void
     */
    protected function resetWhere(): void
    {
        $this->whereNot = false;
        $this->whereAnd = "AND";
        $this->compare = "=";
    }

    /**
     * Query result
     * @param string|self $sql
     * @param string|null $method
     * @param array $args
     * @return array|object|bool|string
     * @throws ResultException
     */
    final protected function query(string|self $sql, ?string $method = null, array $args = []): array|object|bool|string
    {
        $query = new Query($sql, $this->connection);
        $query->setPluck($this->pluck);
        if (!is_null($method)) {
            if (method_exists($query, $method)) {
                return call_user_func_array([$query, $method], $args);
            }
            throw new ResultException("Method \"$method\" does not exists!", 1);
        }
        return $query;
    }

}