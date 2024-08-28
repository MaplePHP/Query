<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\DBQueryException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Exceptions\ResultException;
//use MaplePHP\Query\Utility\Attr;
use MaplePHP\Query\Utility\WhitelistMigration;

/**
 * @method pluck(string $string)
 */
class DB extends AbstractDB
{
    private $method;
    private $explain;
    private $where;
    private $having;
    private $set = [];
    private $dupSet;
    private $limit;
    private $offset;
    private $order;
    private $join = [];
    private $distinct;
    private $group;
    private $noCache;
    private $calRows;
    private $union;
    private $viewName;
    private $sql;
    private $dynamic;
    private ?string $returning = null;

    /**
     * It is a semi-dynamic method builder that expects certain types of objects to be set
     * @param string $method
     * @param array $args
     * @return self
     * @throws ConnectException
     * @throws ResultException
     */
    public static function __callStatic(string $method, array $args)
    {
        if (count($args) > 0) {
            $defaultArgs = $args;
            $table = array_pop($args);
            $inst = self::table($table);
            $inst->method = $method;
            //$inst->setConnKey(Connect::$current);
            $prefix = $inst->connInst()->getHandler()->getPrefix();

            switch ($inst->method) {
                case 'select':
                case 'selectView':
                    if ($inst->method === "selectView") {
                        $inst->table = static::VIEW_PREFIX_NAME . "_" . $inst->table;
                    }
                    $col = explode(",", $args[0]);
                    call_user_func_array([$inst, "columns"], $col);
                    break;
                case 'createView':
                case 'replaceView':
                    $encodeArg1 = $inst->getAttr($defaultArgs[0])->enclose(false);
                    $inst->viewName = $prefix . static::VIEW_PREFIX_NAME . "_" . $encodeArg1;
                    $inst->sql = $defaultArgs[1];
                    break;
                case 'dropView':
                case 'showView':
                    $encodeArg1 = $inst->getAttr($defaultArgs[0])->enclose(false);
                    $inst->viewName = $prefix . static::VIEW_PREFIX_NAME . "_" . $encodeArg1;
                    break;
                default:
                    $inst->dynamic = [[$inst, $inst->method], $args];
                    break;
            }

        } else {
            $inst = new self();
            //$inst->setConnKey(Connect::$current);
        }

        return $inst;
    }

    /**
     * Used to make methods into dynamic shortcuts
     * @param string $method
     * @param array $args
     * @return array|bool|DB|object
     * @throws ResultException
     * @throws DBValidationException|ConnectException|ResultException|Exceptions\DBQueryException
     */
    public function __call(string $method, array $args)
    {
        $camelCaseArr = $this->extractCamelCase($method);
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
                $this->camelLoop($camelCaseArr, $args, function ($col, $val) use ($shift) {
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
     * You can build queries like Laravel If you want. I do not think they have good semantics tho.
     * It is better to use (DB::select, DB::insert, DB::update, DB::delete)
     * @param string|array|MigrateInterface $data
     * @return self new instance
     * @throws ResultException
     */
    public static function table(string|array|MigrateInterface $data): self
    {
        $mig = null;
        if ($data instanceof MigrateInterface) {
            $mig = new WhitelistMigration($data);
            $data = $mig->getTable();
        }

        $inst = new self();
        $data = $inst->separateAlias($data);
        $inst->alias = $data['alias'];
        $inst->table = $inst->getAttr($data['table'])->enclose(false);
        $inst->mig = $mig;
        $inst->setConnKey(Connect::$current);

        if (is_null($inst->alias)) {
            $inst->alias = $inst->table;
        }
        return $inst;
    }

    /**
     * Access Query Attr class
     * @param array|string|int|float $value
     * @param array|null $args
     * @return AttrInterface
     * @throws DBValidationException
     */
    public static function withAttr(array|string|int|float $value, ?array $args = null): AttrInterface
    {
        $inst = new self();
        $inst = $inst->getAttr($value);
        if (!is_null($args)) {
            foreach ($args as $method => $arg) {
                if (!method_exists($inst, $method)) {
                    throw new DBValidationException("The Query Attr method \"" .htmlspecialchars($method, ENT_QUOTES). "\" does not exists!", 1);
                }
                $inst = call_user_func_array([$inst, $method], (!is_array($arg) ? [$arg] : $arg));
            }
        }
        return $inst;
    }

    /**
     * Build SELECT sql code (The method will be auto called in method build)
     * @method static __callStatic
     * @return self
     * @throws DBValidationException|ConnectException
     */
    protected function select(): self
    {
        $columns = is_null($this->columns) ? "*" : implode(",", $this->getColumns());
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $having = $this->buildWhere("HAVING", $this->having);
        $order = (!is_null($this->order)) ? " ORDER BY " . implode(",", $this->order) : "";
        $limit = $this->buildLimit();
        $this->sql = "{$this->explain}SELECT $this->noCache$this->calRows$this->distinct$columns FROM " .
        $this->getTable(true) . "$join$where$this->group$having$order$limit$this->union";
        return $this;
    }

    /**
     * Select view
     * @return self
     * @throws DBValidationException
     * @throws ConnectException
     */
    protected function selectView(): self
    {
        return $this->select();
    }

    /**
     * Build INSERT sql code (The method will be auto called in method build)
     * @return self
     * @throws ConnectException
     */
    protected function insert(): self
    {
        $this->sql = "{$this->explain}INSERT INTO " . $this->getTable() . " " .
        $this->buildInsertSet() . $this->buildDuplicate() . $this->buildReturning();
        return $this;
    }

    /**
     * Build UPDATE sql code (The method will be auto called in method build)
     * @return self
     * @throws ConnectException
     */
    protected function update(): self
    {
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $limit = $this->buildLimit();

        $this->sql = "{$this->explain}UPDATE " . $this->getTable() . "$join SET " .
        $this->buildUpdateSet() . "$where$limit}"  . $this->buildReturning();
        return $this;
    }

    /**
     * Build DELETE sql code (The method will be auto called in method build)
     * @return self
     * @throws ConnectException
     */
    protected function delete(): self
    {
        $linkedTables = $this->getAllQueryTables();
        if (!is_null($linkedTables)) {
            $linkedTables = " $linkedTables";
        }
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $limit = $this->buildLimit();

        $this->sql = "{$this->explain}DELETE$linkedTables FROM " . $this->getTable() . "$join$where$limit";
        return $this;
    }

    /**
     * Select protected mysql columns
     * @param  string $columns
     * @return self
     */
    public function columns(...$columns): self
    {
        $this->columns = $this->prepArr($columns, false);
        return $this;
    }

    /**
     * Select unprotected mysql columns
     * @param  string $columns
     * @return self
     */
    public function columnsRaw(string $columns): self
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
        $this->compare = $this->operator($operator);
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
     * Chaining with where "NOT"
     * @return self
     */
    public function not(): self
    {
        $this->whereNot = true;
        return $this;
    }

    /**
     * Raw Mysql Where input
     * Uses sprint to mysql prep/protect input in string. Prep string values needs to be enclosed manually
     * @param  string    $sql     SQL string example: (id = %d AND permalink = '%s')
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function whereRaw(string $sql, ...$arr): self
    {
        if (is_array($arr[0] ?? null)) {
            $arr = $arr[0];
        }
        $this->where[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
        $this->resetWhere();
        return $this;
    }

    /**
     * Create protected MySQL WHERE input
     * Supports dynamic method name calls like: whereIdStatus(1, 0)
     * @param string|AttrInterface $key Mysql column
     * @param string|int|float|AttrInterface $val Equals to value
     * @param string|null $operator Change comparison operator from default "=".
     * @return self
     * @throws DBValidationException
     */
    public function where(string|AttrInterface $key, string|int|float|AttrInterface $val, ?string $operator = null): self
    {
        // Whitelist operator
        if (!is_null($operator)) {
            $this->compare = $this->operator($operator);
        }
        $this->setWhereData($key, $val, $this->where);
        return $this;
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
     * Create protected MySQL HAVING input
     * @param string|AttrInterface $key Mysql column
     * @param string|int|float|AttrInterface $val Equals to value
     * @param string|null $operator Change comparison operator from default "=".
     * @return self
     * @throws DBValidationException
     */
    public function having(string|AttrInterface $key, string|int|float|AttrInterface $val, ?string $operator = null): self
    {
        if (!is_null($operator)) {
            $this->compare = $this->operator($operator);
        }
        $this->setWhereData($key, $val, $this->having);
        return $this;
    }

    /**
     * Raw Mysql HAVING input
     * Uses sprint to mysql prep/protect input in string. Prep string values needs to be enclosed manually
     * @param  string    $sql     SQL string example: (id = %d AND permalink = '%s')
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function havingRaw(string $sql, ...$arr): self
    {
        if (is_array($arr[0] ?? null)) {
            $arr = $arr[0];
        }
        $this->having[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
        $this->resetWhere();
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
     * Set Mysql ORDER
     * @param string|AttrInterface $col Mysql Column
     * @param string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
     * @return self
     * @throws DBValidationException
     */
    public function order(string|AttrInterface $col, string $sort = "ASC"): self
    {
        $col = $this->prep($col, false);

        if (!is_null($this->mig) && !$this->mig->columns([(string)$col])) {
            throw new DBValidationException($this->mig->getMessage(), 1);
        }
        $sort = $this->orderSort($sort); // Whitelist
        $this->order[] = "$col $sort";
        return $this;
    }

    /**
     * Raw Mysql ORDER input
     * Uses sprint to mysql prep/protect input in string. Prep string values needs to be closed manually
     * @param  string    $sql     SQL string example: (id ASC, parent DESC)
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function orderRaw(string $sql, ...$arr): self
    {
        if (is_array($arr[0] ?? null)) {
            $arr = $arr[0];
        }
        $this->order[] = $this->sprint($sql, $arr);
        return $this;
    }

    /**
     * Add group
     * @param array $columns
     * @return self
     * @throws DBValidationException
     */
    public function group(...$columns): self
    {
        if (!is_null($this->mig) && !$this->mig->columns($columns)) {
            throw new DBValidationException($this->mig->getMessage(), 1);
        }
        $this->group = " GROUP BY " . implode(",", $this->prepArr($columns, false));
        return $this;
    }

    /**
     * Postgre specific function
     * @param string $column
     * @return $this
     */
    public function returning(string $column): self
    {
        $this->returning = (string)$this->prep($column);
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
            $arr = $this->separateAlias($table);
            $table = (string)$this->prep($arr['table'], false);
            $alias = (!is_null($arr['alias'])) ? " {$arr['alias']}" : " $table";

            if (is_array($where)) {
                $data = array();
                foreach ($where as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $grpKey => $grpVal) {
                            if(!($grpVal instanceof AttrInterface)) {
                                $grpVal = $this::withAttr($grpVal)->enclose(false);
                            }
                            $this->setWhereData($grpKey, $grpVal, $data);
                        }
                    } else {
                        if(!($val instanceof AttrInterface)) {
                            $val = $this::withAttr($val)->enclose(false);
                        }
                        $this->setWhereData($key, $val, $data);
                    }
                }
                $out = $this->buildWhere("", $data);
            } else {
                $out = $this->sprint($where, $sprint);
            }
            $type = $this->joinTypes(strtoupper($type)); // Whitelist
            $this->join[] = "$type JOIN $prefix$table$alias ON " . $out;
            $this->joinedTables[$table] = "$prefix$table";
        }
        return $this;
    }

    /**
     * Add make query a distinct call
     * @return self
     */
    public function distinct(): self
    {
        $this->distinct = "DISTINCT ";
        return $this;
    }

    /**
     * Explain the query. Will tell you how you can make improvements
     * All database handlers is supported e.g. mysql, postgresql, sqlite...
     * @return self
     */
    public function explain(): self
    {
        $this->explain = "EXPLAIN ";
        return $this;
    }

    /**
     * Disable mysql query cache
     * All database handlers is supported e.g. mysql, postgresql, sqlite...
     * @return self
     */
    public function noCache(): self
    {
        $this->noCache = "SQL_NO_CACHE ";
        return $this;
    }

    /**
     * DEPRECATE: Calculate rows in query
     * All database handlers is supported e.g. mysql, postgresql, sqlite...
     * @return self
     */
    public function calcRows(): self
    {
        $this->calRows = "SQL_CALC_FOUND_ROWS ";
        return $this;
    }

    /**
     * Create INSERT or UPDATE set Mysql input to insert
     * @param string|array|AttrInterface $key (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param string|array|AttrInterface|null $value If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function set(string|array|AttrInterface $key, string|array|AttrInterface $value = null): self
    {
        if (is_array($key)) {
            $this->set = array_merge($this->set, $this->prepArr($key));
        } else {
            $this->set[(string)$key] = $this->prep($value);
        }
        return $this;
    }

    /**
     * UNPROTECTED: Create INSERT or UPDATE set Mysql input to insert
     * @param string $key   Mysql column
     * @param string $value Input/insert value (UNPROTECTED and Will not enclose)
     */
    public function setRaw(string $key, string $value): self
    {
        $this->set[$key] = $value;
        return $this;
    }

    /**
     * Update if ID KEY is duplicate else insert
     * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function onDupKey($key = null, ?string $value = null): self
    {
        return $this->onDuplicateKey($key, $value);
    }

    // Same as onDupKey
    public function onDuplicateKey($key = null, ?string $value = null): self
    {
        $this->dupSet = array();
        if (!is_null($key)) {
            if (is_array($key)) {
                $this->dupSet = $this->prepArr($key);
            } else {
                $this->dupSet[$key] = $this->prep($value);
            }
        }
        return $this;
    }

    /**
     * Union result
     * @param  DBInterface  $inst
     * @param  bool         $allowDuplicate  UNION by default selects only distinct values.
     *                                       Use UNION ALL to also select duplicate values!
     * @mixin AbstractDB
     * @return self
     */
    public function union(DBInterface $inst, bool $allowDuplicate = false): self
    {
        return $this->unionRaw($inst->sql(), $allowDuplicate);
    }

     /**
     * Union raw result, create union with raw SQL code
     * @param  string  $sql
     * @param  bool    $allowDuplicate  UNION by default selects only distinct values.
     *                                  Use UNION ALL to also select duplicate values!
     * @mixin AbstractDB
     * @return self
     */
    public function unionRaw(string $sql, bool $allowDuplicate = false): self
    {
        $this->order = null;
        $this->limit = null;
        $this->union = " UNION " . ($allowDuplicate ? "ALL " : "") . $sql;
        return $this;
    }

    /**
     * Build on insert set sql string part
     * @param array|null $arr
     * @return string
     */
    private function buildInsertSet(?array $arr = null): string
    {
        if (is_null($arr)) {
            $arr = $this->set;
        }
        $columns = array_keys($arr);
        $columns = implode(",", $columns);
        $values = implode(",", $this->set);
        return "($columns) VALUES ($values)";
    }

    /**
     * Build on update set sql string part
     * @param array|null $arr
     * @return string
     */
    private function buildUpdateSet(?array $arr = null): string
    {
        if (is_null($arr)) {
            $arr = $this->set;
        }
        $new = array();
        foreach ($arr as $key => $val) {
            $new[] = "$key = $val";
        }
        return implode(",", $new);
    }

    /**
     * Will build a returning value that can be fetched with insert id
     * This is a PostgreSQL specific function.
     * @return string
     * @throws ConnectException
     */
    private function buildReturning(): string
    {
        if(!is_null($this->returning) && $this->connInst()->getHandler()->getType() === "postgresql") {
            return " RETURNING $this->returning";
        }
        return "";
    }

    /**
     * Build on duplicate sql string part
     * @return string
     */
    private function buildDuplicate(): string
    {
        if (!is_null($this->dupSet)) {
            $set = (count($this->dupSet) > 0) ? $this->dupSet : $this->set;
            return " ON DUPLICATE KEY UPDATE " . $this->buildUpdateSet($set);
        }
        return "";
    }

    /**
     * Will build where string
     * @param string $prefix
     * @param array|null $where
     * @return string
     */
    private function buildWhere(string $prefix, ?array $where): string
    {
        $out = "";
        if (!is_null($where)) {
            $out = " $prefix";
            $index = 0;
            foreach ($where as $array) {
                $firstAnd = key($array);
                $out .= (($index > 0) ? " $firstAnd" : "") . " (";
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
    private function buildJoin(): string
    {
        return (count($this->join) > 0) ? " " . implode(" ", $this->join) : "";
    }

    /**
     * Build limit
     * @return string
     */
    private function buildLimit(): string
    {
        if (is_null($this->limit) && !is_null($this->offset)) {
            $this->limit = 1;
        }
        $offset = (!is_null($this->offset)) ? ",$this->offset" : "";
        return (!is_null($this->limit)) ? " LIMIT $this->limit$offset" : "";
    }

    /**
     * Used to call method that builds SQL queries
     * @throws ResultException|DBValidationException|ConnectException
     */
    final protected function build(): void
    {

        if (!is_null($this->method) && method_exists($this, $this->method)) {


            $inst = (!is_null($this->dynamic)) ? call_user_func_array($this->dynamic[0], $this->dynamic[1]) : $this->{$this->method}();

            if (is_null($inst->sql)) {
                throw new ResultException("The Method 1 \"$inst->method\" expect to return a sql " .
                    "building method (like return @select() or @insert()).", 1);
            }
        } else {
            $this->select();
        }
    }

    /**
     * Generate SQL string of current instance/query
     * @return string
     * @throws ConnectException|DBValidationException|DBQueryException|ResultException
     */
    public function sql(): string
    {
        $this->build();
        return $this->sql;
    }

    /**
     * Get insert AI ID from prev inserted result
     * @param string|null $column
     * @return int|string
     * @throws ConnectException
     */
    public function insertId(?string $column = null): int|string
    {
        $column = !is_null($column) ? $column : $this->returning;
        if(!is_null($column)) {
            return $this->connInst()->DB()->insert_id($column);
        }
        return $this->connInst()->DB()->insert_id();
    }

    /**
     * DEPRECATED??
     */

    /**
     * Build CREATE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function createView(): self
    {
        //$this->select();
        $this->sql = "CREATE VIEW " . $this->viewName . " AS $this->sql";
        return $this;
    }

    /**
     * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function replaceView(): self
    {
        //$this->select();
        $this->sql = "CREATE OR REPLACE VIEW " . $this->viewName . " AS $this->sql";
        return $this;
    }

    /**
     * Build DROP VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function dropView(): self
    {
        $this->sql = "DROP VIEW " . $this->viewName;
        return $this;
    }

    /**
     * Build DROP VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function showView(): self
    {
        $this->sql = "SHOW CREATE VIEW " . $this->viewName;
        return $this;
    }
}