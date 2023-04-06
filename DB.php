<?php
/**
 * Wazabii DB - For main queries
 */
namespace PHPFuse\Query;

class DB {

	// Whitelists
	const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
	const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types

	private $method;
	private $explain;
	private $table;
	private $columns;
	private $where;
	private $having;
	private $set = array();
	private $dupSet;
	private $whereAnd = "AND";
	private $compare = "=";
	private $whereIndex = 0;
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

	static function __callStatic($method, $args) {
		if(count($args) > 0) {
			$table = array_pop($args);
			$inst = static::table($table);
			$inst->method = $method;

			if($inst->method === "select" && isset($args[0])) {
				$col = explode(",", $args[0]);
				call_user_func_array([$inst, "columns"], $col);
			}

			if($inst->method === "createView" || $inst->method === "replaceView") {
				$inst->viewName = Connect::prefix()."{$args[0]}";
			}

			if($inst->method === "dropView") $inst->viewName = Connect::prefix()."{$table}";

		} else {
			$inst = new static();
		}
		
		return $inst;
	}

	function __call($method, $args) {
		$camelCaseArr = preg_split('#([A-Z][^A-Z]*)#', $method, null, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$shift = array_shift($camelCaseArr);

		switch($shift) {
			case "columns": case "column": case "col": case "pluck":
				if(is_array($args[0] ?? NULL)) $args = $args[0];
				$this->columns = $this->prepArr($args, false);
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
				$this->join($args[0], $args[1], ($args[2] ?? []), $camelCaseArr[0]);
			break;
			case "group":
				if(is_array($args[0] ?? NULL)) $args = $args[0];
				$this->group = " GROUP BY ".implode(",", $this->prepArr($args));
			break;
			default;
				throw new \Exception("Method \"{$method}\" does not exists!", 1);
				
		}

		return $this;
	}

	/**
	 * You can build queries like Larvel If you want. I do not think they have good semantics tho.
	 * @param  string $table Mysql table name
	 * @return self new intance
	 */
	static function table(string $table) {
		$inst = new static();
		$inst->_table = $inst->prep($table);
		return $inst;
	}
	
	/**
	 * Change where compare operator from default "=". 
	 * Will change back to default after where method is triggered
	 * @param  string $operator once of (">", ">=", "<", "<>", "!=", "<=", "<=>")
	 * @return self
	 */
	function compare(string $operator) {
		$this->compare = $this->operator($operator);
		return $this;
	}

	/**
	 * Chaining where with mysql "AND" or with "OR"
	 * @return self
	 */
	function and() {
		$this->whereAnd = "AND";
		return $this;
	}

	/**
	 * Chaining where with mysql "AND" or with "OR"
	 * @return self
	 */
	function or() {
		$this->whereAnd = "OR";
		return $this;
	}
	/**
	 * Use vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	function sprint(string $str, array $arr = array()) {
		return vsprintf($str, $this->prepArr($arr, false));
	}


	/**
	 * Raw Mysql Where input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	function whereRaw(string $sql, array $arr = array()) {
		$this->resetWhere();
		$this->where[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
	}

	/**
	 * Create protected MySQL WHERE input
	 * Supports dynamic method name calls like: whereIdStatus(1, 0)
	 * @param  string      $key      Mysql column
	 * @param  string      $val      Equals to value
	 * @param  string|null $operator Change comparison operator from default "=".
	 * @return self
	 */
	function where(string $key, string $val, ?string $operator = NULL) {
		if(!is_null($operator)) $this->compare = $this->operator($operator);
		$this->where[$this->whereIndex][$this->whereAnd][$this->compare][$key] = $this->prep($val);
		$this->compare = "=";
		return $this;
	}

	/**
	 * Group mysql WHERE inputs
	 * @param  callable $call  Evere method where placed inside callback will be grouped.
	 * @return self
	 */
	function whereBind(callable $call) {
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
	function having(string $key, string $val, ?string $operator = NULL) {
		if(!is_null($operator)) $this->compare = $this->operator($operator);
		$this->having[$this->whereIndex][$this->whereAnd][$this->compare][$key] = $this->prep($val);
		$this->compare = "=";
		return $this;
	}

	/**
	 * Raw Mysql HAVING input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	function havingRaw(string $sql, array $arr = array()) {
		$this->resetWhere();
		$this->having[$this->whereIndex][$this->whereAnd][] = $this->sprint($sql, $arr);
	}

	/**
	 * Add a limit and maybee a offset
	 * @param  int      $limit
	 * @param  int|null $offset
	 * @return self
	 */
	function limit(int $limit, ?int $offset = NULL) {
		$this->limit = (int)$limit;
		if(!is_null($offset)) $this->offset = (int)$offset;
		return $this;
	}

	/**
	 * Add a offset (if limit is not set then it will automatically become "1").
	 * @param  int    $offset
	 * @return self
	 */
	function offset(int $offset) {
		$this->offset = (int)$offset;
		return $this;
	}


	/**
	 * Set Mysql ORDER
	 * @param  string $col  Mysql Column
	 * @param  string $sort Mysql sort type. Only "ASC" OR "DESC" is allowed, anything else will become "ASC".
	 * @return self
	 */
	function order(string $col, string $sort = "ASC") {
		$col = $this->prep($col);
		$sort = $this->orderSort($sort);

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
	function orderRaw(string $sql, array $arr = array()) {
		$this->order[] = $this->sprint($sql, $arr);
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
	function join(string $table, string $sql, array $sprint = array(), string $type = "INNER") {
		$prefix = Connect::prefix();
		$type = $this->joinTypes(strtoupper($type));
		$this->join[$table] = "{$type} JOIN {$prefix}{$table} ON ".$this->sprint($sql, $sprint);
		return $this;
	}

	/**
	 * Disable mysql query cache
	 * @return self
	 */
	function noCache() {
		$this->noCache = "SQL_NO_CACHE ";
		return $this;
	}

	/**
	 * Add make query a distinct call
	 * @return self
	 */
	function distinct() {
		$this->distinct = "DISTINCT ";
		return $this;
	}

	/**
	 * Exaplain the mysql query. Will tell you how you can make improvements
	 * @return self
	 */
	function explain() {
		$this->explain = "EXPLAIN ";
		return $this;
	}

	/**
	 * DEPRECATE: Calculate rows in query
	 * @return self
	 */
	function calcRows() {
		$this->calRows = "SQL_CALC_FOUND_ROWS ";
		return $this;
	}

	/**
	 * Create INSERT or UPDATE set Mysql input to insert
	 * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
	 * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
	 * @return self
	 */
	function set($key, ?string $value = NULL) {
		if(is_array($key)) {
			$this->set = array_merge($this->set, $this->prepArr($key, true));
		} else {
			$this->set[$key] = $this->enclose($this->prep($value));
		}
		return $this;
	}

	/**
	 * Update if ID KEY is duplicate else insert
	 * @param  string|array  $key    (string) "name" OR (array) ["id" => 1, "name" => "Lorem ipsum"]
	 * @param  string|null   $value  If key is string then value will pair with key "Lorem ipsum"
	 * @return self
	 */
	function onDupKey($key = NULL, ?string $value = NULL) {
		return $this->onDuplicateKey($key, $value);
	}

	// Same as onDupKey
	function onDuplicateKey($key = NULL, ?string $value = NULL) {
		$this->dupSet = array();
		if(!is_null($key)) {
			if(is_array($key)) {
				$this->dupSet = $this->prepArr($key, true);
			} else {
				$this->dupSet[$key] = $this->enclose($this->prep($value));
			}
		}
		return $this;
	}

	/**
	 * UPROTECTED: Create INSERT or UPDATE set Mysql input to insert
	 * @param string $key   Mysql column
	 * @param string $value Input/insert value (UPROTECTED and Will not enclose)
	 */
	function setRaw(string $key, string $value) {
		$this->set[$key] = $value;
		return $this;
	}

	/**
	 * Union result
	 * @param  DB $inst
	 * @param  bool  $allowDuplicate  UNION by default selects only distinct values. Use UNION ALL to also select duplicate values!
	 * @return self
	 */
	function union(DB $inst, bool $allowDuplicate = false) {
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
	function enclose(string $val, bool $enclose = true) {
		if($enclose) {
			return "'{$val}'";
		}
		return $val;
	}

	/**
	 * Genrate SQL string of current instance/query
	 * @return string
	 */
	function sql() {
		$this->build();
		return $this->sql;
	}

	/**
	 * Build SELECT sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function select() {
		$columns = is_null($this->columns) ? "*" : implode(",", $this->columns);
		$join = $this->buildJoin();
		$where = $this->buildWhere("WHERE", $this->where);
		$having = $this->buildWhere("HAVING", $this->having);
		$order = (!is_null($this->order)) ? " ORDER BY ".implode(",", $this->order) : "";
		$limit = $this->buildLimit();
		$this->sql = "{$this->explain}SELECT {$this->noCache}{$this->calRows}{$this->distinct}{$columns} FROM ".$this->getTable()."{$join}{$where}{$this->group}{$having}{$order}{$limit}{$this->union}";

		return $this;
	}

	/**
	 * Build INSERT sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function insert() {
		$this->sql = "{$this->explain}INSERT INTO ".$this->getTable()." ".$this->buildInsertSet().$this->buildDuplicate();
		return $this;
	}

	/**
	 * Build UPDATE sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function update() {
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
	protected function delete() {
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
	protected function createView() {
		$this->select();
		$this->sql = "CREATE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function replaceView() {
		$this->select();
		$this->sql = "CREATE OR REPLACE VIEW ".$this->viewName." AS {$this->sql}";
		return $this;
	}

	/**
	 * Build DROP VIEW sql code (The method will be auto called in method build)
	 * @return self
	 */
	protected function dropView() {
		$this->sql = "DROP VIEW ".$this->viewName;
		return $this;
	}

	/**
	 * Used to call methoed that builds SQL queryies
	 */
	private function build() {
		if(method_exists($this, $this->method)) {
			return $this->{$this->method}();
		} else {
			if(is_null($this->sql)) {
				throw new \Exception("Method \"{$this->method}\" does not exists! You need to create a method that with same name as static, that will build the query you are after. Take a look att method @method->select.", 1);
			}
			
		}
	}

	/**
	 * Execute query result
	 * @return object (Mysql result)
	 */
	function execute() {
		$this->build();
		if($result = Connect::query($this->sql)) {
			return $result;	

		} else {
			throw new \Exception(Connect::DB()->error, 1);
		}
		return false;
	}

	/**
	 * Execute query result And fetch as obejct
	 * @return object (Mysql result)
	 */
	function get() {
		return $this->obj();
	}

	/**
	 * SAME AS @get(): Execute query result And fetch as obejct
	 * @return object (Mysql result)
	 */
	function obj() {
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
	function fetch(?callable $callback = NULL) {
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
	function db() {
		return Connect::DB();
	}

	/**
	 * Get insert AI ID from prev inserted result
	 * @return int
	 */
	function insertID() {
		return Connect::DB()->insert_id;
	}

	/**
	 * Start Transaction 
	 * @return Transaction instance. You can use instance to call: inst->rollback() OR inst->commit()
	 */
	static function beginTransaction() {
		Connect::DB()->begintransaction();
		return Connect::DB();
	}

	
	// Same as @beginTransaction
	static function transaction() {
		return self::beginTransaction();
	}

	/**
	 * Commit transaction
	 * @return self
	 */
	static function commit() {
		Connect::DB()->commit();
		return self;
	}

	/**
	 * Rollback transaction
	 * @return self
	 */
	static function rollback() {
		Connect::DB()->rollback();
		return self;
	}

	/**
	 * Get current instance Table name with prefix attached
	 * @return string
	 */
	function getTable() {
		return Connect::prefix().$this->_table;
	}

	/**
	 * Get current instance Columns
	 * @return array
	 */
	function getColumns() {
		return $this->columns;
	}
	
	/**
	 * Will reset Where input
	 */
	private function resetWhere() {
		$this->whereAnd = "AND";
		$this->compare = "=";
	}

	private function camelLoop(array $camelCaseArr, array $valArr, callable $call) {
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
	function prep(string $val) {
		return Connect::prep($val);
	}

	/**
	 * Mysql Prep/protect array items
	 * @param  array        $arr
	 * @param  bool|boolean $enclose Auto enclose?
	 * @param  bool|boolean $trim    Auto trime
	 * @return array
	 */
	private function prepArr(array $arr, bool $enclose = true, bool $trim = false) {
		$new = array();
		foreach($arr as $k => $v) {
			$key = $this->prep($k);
			if($trim) $v = trim($v);
			$value = $this->enclose($this->prep($v), $enclose);
			$new[$key] = $value;
		}
		return $new;
	}

	private function buildTableToCol() {
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

	private function buildInsertSet(?array $arr = NULL) {
		if(is_null($arr)) $arr = $this->set;
		$columns = array_keys($arr);
		$columns = implode(",", $columns); 
		$values = implode(",", $this->set);
		return "({$columns}) VALUES ({$values})";
	}

	private function buildUpdateSet(?array $arr = NULL) {
		if(is_null($arr)) $arr = $this->set;
		$new = array();
		foreach($arr as $key => $val) $new[] = "{$key} = {$val}";
		return implode(",", $new);
	}

	private function buildDuplicate() {
		if(!is_null($this->dupSet)) {
			$set = (count($this->dupSet) > 0) ? $this->dupSet : $this->set;
			return " ON DUPLICATE KEY UPDATE ".$this->buildUpdateSet($set);
		}
		return "";
	}

	private function buildWhere(string $prefix, ?array $where) {
		$out = "";
		if(!is_null($where)) {
			$out = " {$prefix}";
			foreach($where as $i => $array) {
				$firstAnd = key($array);
				$andOr = "";
				$out .= (($i) ? " {$firstAnd}" : "")." (";
				foreach($array as $key => $arr) {
					foreach($arr as $operator => $a) {
						if(is_array($a)) {
							foreach($a as $col => $val) {
								$out .= "{$andOr} {$col} {$operator} '{$val}' ";
								$andOr = $key;
							}
						} else {
							$out .= "{$andOr} {$a} ";
							$andOr = $key;
						}
					}
				}
				$out .= ")";
			}
		}
		return $out;
	}

	private function buildJoin() {
		return (!is_null($this->join)) ? " ".implode(" ", $this->join) : "";
	}

	private function buildLimit() {
		if(is_null($this->limit) && !is_null($this->offset)) $this->limit = 1;
		$offset = (!is_null($this->offset)) ? ",{$this->offset}" : "";
		return (!is_null($this->limit)) ? " LIMIT {$this->limit}{$offset}" : "";
	}



	//...Whitelist...

	/**
	 * Whitelist comparison operators
	 * @param  string $val
	 * @return string
	 */
	private function operator(string $val) {
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
	private function orderSort(string $val) {
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
	private function joinTypes(string $val) {
		$val = trim($val);
		if(in_array($val, $this::JOIN_TYPES)) {
			return $val;
		}
		return "INNER";
	}



	/**
	 * Profile mysql speed
	 */
	static function startProfile() {
		Connect::query("set profiling=1");
	}

	/**
	 * Close profile and print results
	 */
	static function endProfile($html = true) {
		$totalDur = 0;
		$rs = Connect::query("show profiles");

		$output = "";
		if($html) $output .= "<p style=\"color: red;\">";
		while($rd = $rs->fetch_object()) {
			$dur = round($rd->Duration, 4) * 1000;
			$totalDur += $dur;
		    $output .= $rd->Query_ID.' - <strong>'.$dur.' ms</strong> - '.$rd->Query."<br>\n";
		}
		$total = round($totalDur, 4);
		
		if($html) {
			$output .= "Total: ".$total." ms\n";
			$output .= "</p>";
			echo $output;
		} else {
			return array("row" => $output, "total" => $total);
		}
	}

}

?>