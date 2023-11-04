<?php
/**
 * Wazabii DB - For main queries
 */
namespace PHPFuse\Query;

use PHPFuse\Query\Handlers\MySqliHandler;
use PHPFuse\Query\Interfaces\AttrInterface;
use PHPFuse\Query\Interfaces\MigrateInterface;
use PHPFuse\Query\Exceptions\DBValidationException;
use PHPFuse\Query\Exceptions\DBQueryException;

class DB {

	// Whitelists
	const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
	const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types
	const VIEW_PREFIX_NAME = "view"; // View prefix

	private $table;
	private $alias;
	private $method;
	private $explain;
	
	private $columns;
	private $where;
	private $having;
	private $set = array();
	private $dupSet;
	private $whereAnd = "AND";
	private $compare = "=";
	private $whereIndex = 0;
	private $whereProtocol = array();
	private $limit;
	private $offset;
	private $order;
	private $join;
	private $distinct;
	private $group;
	private $noCache;
	private $calRows;
	private $union;
	private $viewName;
	private $sql;
	private $dynamic;
	private $mig;
	private $fkData;

	static private $mysqlVars;
	
	/**
	 * It is a semi-dynamic method builder that expects certain types of objects to be setted
	 * @param  string $method
	 * @param  array $args
	 * @return static
	 */
	static function __callStatic($method, $args) 
	{
		if(count($args) > 0) {
			
			$defaultArgs = $args;
			$length = count($defaultArgs);
			$table = array_pop($args);
			
			$mig = NULL;
			if($table instanceof MigrateInterface) {
				$mig = new WhitelistMigration($table);
				$table = $mig->getTable();
			}
			
			$inst = static::table($table);
			if(is_null($inst->alias)) $inst->alias = $inst->table;
			$inst->mig = $mig;
			$inst->method = $method;
			
			switch($inst->method) {
				case 'select': case 'selectView':
					if($inst->method === "selectView") $inst->table = static::VIEW_PREFIX_NAME."_".$inst->table;
					$col = explode(",", $args[0]);
					call_user_func_array([$inst, "columns"], $col);

				break;
				case 'createView': case 'replaceView':
					$inst->viewName = Connect::prefix().static::VIEW_PREFIX_NAME."_".attr::value($defaultArgs[0])->enclose(false);
					$inst->sql = $defaultArgs[1];
				break;
				case 'dropView': case 'showView':
					$inst->viewName = Connect::prefix().static::VIEW_PREFIX_NAME."_".attr::value($defaultArgs[0])->enclose(false);
				break;				
				default:
					$inst->dynamic = [[$inst, $inst->method], $args];
				break;
			}

		} else {
			$inst = new static();
		}
		
		return $inst;
	}

	/**
	 * Used to make methods into dynamic shortcuts
	 * @param  string $method
	 * @param  array $args
	 * @return self
	 */
	function __call($method, $args) 
	{

		$camelCaseArr = $this->extractCamelCase($method);
		$shift = array_shift($camelCaseArr);

		switch($shift) {
			case "columns": case "column": case "col": case "pluck":
				if(is_array($args[0] ?? NULL)) $args = $args[0];
				$this->columns($args);
			break;
			case "where":
				$this->camelLoop($camelCaseArr, $args, function($col, $val) {
					$this->where($col, $val);
				});
			break;
			case "having":
				$this->camelLoop($camelCaseArr, $args, function($col, $val) {
					$this->having($col, $val);
				});
			break;
			case "order":
				if($camelCaseArr[0] === "By") array_shift($camelCaseArr);
				$ace = end($camelCaseArr);
				foreach($args as $val) $this->order($val, $ace);
			break;
			case "join":
				$this->join($args[0], ($args[1] ?? NULL), ($args[2] ?? []), $camelCaseArr[0]);
			break;
			default;
				throw new DBQueryException("Method \"{$method}\" does not exists!", 1);		
		}
		return $this;
	}

	/**
	 * You can build queries like Larvel If you want. I do not think they have good semantics tho.
	 * @param  string|array $table Mysql table name (if array e.g. [TABLE_NAME, ALIAS])
	 * @return self new intance
	 */
	static function table(string|array $data): self 
	{
		$inst = new static();
		$data = $inst->sperateAlias($data);
		$inst->alias = $data['alias'];
		$inst->table = Attr::value($data['table'])->enclose(false);
		return $inst;
	}

	/**
	 * Create Mysql variable
	 * @param string $key   Variable key
	 * @param string $value Variable value
	 */
	static function setVariable(string $key, string $value): AttrInterface
	{
		$nk = self::withAttr("@{$key}", ["enclose" => false, "encode" => false]);
		$value = (($value instanceof AttrInterface) ? $value : self::withAttr($value));

		self::$mysqlVars[$key] = clone $value;
		Connect::query("SET {$nk} = {$value}");
		return $nk;
	}

	/**
	 * Get Mysql variable
	 * @param string $key   Variable key
	 */
	static function getVariable(string $key): AttrInterface
	{
		if(!self::hasVariable($key)) throw new DBQueryException("DB MySQL variable is not set.", 1);
		return self::withAttr("@{$key}", ["enclose" => false, "encode" => false]);
	}

	/**
	 * Get Mysql variable
	 * @param string $key   Variable key
	 */
	static function getVariableValue(string $key): string
	{
		if(!self::hasVariable($key)) throw new DBQueryException("DB MySQL variable is not set.", 1);
		return self::$mysqlVars[$key]->enclose(false)->encode(false);
	}

	/**
	 * Has Mysql variable
	 * @param string $key   Variable key
	 */
	static function hasVariable(string $key): bool
	{
		return (isset(self::$mysqlVars[$key]));
	}

	/**
	 * Access Query Attr class
	 * @param  array  $value
	 * @return AttrInterface
	 */
	static function withAttr(string|array $value, ?array $args = NULL): AttrInterface 
	{
		$inst = Attr::value($value);
		if(!is_null($args)) foreach($args as $method => $args) {
			if(!method_exists($inst, $method)) {
				throw new DBValidationException("The Query Attr method \"".Attr::value($method)->enclose(false)."\" does not exists!", 1);
			}
			$inst = call_user_func_array([$inst, $method], (!is_array($args) ? [$args] : $args));
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
		$order = (!is_null($this->order)) ? " ORDER BY ".implode(",", $this->order) : "";
		$limit = $this->buildLimit();
		
		$this->sql = "{$this->explain}SELECT {$this->noCache}{$this->calRows}{$this->distinct}{$columns} FROM ".$this->getTable(true)."{$join}{$where}{$this->group}{$having}{$order}{$limit}{$this->union}";

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
		$this->sql = "{$this->explain}INSERT INTO ".$this->getTable()." ".$this->buildInsertSet().$this->buildDuplicate();
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

		$this->sql = "{$this->explain}UPDATE ".$this->getTable()."{$join} SET ".$this->buildUpdateSet()."{$where}{$limit}";
		return $this;
	}

	/**
	 * Build DELETE sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function delete() : self 
	{
		$tbToCol = $this->buildTableToCol();
		$join = $this->buildJoin();
		$where = $this->buildWhere("WHERE", $this->where);
		$limit = $this->buildLimit();

		$this->sql = "{$this->explain}DELETE{$tbToCol} FROM ".$this->getTable()."{$join}{$where}{$limit}";
		return $this;
	}

	/**
	 * Build CREATE VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function createView(): self 
	{
		//$this->select();
		$this->sql = "CREATE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function replaceView(): self 
	{
		//$this->select();
		$this->sql = "CREATE OR REPLACE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build DROP VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function dropView(): self 
	{
		$this->sql = "DROP VIEW ".$this->viewName;
		return $this;
	}

	/**
	 * Build DROP VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function showView(): self 
	{
		$this->sql = "SHOW CREATE VIEW ".$this->viewName;
		return $this;
	}

	/**
	 * Get return a new generated UUID 
	 * @return null|string
	 */
	public static function getUUID(): ?string
	{
		if($result = Connect::query("SELECT UUID()")) {
			if($result && $result->num_rows > 0) {
				$row = $result->fetch_row();
				return ($row[0] ?? NULL);
			}
			return NULL;

		} else {
			throw new DBQueryException(Connect::DB()->error, 1);
		}
	}
	
	public function columns(...$columns) {		
		$this->columns = $this->prepArr($columns, false);
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
	 * Raw Mysql Where input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	public function whereRaw(string $sql, ...$arr): self 
	{
		if(is_array($arr[0] ?? NULL)) $arr = $arr[0];
		$this->resetWhere();
		$this->where[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
		return $this;
	}

	/**
	 * Create protected MySQL WHERE input
	 * Supports dynamic method name calls like: whereIdStatus(1, 0)
	 * @param  string      $key      Mysql column
	 * @param  string      $val      Equals to value
	 * @param  string|null $operator Change comparison operator from default "=".
	 * @return self
	 */
	public function where(string|AttrInterface $key, string|AttrInterface $val, ?string $operator = NULL): self 
	{
		// Whitelist operator
		if(!is_null($operator)) $this->compare = $this->operator($operator);
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
		if(!is_null($this->where)) $this->whereIndex++;
		$this->resetWhere();
		$call($this);
		$this->whereIndex++;
		return $this;
	}

	/**
	 * Create protected MySQL HAVING input
	 * @param  string      $key      Mysql column
	 * @param  string      $val      Equals to value
	 * @param  string|null $operator Change comparison operator from default "=".
	 * @return self
	 */
	public function having(string|AttrInterface $key, string|AttrInterface $val, ?string $operator = NULL): self 
	{
		if(!is_null($operator)) $this->compare = $this->operator($operator);
		$this->setWhereData($key, $val, $this->having);
		return $this;
	}

	/**
	 * Raw Mysql HAVING input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	public function havingRaw(string $sql, ...$arr): self 
	{
		if(is_array($arr[0] ?? NULL)) $arr = $arr[0];
		$this->resetWhere();
		$this->having[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
	}

	/**
	 * Add a limit and maybee a offset
	 * @param  int      $limit
	 * @param  int|null $offset
	 * @return self
	 */
	public function limit(int $limit, ?int $offset = NULL): self 
	{
		$this->limit = (int)$limit;
		if(!is_null($offset)) $this->offset = (int)$offset;
		return $this;
	}

	/**
	 * Add a offset (if limit is not set then it will automatically become "1").
	 * @param  int    $offset
	 * @return self
	 */
	public function offset(int $offset): self 
	{
		$this->offset = (int)$offset;
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

		if(!is_null($this->mig) && !$this->mig->columns([(string)$col])) {
			throw new DBValidationException($this->mig->getMessage(), 1);
		}
		$sort = $this->orderSort($sort); // Whitelist
		$this->order[] = "{$col} {$sort}";
		return $this;
	}

	/**
	 * Raw Mysql ORDER input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id ASC, parent DESC)
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	public function orderRaw(string $sql, ...$arr): self 
	{
		if(is_array($arr[0] ?? NULL)) $arr = $arr[0];
		$this->order[] = $this->sprint($sql, $arr);
		return $this;
	}

	/**
	 * Add group
	 * @param  spread $columns
	 * @return self
	 */
	public function group(...$columns): self 
	{
		if(!is_null($this->mig) && !$this->mig->columns($columns)) {
			throw new DBValidationException($this->mig->getMessage(), 1);
		}
		$this->group = " GROUP BY ".implode(",", $this->prepArr($columns));
		return $this;
	}

	/**
	 * Mysql JOIN query (Default: INNER)
	 * Supports dynamic method name calls like: joinLeft, joinRight...
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string $table  Table name of the joined table
	 * @param  string $sql    SQL string example: (a.id = b.user_id AND status = '%d')
	 * @param  array  $sprint Mysql prep values
	 * @param  string $type   Valid join type ("INNER", "LEFT", "RIGHT", "CROSS"). Anything else will become "INNER".
	 * @return self
	 */
	public function join(string|array|MigrateInterface $table, string|array $where = NULL, array $sprint = array(), string $type = "INNER"): self 
	{
		if($table instanceof MigrateInterface) {
			
			$main = $this->getMainFKData();
			$prefix = Connect::prefix();
			$data = $table->getData();
			$this->mig->mergeData($data);

			foreach($data as $col => $row) {
				if(isset($row['fk'])) {
					foreach($row['fk'] as $a) {
						if($a['table'] === (string)$this->table) {
							$this->join[] = "{$type} JOIN ".$prefix.$table->getTable()." ".$table->getTable()." ON (".$table->getTable().".{$col} = {$a['table']}.{$a['column']})";
						}
					}

				} else {
					foreach($main as $c => $a) {
						foreach($a as $t => $d) {
							if(in_array($col, $d)) {
								$this->join[] = "{$type} JOIN ".$prefix.$table->getTable()." ".$table->getTable()." ON ({$t}.{$col} = {$this->alias}.{$c})";
							}
						}
					}
				}
			}
			
		} else {
			if(is_null($where)) throw new DBQueryException("You need to specify the argumnet 2 (where) value!", 1);

			$prefix = Connect::prefix();
			$arr = $this->sperateAlias($table);
			
			$table = (string)$this->prep($arr['table'], false);
			$alias = (!is_null($arr['alias'])) ? " {$arr['alias']}" : " {$table}";

			if(is_array($where)) {
				$data = array();
				foreach($where as $key => $val) {
					if(is_array($val)) {
						foreach($val as $k => $v) $this->setWhereData($k, $v, $data);
					} else {
						$this->setWhereData($key, $val, $data);
					}
				}
				$out = $this->buildWhere("", $data);
			} else {
				$out = $this->sprint($where, $sprint);
			}
			$type = $this->joinTypes(strtoupper($type)); // Whitelist
			$this->join[$table] = "{$type} JOIN {$prefix}{$table}{$alias} ON ".$out;
		}	

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
	 * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
	 * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
	 * @return self
	 */
	public function set(string|array|AttrInterface $key, string|array|AttrInterface $value = NULL): self 
	{
		if(is_array($key)) {
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
	public function onDupKey($key = NULL, ?string $value = NULL): self 
	{
		return $this->onDuplicateKey($key, $value);
	}

	// Same as onDupKey
	public function onDuplicateKey($key = NULL, ?string $value = NULL): self 
	{
		$this->dupSet = array();
		if(!is_null($key)) {
			if(is_array($key)) {
				$this->dupSet = $this->prepArr($key, true);
			} else {
				$this->dupSet[$key] = $this->prep($value);
			}
		}
		return $this;
	}

	/**
	 * Union result
	 * @param  DB $inst
	 * @param  bool  $allowDuplicate  UNION by default selects only distinct values. Use UNION ALL to also select duplicate values!
	 * @return self
	 */
	public function union(DB $inst, bool $allowDuplicate = false): self 
	{
		$this->order = NULL;
		$this->limit = NULL;
		$this->union = " UNION ".($allowDuplicate ? "ALL ": "").$inst->select()->sql();
		return $this;
	}

	/**
	 * Enclose value
	 * @param  string        $val
	 * @param  bool|boolean  $enclose disbale enclose
	 * @return string
	 */
	public function enclose(string|AttrInterface $val, bool $enclose = true): self 
	{
		if($val instanceof AttrInterface) return $val;
		if($enclose) return "'{$val}'";
		return $val;
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
	 * Execute query result
	 * @return object|array|bool
	 */
	public function execute(): object|array|bool 
	{
		$this->build();
		if($result = Connect::query($this->sql)) {
			return $result;	
		} else {
			throw new DBQueryException(Connect::DB()->error, 1);
		}
		return false;
	}

	/**
	 * Execute query result And fetch as obejct
	 * @return bool|object|array
	 */
	public function get(): bool|object|array
	{
		return $this->obj();
	}

	/**
	 * SAME AS @get(): Execute query result And fetch as obejct
	 * @return bool|object (Mysql result)
	 */
	final public function obj(): bool|object 
	{
		if(($result = $this->execute()) && $result->num_rows > 0) {
			return $result->fetch_object();
		}
		return false;
	}

	/**
	 * Execute SELECT and fetch as array with nested objects
	 * @param  function $callback callaback, make changes in query and if return then change key
	 * @return array
	 */
	final public function fetch(?callable $callback = NULL): array
	{
		$key = 0;
		$k = NULL;
		$arr = array();

		if(($result = $this->execute()) && $result->num_rows > 0) {
			while($row = $result->fetch_object()) {
				if($callback) $k = $callback($row, $key);
				$sk = ((!is_null($k)) ? $k : $key);
				if(is_array($sk)) {
					$arr = array_replace_recursive($arr, $k);
				} else {
					$arr[$sk] = $row;
				}
				
				$key++;
			}
		}
		return $arr;
	}

	/**
	 * Access Mysql DB connection
	 * @return query\connect
	 */
	public function db() {
		return Connect::DB();
	}

	/**
	 * Get insert AI ID from prev inserted result
	 * @return int
	 */
	public function insertID() {
		return Connect::DB()->insert_id;
	}

	/**
	 * Start Transaction 
	 * @return Transaction instance. You can use instance to call: inst->rollback() OR inst->commit()
	 */
	static function beginTransaction() {
		Connect::DB()->begin_transaction();
		return Connect::DB();
	}

	
	// Same as @beginTransaction
	static function transaction() {
		return self::beginTransaction();
	}

	/**
	 * Commit transaction
	 * @return void
	 */
	static function commit(): void 
	{
		Connect::DB()->commit();
	}

	/**
	 * Rollback transaction
	 * @return void
	 */
	static function rollback(): void 
	{
		Connect::DB()->rollback();
	}

	/**
	 * Get current instance Table name with prefix attached
	 * @return string
	 */
	public function getTable(bool $withAlias = false): string 
	{
		$alias = ($withAlias && !is_null($this->alias)) ? " {$this->alias}" : "";
		return Connect::prefix().$this->table.$alias;
	}

	/**
	 * Get current instance Columns
	 * @return array
	 */
	public function getColumns(): array 
	{
		if(!is_null($this->mig) && !$this->mig->columns($this->columns)) {
			throw new DBValidationException($this->mig->getMessage(), 1);
		}
		return $this->columns;
	}

	/**
	 * Get set
	 * @return array
	 */
	public function getSet(): array 
	{
		return $this->set;
	}

	/**
	 * Get method
	 * @return string
	 */
	public function getMethod(): string 
	{
		return $this->method;
	}
	
	/**
	 * Will reset Where input
	 */
	private function resetWhere(): void 
	{
		$this->whereAnd = "AND";
		$this->compare = "=";
	}

	/**
	 * Use to loop camel case method columns
	 * @param  array    $camelCaseArr
	 * @param  array    $valArr
	 * @param  callable $call
	 * @return void
	 */
	private function camelLoop(array $camelCaseArr, array $valArr, callable $call): void 
	{
		foreach($camelCaseArr as $k => $col) {
			$col = lcfirst($col);
			$value = ($valArr[$k] ?? NULL);
			$call($col, $value);
		}
	}

	/**
	 * Mysql Prep/protect string
	 * @param  string $val
	 * @return string
	 */
	private function prep(string|array|AttrInterface $val, bool $enclose = true): AttrInterface
	{
		if($val instanceof AttrInterface) {
			return $val;
		}
		$val = Attr::value($val);
		$val->enclose($enclose);
		return $val;
	}

	/**
	 * Mysql Prep/protect array items
	 * @param  array        $arr
	 * @param  bool|boolean $enclose Auto enclose?
	 * @param  bool|boolean $trim    Auto trime
	 * @return array
	 */
	private function prepArr(array $arr, bool $enclose = true) {
		$new = array();
		foreach($arr as $k => $v) {
			$key = (string)$this->prep($k, false);
			//$v = $this->prep($v, $enclose);
			//$value = $this->enclose($v, $enclose);
			$new[$key] = (string)$this->prep($v, $enclose);
		}
		return $new;
	}

	/**
	 * Build on YB to col sql string part
	 * @return string
	 */
	private function buildTableToCol(): string 
	{
		if(!is_null($this->join)) {
			$new = array();
			$keys = array_keys($this->join);
			array_unshift($keys, $this->getTable());
			foreach($keys as $val) {
				$a = explode(" ", $val);
				$a = array_filter($a);
				$new[] = end($a);
			}

			return " ".implode(",", $new);
		}
		return "";
	}

	/**
	 * Build on insert set sql string part
	 * @return string
	 */
	private function buildInsertSet(?array $arr = NULL): string 
	{
		if(is_null($arr)) $arr = $this->set;
		$columns = array_keys($arr);
		$columns = implode(",", $columns); 
		$values = implode(",", $this->set);
		return "({$columns}) VALUES ({$values})";
	}

	/**
	 * Build on update set sql string part
	 * @return string
	 */
	private function buildUpdateSet(?array $arr = NULL): string 
	{
		if(is_null($arr)) $arr = $this->set;
		$new = array();
		foreach($arr as $key => $val) $new[] = "{$key} = {$val}";
		return implode(",", $new);
	}

	/**
	 * Build on duplicate sql string part
	 * @return string
	 */
	private function buildDuplicate(): string 
	{
		if(!is_null($this->dupSet)) {
			$set = (count($this->dupSet) > 0) ? $this->dupSet : $this->set;
			return " ON DUPLICATE KEY UPDATE ".$this->buildUpdateSet($set);
		}
		return "";
	}

	/**
	 * Will build the 
	 * @param  string $prefix [description]
	 * @param  array  $where  [description]
	 * @return [type]         [description]
	 */
	private function buildWhere(string $prefix, ?array $where): string 
	{
		$out = "";
		if(!is_null($where)) {
			$out = " {$prefix}";
			$i = 0;
			foreach($where as $array) {
				$firstAnd = key($array);
				$andOr = "";
				$out .= (($i > 0) ? " {$firstAnd}" : "")." (";
				$c = 0;
				foreach($array as $key => $arr) {
					foreach($arr as $operator => $a) {
						if(is_array($a)) {
							foreach($a as $col => $b) {
								foreach($b as $val) {
									if($c > 0) $out .= "{$key} ";
									$out .= "{$col} {$operator} {$val} ";
									$c++;
								}
							}

						} else {
							$out .= "{$key} {$a} ";
							$c++;
						}
					}
				}
				$out .= ")";
				$i++;
			}
		}
		return $out;
	}

	private function buildJoin(): string 
	{
		return (!is_null($this->join)) ? " ".implode(" ", $this->join) : "";
	}

	private function buildLimit(): string 
	{
		if(is_null($this->limit) && !is_null($this->offset)) $this->limit = 1;
		$offset = (!is_null($this->offset)) ? ",{$this->offset}" : "";
		return (!is_null($this->limit)) ? " LIMIT {$this->limit}{$offset}" : "";
	}

	//...Whitelist START... 

	/**
	 * Whitelist comparison operators
	 * @param  string $val
	 * @return string
	 */
	private function operator(string $val): string 
	{
		$val = trim($val);
		if(in_array($val, $this::OPERATORS)) {
			return $val;
		}
		return "=";
	}

	/**
	 * Whitelist mysql sort directions
	 * @param  string $val
	 * @return string
	 */
	private function orderSort(string $val): string 
	{
		$val = strtoupper($val);
		if($val === "ASC" || $val === "DESC") {
			return $val;
		}
		return "ASC";
	}

	/**
	 * Whitelist mysql join types
	 * @param  string $val
	 * @return string
	 */
	private function joinTypes(string $val): string 
	{
		$val = trim($val);
		if(in_array($val, $this::JOIN_TYPES)) {
			return $val;
		}
		return "INNER";
	}

	//...Whitelist END... 

	/**
	 * Use vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	protected function sprint(string $str, array $arr = array()): string 
	{
		return vsprintf($str, $this->prepArr($arr, false));
	}

	/**
	 * Sperate Alias
	 * @param  array  $data
	 * @return array
	 */
	protected function sperateAlias(string|array $data): array
	{
		if(is_array($data)) {
			if(count($data) !== 2) throw new DBQueryException("If you specify Table as array then it should look like this [TABLE_NAME, ALIAS]", 1);
			$alias = array_pop($data);
			$table = reset($data);

		} else {
			$alias = NULL;
			$table = $data;
		}

		return ["alias" => $alias, "table" => $table];
	}

	/**
	 * Will extract camle case to array
	 * @param  string $value string value with possible camel cases
	 * @return array
	 */
	final protected function extractCamelCase(string $value): array 
	{
		$arr = array();
		if(is_string($value)) {
			$arr = preg_split('#([A-Z][^A-Z]*)#', $value, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		}
		return $arr;
	}

	/**
	 * Get the Main FK data protocol
	 * @return array
	 */
	final protected function getMainFKData(): array 
	{
		if(is_null($this->fkData)) {
			$this->fkData = array();
			foreach($this->mig->getMig()->getData() as $col => $row) {
				if(isset($row['fk'])) {
					foreach($row['fk'] as $a) $this->fkData[$col][$a['table']][] = $a['column'];
				}
			}
		}
		return $this->fkData;
	}

	/**
	 * Propegate where data structure
	 * @param string|AttrInterface $key
	 * @param string|AttrInterface $val
	 * @param array|null &$data static value
	 */
	final protected function setWhereData(string|AttrInterface $key, string|AttrInterface $val, ?array &$data): void 
	{
		$key = (string)$this->prep($key, false);
		$val = $this->prep($val);

		if(!is_null($this->mig) && !$this->mig->where($key, $val)) {
			throw new DBValidationException($this->mig->getMessage(), 1);
		}

		$data[$this->whereIndex][$this->whereAnd][$this->compare][$key][] = $val;
		$this->whereProtocol[$key][] = $val;
		$this->resetWhere();
	}

	/**
	 * Used to call methoed that builds SQL queryies
	 */
	final protected function build(): void
	{
		if(!is_null($this->method) && method_exists($this, $this->method)) {
			$inst = (!is_null($this->dynamic)) ? call_user_func_array($this->dynamic[0], $this->dynamic[1]) : $this->{$this->method}();
			if(is_null($this->sql)) {
				throw new DBQueryException("The Method \"{$this->method}\" expect to return a sql building method (like return @select() or @insert()).", 1);
			}

		} else {
			if(is_null($this->sql)) {
				$m = is_null($this->method) ? "NULL" : $this->method;
				throw new DBQueryException("Method \"{$m}\" does not exists! You need to create a method that with same name as static, that will build the query you are after. Take a look att method @method->select.", 1);
			}
		}
	}

}
