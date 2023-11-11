<?php

/**
 * The MigrateInterface is used for creating Migrate Model files for the database "that" can
 * also be used to communicate with the DB library
 */

namespace PHPFuse\Query\Interfaces;

interface DBInterface
{
    /**
     * Change where compare operator from default "=".
     * Will change back to default after where method is triggered
     * @param  string $operator once of (">", ">=", "<", "<>", "!=", "<=", "<=>")
     * @return self
     */
    public function compare(string $operator): self;

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function and(): self;

    /**
     * Chaining where with mysql "AND" or with "OR"
     * @return self
     */
    public function or(): self;

    /**
     * Raw Mysql Where input
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
     * @param  string    $sql     SQL string example: (id = %d AND permalink = '%s')
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function whereRaw(string $sql, ...$arr): self;

    /**
     * Create protected MySQL WHERE input
     * Supports dynamic method name calls like: whereIdStatus(1, 0)
     * @param  string      $key      Mysql column
     * @param  string      $val      Equals to value
     * @param  string|null $operator Change comparison operator from default "=".
     * @return self
     */
    public function where(string|AttrInterface $key, string|AttrInterface $val, ?string $operator = null): self;


    /**
     * Group mysql WHERE inputs
     * @param  callable $call  Evere method where placed inside callback will be grouped.
     * @return self
     */
    public function whereBind(callable $call): self;

    /**
     * Create protected MySQL HAVING input
     * @param  string      $key      Mysql column
     * @param  string      $val      Equals to value
     * @param  string|null $operator Change comparison operator from default "=".
     * @return self
     */
    public function having(string|AttrInterface $key, string|AttrInterface $val, ?string $operator = null): self;

    /**
     * Raw Mysql HAVING input
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
     * @param  string    $sql     SQL string example: (id = %d AND permalink = '%s')
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function havingRaw(string $sql, ...$arr): self;

    /**
     * Add a limit and maybee a offset
     * @param  int      $limit
     * @param  int|null $offset
     * @return self
     */
    public function limit(int $limit, ?int $offset = null): self;


    /**
     * Add a offset (if limit is not set then it will automatically become "1").
     * @param  int    $offset
     * @return self
     */
    public function offset(int $offset): self;

    /**
     * Set Mysql ORDER
     * @param  string $col  Mysql Column
     * @param  string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
     * @return self
     */
    public function order(string|AttrInterface $col, string $sort = "ASC"): self;

    /**
     * Raw Mysql ORDER input
     * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
     * @param  string    $sql     SQL string example: (id ASC, parent DESC)
     * @param  array     $arr     Mysql prep values
     * @return self
     */
    public function orderRaw(string $sql, ...$arr): self;


    /**
     * Add group
     * @param  mixed $columns
     * @return self
     */
    public function group(...$columns): self;


    /**
     * Mysql JOIN query (Default: INNER)
     * @param  string|array|MigrateInterface    $table  Mysql table name (if array e.g. [TABLE_NAME, ALIAS]) or MigrateInterface instance
     * @param  array|array                      $where  Where data (as array or string e.g. string is raw)
     * @param  array                            $sprint Use sprint to prep data
     * @param  string                           $type   Type of join
     * @return [type]                   [description]
     */
    public function join(string|array|MigrateInterface $table, string|array $where = null, array $sprint = array(), string $type = "INNER"): self;


    /**
     * Add make query a distinct call
     * @return self
     */
    public function distinct(): self;


    /**
     * Exaplain the mysql query. Will tell you how you can make improvements
     * @return self
     */
    public function explain(): self;


    /**
     * Create INSERT or UPDATE set Mysql input to insert
     * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function set(string|array|AttrInterface $key, string|array|AttrInterface $value = null): self;

    /**
     * UPROTECTED: Create INSERT or UPDATE set Mysql input to insert
     * @param string $key   Mysql column
     * @param string $value Input/insert value (UPROTECTED and Will not enclose)
     */
    public function setRaw(string $key, string $value): self;


    /**
     * Update if ID KEY is duplicate else insert
     * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
     * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
     * @return self
     */
    public function onDupKey($key = null, ?string $value = null): self;


    /**
     * Union result
     * @param  DBInterface  $inst
     * @param  bool         $allowDuplicate  UNION by default selects only distinct values.
     *                                       Use UNION ALL to also select duplicate values!
     * @return self
     */
    public function union(DBInterface $inst, bool $allowDuplicate = false): self;
}
