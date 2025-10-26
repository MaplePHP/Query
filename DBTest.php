<?php

declare(strict_types=1);

namespace MaplePHP\Query;

use RuntimeException;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\DBValidationException;
use MaplePHP\Query\Interfaces\DBInterface;
use MaplePHP\Query\Interfaces\HandlerInterface;
use MaplePHP\Query\Interfaces\QueryBuilderInterface;
use MaplePHP\Query\Utility\Attr;
use MaplePHP\Query\Utility\Helpers;
use MaplePHP\Query\Exceptions\ResultException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;
use MaplePHP\Query\Utility\WhitelistMigration;

/**
 * @method pluck(string $string)
 */
class DBTest implements DBInterface
{
    private HandlerInterface $handler;
    private ConnectInterface $connection;
    private ?WhitelistMigration $migration = null;
    private AttrInterface $attr;
    private ?QueryBuilderInterface $builder = null;

    protected string $prefix;
    protected string $compare = "=";
    protected bool $whereNot = false;
    protected string $whereAnd = "AND";
    protected int $whereIndex = 0;
    protected ?string $pluck = null;
    protected array $selectSet = [];
    private object|array|bool|null $result = null;

    public null|string|AttrInterface $table = null;
    public ?AttrInterface $alias = null;
    public ?array $columns = null;
    public bool $distinct = false;
    public ?array $order = null;
    public ?array $where = null;
    public ?array $having = null;
    public ?AttrInterface $limit = null;
    public ?AttrInterface $offset = null;
    public ?array $group = null;
    public array $join = [];
    public bool $prepare = false;
    public bool $explain = false;
    public bool $noCache = false;
    public ?array $union = null;
    public string $queryType = "select";
    public array $set = [];
    public ?array $onDupKey = null;
    public ?AttrInterface $returning = null;

    /**
     * @throws ConnectException
     */
    public function __construct(HandlerInterface $handler)
    {
        $this->handler = $handler;
        $this->connection = $handler->execute();
        $this->prefix = $handler->getPrefix();
        $this->attr = new Attr($this->connection);
    }

    /**
     * Get handler
     * @return HandlerInterface
     */
    public function getHandler(): HandlerInterface
    {
        return $this->handler;
    }

    /**
     * Get handlers connection
     * @return ConnectInterface
     */
    public function getConnection(): ConnectInterface
    {
        return $this->connection;
    }

    /**
     * Get SQL code
     * @return string
     * @throws ConnectException
     */
    public function __toString(): string
    {
        return $this->sql();
    }

    /**
     * Used to make methods into dynamic shortcuts
     * @param string $method
     * @param array $args
     * @return array|bool|object|string
     * @throws ConnectException
     * @throws DBValidationException
     * @throws ResultException
     */
    public function __call(string $method, array $args): array|bool|object|string
    {
        $camelCaseArr = Helpers::extractCamelCase($method);
        $shift = array_shift($camelCaseArr);

        $inst = clone $this;
        switch ($shift) {
            case "pluck": // Columns??
                $args = ($args[0] ?? "");
                if (str_contains($args, ",")) {
                    throw new ResultException("Your only allowed to pluck one database column!");
                }

                $pluck = explode(".", $args);
                $inst->pluck = trim(end($pluck));
                $inst = $inst->columns($args);
                break;
            case "where":
            case "having":
                Helpers::camelLoop($camelCaseArr, $args, function ($col, $val) use ($shift, &$inst) {
                    $inst = $inst->{$shift}($col, $val);
                });
                break;
            case "order":
                if ($camelCaseArr[0] === "By") {
                    array_shift($camelCaseArr);
                }
                $ace = end($camelCaseArr);
                foreach ($args as $val) {
                    $inst = $inst->order($val, $ace);
                }
                break;
            case "join":
                $inst = $inst->join($args[0], ($args[1] ?? null), ($args[2] ?? []), $camelCaseArr[0]);
                break;
            default:
                return $inst->query($inst, $method, $args);
        }
        return $inst;
    }

    // Magic method to dynamically access protected properties
    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->{$property};
        }
        throw new InvalidArgumentException("Property '$property' does not exist");
    }

    /**
     * @param string|array|MigrateInterface $table
     * @return DBTest
     */
    public function table(string|array|MigrateInterface $table): self
    {
        $inst = clone $this;

        /*
         if ($table instanceof MigrateInterface) {
            $inst->migration = new WhitelistMigration($table);
            $table = $inst->migration->getTable();
        }
         */
        $tableRow = Helpers::separateAlias($table);
        $table = $inst->prefix . $tableRow['table'];


        $inst->table = $inst->attr($table, Attr::COLUMN_TYPE);
        $inst->alias = $this->attr($tableRow['alias'] ?? $tableRow['table'], Attr::COLUMN_TYPE);


        return $inst;
    }

    public function select(array|null $columns, null|string|array|MigrateInterface $table = null): self
    {
        $inst = clone $this;
        $inst->queryType = "select";
        if ($table !== null) {
            $inst = $inst->table($table);
        }
        if ($inst->table === null) {
            throw new RuntimeException('You need to specify a database table either in the second argument in this method or with the table method.');
        }
        if (is_array($columns)) {
            $inst = $inst->columns(...$columns);
        }
        return $inst;
    }

    public function insert(null|string|array|MigrateInterface $table = null): self
    {
        $inst = clone $this;
        $inst->queryType = "insert";
        if ($table !== null) {
            $inst = $inst->table($table);
        }
        if ($inst->table === null) {
            throw new RuntimeException('You need to specify a database table either in the first argument in this method or with the table method.');
        }
        return $inst;
    }

    public function update(null|string|array|MigrateInterface $table = null): self
    {
        $inst = clone $this;
        $inst->queryType = "update";
        if ($table !== null) {
            $inst = $inst->table($table);
        }
        if ($inst->table === null) {
            throw new RuntimeException('You need to specify a database table either in the first argument in this method or with the table method.');
        }
        return $inst;
    }

    public function delete(null|string|array|MigrateInterface $table = null): self
    {
        $inst = clone $this;
        $inst->queryType = "delete";
        if ($table !== null) {
            $inst = $inst->table($table);
        }
        if ($inst->table === null) {
            throw new RuntimeException('You need to specify a database table either in the first argument in this method or with the table method.');
        }
        return $inst;
    }

    /**
     * Easy way to create an attr/data type for the query string
     *
     * @param mixed $value
     * @param int $type
     * @return array|AttrInterface
     */
    public function attr(mixed $value, int $type): array|AttrInterface
    {
        if (is_callable($value)) {
            $value = $value($this->attr->withValue($value)->type($type));
        }
        if (is_array($value)) {
            return array_map(function ($val) use ($type) {
                if ($val instanceof AttrInterface) {
                    return $val;
                }
                return $this->attr->withValue($val)->type($type);
            }, $value);
        }
        if ($value instanceof AttrInterface) {
            return $value;
        }
        return $this->attr->withValue($value)->type($type);
    }

    /**
     * When SQL query has been triggered then the QueryBuilder should exist
     * @return QueryBuilderInterface
     * @throws ConnectException
     */
    public function getQueryBuilder(): QueryBuilderInterface
    {
        if ($this->builder === null) {
            $this->sql();
            //throw new BadMethodCallException("The query builder can only be called after query has been built.");
        }
        return $this->builder;
    }

    /**
     * Select protected mysql columns
     *
     * @param string|array|AttrInterface ...$columns
     * @return self
     */
    public function columns(string|array|AttrInterface ...$columns): self
    {
        $inst = clone $this;
        foreach ($columns as $key => $column) {
            $inst->columns[$key]['alias'] = null;
            if (is_array($column)) {
                $alias = reset($column);
                $column = key($column);
                $inst->columns[$key]['alias'] = $this->attr($alias, Attr::COLUMN_TYPE);
            }
            $inst->columns[$key]['column'] = $this->attr($column, Attr::COLUMN_TYPE);
        }
        return $inst;
    }

    // JUST A IF STATEMENT
    // whenNot???
    public function when(bool $bool, callable $func): self
    {
        $inst = clone $this;
        return $inst;
    }

    // FIX - SQL STATEMENT EXISTS
    // existNot???
    public function exist(callable $func): self
    {
        $inst = clone $this;
        return $inst;
    }

    /**
     * Change where compare operator from default "=".
     * Will change back to default after where method is triggered
     * @param  string $operator once of (">", ">=", "<", "<>", "!=", "<=", "<=>")
     * @return self
     */
    public function compare(string $operator): self
    {
        $inst = clone $this;
        $inst->compare = Helpers::operator($operator);
        return $inst;
    }

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function and(): self
    {
        $inst = clone $this;
        $inst->whereAnd = "AND";
        return $inst;
    }

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function or(): self
    {
        $inst = clone $this;
        $inst->whereAnd = "OR";
        return $inst;
    }

    /**
     * Chaining with where "NOT" ???
     * @return self
     */
    public function not(): self
    {
        $inst = clone $this;
        $inst->whereNot = true;
        return $inst;
    }


    /**
     * Create protected MySQL WHERE input
     * Supports dynamic method name calls like: whereIdStatus(1, 0)
     * @param string|AttrInterface $column Mysql column
     * @param string|int|float|AttrInterface $value Equals to value
     * @param string|null $operator Change comparison operator from default "=".
     * @return self
     */
    public function where(string|AttrInterface $column, string|int|float|AttrInterface $value, ?string $operator = null): self
    {
        $inst = clone $this;
        if ($operator !== null) {
            $inst->compare = Helpers::operator($operator);
        }
        $inst->setWhereData($value, $column, $inst->where);
        $inst->selectSet[] = (string)$value;
        return $inst;
    }

    /**
     * Create protected MySQL HAVING input
     * @param string|AttrInterface $column Mysql column
     * @param string|int|float|AttrInterface $value Equals to value
     * @param string|null $operator Change comparison operator from default "=".
     * @return self
     */
    public function having(string|AttrInterface $column, string|int|float|AttrInterface $value, ?string $operator = null): self
    {
        $inst = clone $this;
        if ($operator !== null) {
            $inst->compare = Helpers::operator($operator);
        }
        $this->setWhereData($value, $column, $inst->having);
        return $inst;
    }

    /**
     * Set Mysql ORDER
     * @param string|AttrInterface $column Mysql Column
     * @param string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
     * @return self
     */
    public function order(string|AttrInterface $column, string $sort = "ASC"): self
    {
        // PREP AT BUILD
        //$col = $this->prep($col, false);
        /*
        if ($this->migration !== null && !$this->migration->columns([(string)$col])) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */
        $inst = clone $this;
        $inst->order[] = [
            "column" => $this->attr($column, Attr::COLUMN_TYPE),
            "sort" => Helpers::orderSort($sort)
        ];
        return $inst;
    }

    /**
     * Add a limit and maybe an offset
     * @param int|AttrInterface $limit
     * @param int|AttrInterface|null $offset
     * @return $this
     */
    public function limit(int|AttrInterface $limit, null|int|AttrInterface $offset = null): self
    {
        $inst = clone $this;
        $inst->limit = $this->attr($limit, Attr::VALUE_TYPE_NUM);
        if ($offset !== null) {
            $inst->offset($offset);
        }
        return $inst;
    }

    /**
     * Add an offset (if limit is not set then it will automatically become "1").
     * @param int|AttrInterface $offset
     * @return $this
     */
    public function offset(int|AttrInterface $offset): self
    {
        $inst = clone $this;
        $inst->offset = $this->attr($offset, Attr::VALUE_TYPE_NUM);
        return $inst;
    }

    /**
     * Add group
     * @param array $columns
     * @return self
     */
    public function group(...$columns): self
    {
        /*
         if ($this->migration !== null && !$this->migration->columns($columns)) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */
        $inst = clone $this;
        $inst->group = $columns;
        return $inst;
    }

    /**
     * Add make query a distinct call
     * @return self
     */
    public function distinct(): self
    {
        $inst = clone $this;
        $inst->distinct = true;
        return $inst;
    }

    /**
     * Postgre specific function
     * @param string $column
     * @return $this
     */
    public function returning(string $column): self
    {
        $inst = clone $this;
        $inst->returning = $this->attr($column, Attr::COLUMN_TYPE);
        return $inst;
    }

    /**
     * Update On Duplicate Key instead of insert
     * @param array|null $set
     * @return $this
     */
    public function onDuplicateKey(?array $set = null): self
    {
        $inst = clone $this;
        $inst->onDupKey = [];
        if (is_array($set)) {
            foreach ($set as $column => $val) {
                $inst->onDupKey[] = [
                    'column' => $this->attr($column, Attr::COLUMN_TYPE),
                    'value' => $this->attr($val, Attr::VALUE_TYPE_STR)
                ];
            }
        }
        return $inst;
    }


    /**
     * Create INSERT or UPDATE set Mysql input to insert
     * @param string|array|AttrInterface $key (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param string|array|AttrInterface|null $value If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function set(string|array|AttrInterface $key, null|string|array|AttrInterface $value = null): self
    {
        if (is_array($key)) {
            foreach ($key as $column => $val) {
                $this->set[] = [
                    'column' => $this->attr($column, Attr::COLUMN_TYPE),
                    'value' => $this->attr($val, Attr::VALUE_TYPE_STR)
                ];
            }

        } else {
            $this->set[] = [
                'column' => $this->attr($key, Attr::COLUMN_TYPE),
                'value' => $this->attr($value, Attr::VALUE_TYPE_STR)
            ];
        }
        return $this;
    }

    /**
     * Mysql JOIN query (Default: INNER)
     * @param string|array|MigrateInterface $table Mysql table name (if array e.g. [TABLE_NAME, ALIAS]) or MigrateInterface instance
     * @param string|array|null $where Where data (as array or string e.g. string is raw)
     * @param array $sprint Use sprint to prep data
     * @param string $type Type of join
     * @return self
     */
    public function join(
        string|array|MigrateInterface $table,
        null|string|array $where = null,
        array $sprint = [],
        string $type = "INNER"
    ): self {

        $inst = clone $this;
        if ($table instanceof MigrateInterface) {
            die("FIX");

        } else {

            /*
             * if ($where === null) {
                throw new ResultException("You need to specify the argument 2 (where) value!", 1);
            }
             */

            // Try to move this to the start of the method
            $tableInst = clone $inst;
            $tableInst->alias = null;
            $tableInst = $tableInst->table($table);

            $data = [];
            if (is_array($where)) {
                foreach ($where as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $grpKey => $grpVal) {
                            $inst->setWhereData($this->attr($grpVal, Attr::COLUMN_TYPE), $grpKey, $data);
                        }
                    } else {
                        $inst->setWhereData($this->attr($val, Attr::COLUMN_TYPE), $key, $data);
                    }
                }
            }
            $type = Helpers::joinTypes(strtoupper($type)); // Whitelist

            $inst->join[] = [
                "type" => $type,
                "table" => $tableInst->table,
                "alias" => $tableInst->alias,
                "where" => $where,
                "whereData" => $data,
                "sprint" => $sprint
            ];
        }
        return $inst;
    }

    /**
     * Union result
     * @param DBInterface|string $dbInst
     * @param bool $allowDuplicate UNION by default selects only distinct values.
     *                                       Use UNION ALL to also select duplicate values!
     * @return self
     * @mixin AbstractDB
     */
    public function union(DBInterface|string $dbInst, bool $allowDuplicate = false): self
    {
        $inst = clone $this;


        if ($inst->order !== null) {
            throw new RuntimeException("You need to move your ORDER BY to the last UNION statement!");
        }

        if ($inst->limit !== null) {
            throw new RuntimeException("You need to move your ORDER BY to the last UNION statement!");
        }

        $inst->union[] = [
            'inst' => $dbInst,
            'allowDuplicate' => $allowDuplicate
        ];
        return $inst;
    }

    /**
     * Tell builder that it should build a prepare statement
     * @return $this
     */
    public function prepare(): self
    {
        $inst = clone $this;
        $this->prepare = true;
        return $this;
    }

    /**
     * Get SQL code
     * @return string
     * @throws ConnectException
     */
    public function sql(): string
    {
        $this->builder = new QueryBuilder($this);
        return $this->builder->sql();
    }

    /**
     * Propagate where data structure
     * @param string|AttrInterface $key
     * @param string|int|float|AttrInterface $val
     * @param array|null &$data static value
     */
    final protected function setWhereData(string|int|float|AttrInterface $val, string|AttrInterface $key, ?array &$data): void
    {
        if ($data === null) {
            $data = [];
        }
        /*
        $key = (string)$this->prep($key, false);
        $val = $this->prep($val);
        if ($this->migration !== null && !$this->migration->where($key, $val)) {
            throw new DBValidationException($this->migration->getMessage(), 1);
        }
         */

        $data[$this->whereIndex][$this->whereAnd][$key][] = [
            "column" => $this->attr($key, Attr::COLUMN_TYPE),
            "not" => $this->whereNot,
            "operator" => $this->compare,
            "value" => $this->attr($val, Attr::VALUE_TYPE)
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
        $inst = clone $this;
        if ($inst->where !== null) {
            $inst->whereIndex++;
        }
        $inst->resetWhere();
        $call($inst);
        $inst->whereIndex++;
        return $inst;
    }


    /**
     * Will reset Where input
     * No need to clone as this will return void
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
    final public function query(string|self $sql, ?string $method = null, array $args = []): array|object|bool|string
    {
        $query = new Query($this->connection, $sql);
        $query->setPluck($this->pluck);
        if ($method !== null) {
            if (method_exists($query, $method)) {
                return call_user_func_array([$query, $method], $args);
            }
            throw new ResultException("Method \"$method\" does not exists!", 1);
        }
        return $query;
    }

    /**
     * Execute
     * @return mixed
     * @throws ConnectException
     * @throws ResultException
     */
    public function execute(): mixed
    {
        if ($this->result === null) {
            $this->result = $this->query($this->sql())->execute();
        }
        return $this->result;
    }

    /**
     * Get insert iD
     * @return int
     */
    public function insertID(): int
    {
        return $this->getConnection()->insert_id();
    }

    /**
    MIGRATION BUILDERS
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
        $joinArr = [];
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
        }
        return $joinArr;
    }

    /**
     * Get the Main FK data protocol
     * @return array
     */
    final protected function getMainFKData(): array
    {
        if ($this->fkData === null) {
            $this->fkData = [];
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
}
