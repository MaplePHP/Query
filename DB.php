<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Exceptions\DBQueryException;
use MaplePHP\Query\Utility\Attr;
use MaplePHP\Query\Utility\WhitelistMigration;

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

    /**
     * It is a semi-dynamic method builder that expects certain types of objects to be setted
     * @param  string $method
     * @param  array $args
     * @return self
     */
    public static function __callStatic($method, $args)
    {
        if (count($args) > 0) {
            $defaultArgs = $args;
            $table = array_pop($args);
            $inst = self::table($table);
            $inst->method = $method;
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
                    $inst->viewName = Connect::prefix() . static::VIEW_PREFIX_NAME . "_" . $encodeArg1;
                    $inst->sql = $defaultArgs[1];
                    break;
                case 'dropView':
                case 'showView':
                    $encodeArg1 = $inst->getAttr($defaultArgs[0])->enclose(false);
                    $inst->viewName = Connect::prefix() . static::VIEW_PREFIX_NAME . "_" . $encodeArg1;
                    break;
                default:
                    $inst->dynamic = [[$inst, $inst->method], $args];
                    break;
            }
        } else {
            $inst = new self();
        }
        return $inst;
    }

    /**
     * Used to make methods into dynamic shortcuts
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $camelCaseArr = $this->extractCamelCase($method);
        $shift = array_shift($camelCaseArr);
        switch ($shift) {
            case "pluck": // Columns??
                if (is_array($args[0] ?? null)) {
                    $args = $args[0];
                }
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
     * You can build queries like Larvel If you want. I do not think they have good semantics tho.
     * It is better to use (DB::select, DB::insert, DB::update, DB::delete)
     * @param  string|array $table Mysql table name (if array e.g. [TABLE_NAME, ALIAS])
     * @return self new intance
     */
    public static function table(string|array|MigrateInterface $data): self
    {
        $mig = null;
        if ($data instanceof MigrateInterface) {
            $mig = new WhitelistMigration($data);
            $data = $mig->getTable();
        }

        $inst = new self();
        $data = $inst->sperateAlias($data);
        $inst->alias = $data['alias'];
        $inst->table = $inst->getAttr($data['table'])->enclose(false);
        $inst->mig = $mig;
        if (is_null($inst->alias)) {
            $inst->alias = $inst->table;
        }
        return $inst;
    }

    /**
     * Access Query Attr class
     * @param  array|string|int|float  $value
     * @return AttrInterface
     */
    public static function withAttr(array|string|int|float $value, ?array $args = null): AttrInterface
    {
        $inst = new self();
        $inst = $inst->getAttr($value);
        if (!is_null($args)) {
            foreach ($args as $method => $args) {
                if (!method_exists($inst, $method)) {
                    throw new DBValidationException("The Query Attr method \"" .htmlspecialchars($method, ENT_QUOTES). "\" does not exists!", 1);
                }
                $inst = call_user_func_array([$inst, $method], (!is_array($args) ? [$args] : $args));
            }
        }
        return $inst;
    }

    /**
     * Build SELECT sql code (The method will be auto called in method build)
     * @return self
     */
    protected function select(): self
    {
        $columns = is_null($this->columns) ? "*" : implode(",", $this->getColumns());
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $having = $this->buildWhere("HAVING", $this->having);
        $order = (!is_null($this->order)) ? " ORDER BY " . implode(",", $this->order) : "";
        $limit = $this->buildLimit();

        $this->sql = "{$this->explain}SELECT {$this->noCache}{$this->calRows}{$this->distinct}{$columns} FROM " .
        $this->getTable(true) . "{$join}{$where}{$this->group}{$having}{$order}{$limit}{$this->union}";

        return $this;
    }

    /**
     * Select view
     * @return self
     */
    protected function selectView(): self
    {
        return $this->select();
    }

    /**
     * Build INSERT sql code (The method will be auto called in method build)
     * @return self
     */
    protected function insert(): self
    {
        $this->sql = "{$this->explain}INSERT INTO " . $this->getTable() . " " .
        $this->buildInsertSet() . $this->buildDuplicate();
        return $this;
    }

    /**
     * Build UPDATE sql code (The method will be auto called in method build)
     * @return self
     */
    protected function update(): self
    {
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $limit = $this->buildLimit();

        $this->sql = "{$this->explain}UPDATE " . $this->getTable() . "{$join} SET " .
        $this->buildUpdateSet() . "{$where}{$limit}";
        return $this;
    }

    /**
     * Build DELETE sql code (The method will be auto called in method build)
     * @return self
     */
    protected function delete(): self
    {
        $linkedTables = $this->getAllQueryTables();
        if (!is_null($linkedTables)) {
            $linkedTables = " {$linkedTables}";
        }
        $join = $this->buildJoin();
        $where = $this->buildWhere("WHERE", $this->where);
        $limit = $this->buildLimit();

        $this->sql = "{$this->explain}DELETE{$linkedTables} FROM " . $this->getTable() . "{$join}{$where}{$limit}";
        return $this;
    }

    /**
     * Build CREATE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function createView(): self
    {
        //$this->select();
        $this->sql = "CREATE VIEW " . $this->viewName . " AS {$this->sql}";
        return $this;
    }

    /**
     * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
     * @return self
     */
    protected function replaceView(): self
    {
        //$this->select();
        $this->sql = "CREATE OR REPLACE VIEW " . $this->viewName . " AS {$this->sql}";
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
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
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
     * @param  string      $key      Mysql column
     * @param  string|int|float|AttrInterface      $val      Equals to value
     * @param  string|null $operator Change comparison operator from default "=".
     * @return self
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
     * @param  callable $call  Evere method where placed inside callback will be grouped.
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
     * @param  string      $key      Mysql column
     * @param  string|int|float|AttrInterface      $val      Equals to value
     * @param  string|null $operator Change comparison operator from default "=".
     * @return self
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
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
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
     * Add a limit and maybee a offset
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
     * Add a offset (if limit is not set then it will automatically become "1").
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
     * @param  string $col  Mysql Column
     * @param  string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
     * @return self
     */
    public function order(string|AttrInterface $col, string $sort = "ASC"): self
    {
        $col = $this->prep($col, false);

        if (!is_null($this->mig) && !$this->mig->columns([(string)$col])) {
            throw new DBValidationException($this->mig->getMessage(), 1);
        }
        $sort = $this->orderSort($sort); // Whitelist
        $this->order[] = "{$col} {$sort}";
        return $this;
    }

    /**
     * Raw Mysql ORDER input
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
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
     * @param  array $columns
     * @return self
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
     * Mysql JOIN query (Default: INNER)
     * @param  string|array|MigrateInterface    $table  Mysql table name (if array e.g. [TABLE_NAME, ALIAS]) or MigrateInterface instance
     * @param  array|string                     $where  Where data (as array or string e.g. string is raw)
     * @param  array                            $sprint Use sprint to prep data
     * @param  string                           $type   Type of join
     * @return self
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
                throw new DBQueryException("You need to specify the argumnet 2 (where) value!", 1);
            }

            $prefix = Connect::prefix();
            $arr = $this->sperateAlias($table);
            $table = (string)$this->prep($arr['table'], false);
            $alias = (!is_null($arr['alias'])) ? " {$arr['alias']}" : " {$table}";

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
            $this->join[] = "{$type} JOIN {$prefix}{$table}{$alias} ON " . $out;
            $this->joinedTables[$table] = "{$prefix}{$table}";
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
     * Exaplain the mysql query. Will tell you how you can make improvements
     * @return self
     */
    public function explain(): self
    {
        $this->explain = "EXPLAIN ";
        return $this;
    }

    /**
     * Disable mysql query cache
     * @return self
     */
    public function noCache(): self
    {
        $this->noCache = "SQL_NO_CACHE ";
        return $this;
    }

    /**
     * DEPRECATE: Calculate rows in query
     * @return self
     */
    public function calcRows(): self
    {
        $this->calRows = "SQL_CALC_FOUND_ROWS ";
        return $this;
    }

    /**
     * Create INSERT or UPDATE set Mysql input to insert
     * @param  string|array|AttrInterface $key   (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param  string|array|AttrInterface $value If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function set(string|array|AttrInterface $key, string|array|AttrInterface $value = null): self
    {
        if (is_array($key)) {
            $this->set = array_merge($this->set, $this->prepArr($key, true));
        } else {
            $this->set[(string)$key] = $this->prep($value);
        }
        return $this;
    }

    /**
     * UPROTECTED: Create INSERT or UPDATE set Mysql input to insert
     * @param string $key   Mysql column
     * @param string $value Input/insert value (UPROTECTED and Will not enclose)
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
                $this->dupSet = $this->prepArr($key, true);
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
        return "({$columns}) VALUES ({$values})";
    }

    /**
     * Build on update set sql string part
     * @return string
     */
    private function buildUpdateSet(?array $arr = null): string
    {
        if (is_null($arr)) {
            $arr = $this->set;
        }
        $new = array();
        foreach ($arr as $key => $val) {
            $new[] = "{$key} = {$val}";
        }
        return implode(",", $new);
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
     * @param  string $prefix
     * @param  array  $where
     * @return string
     */
    private function buildWhere(string $prefix, ?array $where): string
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
    private function buildJoin(): string
    {
        return (count($this->join) > 0) ? " " . implode(" ", $this->join) : "";
    }

    /**
     * Byuld limit
     * @return string
     */
    private function buildLimit(): string
    {
        if (is_null($this->limit) && !is_null($this->offset)) {
            $this->limit = 1;
        }
        $offset = (!is_null($this->offset)) ? ",{$this->offset}" : "";
        return (!is_null($this->limit)) ? " LIMIT {$this->limit}{$offset}" : "";
    }

    /**
     * Used to call methoed that builds SQL queryies
     */
    final protected function build(): void
    {
        if (!is_null($this->method) && method_exists($this, $this->method)) {
            $inst = (!is_null($this->dynamic)) ? call_user_func_array($this->dynamic[0], $this->dynamic[1]) : $this->{$this->method}();

            if (is_null($inst->sql)) {
                throw new DBQueryException("The Method \"{$inst->method}\" expect to return a sql " .
                    "building method (like return @select() or @insert()).", 1);
            }
        } else {
            if (is_null($this->sql)) {
                $method = is_null($this->method) ? "NULL" : $this->method;
                throw new DBQueryException("Method \"{$method}\" does not exists! You need to create a method that with " .
                    "same name as static, that will build the query you are after. " .
                    "Take a look att method @method->select.", 1);
            }
        }
    }

    /**
     * Genrate SQL string of current instance/query
     * @return string
     */
    public function sql(): string
    {
        $this->build();
        return $this->sql;
    }

    /**
     * Start Transaction
     * @return mysqli
     */
    public static function beginTransaction()
    {
        Connect::DB()->begin_transaction();
        return Connect::DB();
    }


    // Same as @beginTransaction
    public static function transaction()
    {
        return self::beginTransaction();
    }

    /**
     * Commit transaction
     * @return void
     */
    public static function commit(): void
    {
        Connect::DB()->commit();
    }

    /**
     * Rollback transaction
     * @return void
     */
    public static function rollback(): void
    {
        Connect::DB()->rollback();
    }

    /**
     * Get return a new generated UUID
     * DEPRECATED: Will be moved to Connect for starter
     * @return null|string
     */
    public static function getUUID(): ?string
    {
        $result = Connect::query("SELECT UUID()");
        if (is_object($result)) {
            if ($result->num_rows > 0) {
                $row = $result->fetch_row();
                return ($row[0] ?? null);
            }
            return null;
        } else {
            throw new DBQueryException(Connect::DB()->error, 1);
        }
    }

    /**
     * Get insert AI ID from prev inserted result
     * @return int|string
     */
    public function insertID(): int|string
    {
        return Connect::DB()->insert_id;
    }
}