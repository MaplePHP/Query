<?php
/**
 * Wazabii DB - For main queries
 */
namespace Query;

class DB {

	// Whitelists
	const OPERATORS = [">", ">=", "<", "<>", "!=", "<=", "<=>"]; // Comparison operators
	const JOIN_TYPES = ["INNER", "LEFT", "RIGHT", "CROSS"]; // Join types

	private $_method;
	private $_explain;
	private $_table;
	private $_columns;
	private $_where;
	private $_having;
	private $_set = array();
	private $_dupSet;
	private $_whereAnd = "AND";
	private $_compare = "=";
	private $_whereIndex = 0;
	private $_limit;
	private $_offset;
	private $_order;
	private $_join;
	private $_distinct;
	private $_group;
	private $_noCache;
	private $_calRows;
	private $_union;
	private $_viewName;
	private $_sql;

	static function __callStatic($method, $args) {

		if(strpos($method, "_") === 0 && count($args) > 0) {

			$table = array_pop($args);
			$inst = static::_table($table);
			$inst->_method = substr($method, 1);

			if($inst->_method === "select" && isset($args[0])) {
				$col = explode(",", $args[0]);
				call_user_func_array([$inst, "columns"], $col);
			}

			if($inst->_method === "createView" || $inst->_method === "replaceView") {
				$inst->_viewName = Connect::_prefix()."{$args[0]}";
			}

			if($inst->_method === "dropView") $inst->_viewName = Connect::_prefix()."{$table}";

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
				$this->_columns = $this->_prepArr($args, false);

			break;
			case "where":
				$this->_camelLoop($camelCaseArr, $args, function($col, $val) {
					$this->where($col, $val);
				});
			break;
			case "having":
				$this->_camelLoop($camelCaseArr, $args, function($col, $val) {
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
				$this->_group = " GROUP BY ".implode(",", $this->_prepArr($args));
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
	static function _table(string $table) {
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
		$this->_compare = $this->_operator($operator);
		return $this;
	}

	/**
	 * Chaining where with mysql "AND" or with "OR"
	 * @return self
	 */
	function and() {
		$this->_whereAnd = "AND";
		return $this;
	}

	/**
	 * Chaining where with mysql "AND" or with "OR"
	 * @return self
	 */
	function or() {
		$this->_whereAnd = "OR";
		return $this;
	}
	/**
	 * Use vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	function sprint(string $str, array $arr = array()) {
		return vsprintf($str, $this->_prepArr($arr, false));
	}


	/**
	 * Raw Mysql Where input
	 * Uses vsprintf to mysql prep/protect input in string. Prep string values needs to be eclosed manually 
	 * @param  string    $str     SQL string example: (id = %d AND permalink = '%s')
	 * @param  array     $arr     Mysql prep values
	 * @return self
	 */
	function whereRaw(string $sql, array $arr = array()) {
		$this->_resetWhere();
		$this->_where[$this->_whereIndex][$this->_whereAnd][] = $this->sprint($sql, $arr);
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
		if(!is_null($operator)) $this->_compare = $this->_operator($operator);
		$this->_where[$this->_whereIndex][$this->_whereAnd][$this->_compare][$key] = $this->prep($val);
		$this->_compare = "=";
		return $this;
	}

	/**
	 * Group mysql WHERE inputs
	 * @param  callable $call  Evere method where placed inside callback will be grouped.
	 * @return self
	 */
	function whereBind(callable $call) {
		if(!is_null($this->_where)) $this->_whereIndex++;
		$this->_resetWhere();
		$call($this);
		$this->_whereIndex++;
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
		if(!is_null($operator)) $this->_compare = $this->_operator($operator);
		$this->_having[$this->_whereIndex][$this->_whereAnd][$this->_compare][$key] = $this->prep($val);
		$this->_compare = "=";
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
		$this->_resetWhere();
		$this->_having[$this->_whereIndex][$this->_whereAnd][] = $this->sprint($sql, $arr);
	}

	/**
	 * Add a limit and maybee a offset
	 * @param  int      $limit
	 * @param  int|null $offset
	 * @return self
	 */
	function limit(int $limit, ?int $offset = NULL) {
		$this->_limit = (int)$limit;
		if(!is_null($offset)) $this->_offset = (int)$offset;
		return $this;
	}

	/**
	 * Add a offset (if limit is not set then it will automatically become "1").
	 * @param  int    $offset
	 * @return self
	 */
	function offset(int $offset) {
		$this->_offset = (int)$offset;
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
		$sort = $this->_orderSort($sort);

		$this->_order[] = "{$col} {$sort}";
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
		$this->_order[] = $this->sprint($sql, $arr);
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
		$prefix = Connect::_prefix();
		$type = $this->_joinTypes(strtoupper($type));
		$this->_join[$table] = "{$type} JOIN {$prefix}{$table} ON ".$this->sprint($sql, $sprint);
		return $this;
	}

	/**
	 * Disable mysql query cache
	 * @return self
	 */
	function noCache() {
		$this->_noCache = "SQL_NO_CACHE ";
		return $this;
	}

	/**
	 * Add make query a distinct call
	 * @return self
	 */
	function distinct() {
		$this->_distinct = "DISTINCT ";
		return $this;
	}

	/**
	 * Exaplain the mysql query. Will tell you how you can make improvements
	 * @return self
	 */
	function explain() {
		$this->_explain = "EXPLAIN ";
		return $this;
	}

	/**
	 * DEPRECATE: Calculate rows in query
	 * @return self
	 */
	function calcRows() {
		$this->_calRows = "SQL_CALC_FOUND_ROWS ";
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
			$this->_set = array_merge($this->_set, $this->_prepArr($key, true));
		} else {
			$this->_set[$key] = $this->enclose($this->prep($value));
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
		$this->_dupSet = array();
		if(!is_null($key)) {
			if(is_array($key)) {
				$this->_dupSet = $this->_prepArr($key, true);
			} else {
				$this->_dupSet[$key] = $this->enclose($this->prep($value));
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
		$this->_set[$key] = $value;
		return $this;
	}

	/**
	 * Union result
	 * @param  DB $inst
	 * @param  bool  $allowDuplicate  UNION by default selects only distinct values. Use UNION ALL to also select duplicate values!
	 * @return self
	 */
	function union(DB $inst, bool $allowDuplicate = false) {
		$this->_order = NULL;
		$this->_limit = NULL;
		$this->_union = " UNION ".($allowDuplicate ? "ALL ": "").$inst->select()->sql();
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
		$this->_build();
		return $this->_sql;
	}

	/**
	 * Build SELECT sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function select() {
		$columns = is_null($this->_columns) ? "*" : implode(",", $this->_columns);
		$join = $this->_buildJoin();
		$where = $this->_buildWhere("WHERE", $this->_where);
		$having = $this->_buildWhere("HAVING", $this->_having);
		$order = (!is_null($this->_order)) ? " ORDER BY ".implode(",", $this->_order) : "";
		$limit = $this->_buildLimit();
		$this->_sql = "{$this->_explain}SELECT {$this->_noCache}{$this->_calRows}{$this->_distinct}{$columns} FROM ".$this->getTable()."{$join}{$where}{$this->_group}{$having}{$order}{$limit}{$this->_union}";

		return $this;
	}

	/**
	 * Build INSERT sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function insert() {
		$this->_sql = "{$this->_explain}INSERT INTO ".$this->getTable()." ".$this->_buildInsertSet().$this->_buildDuplicate();
		return $this;
	}

	/**
	 * Build UPDATE sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function update() {
		$join = $this->_buildJoin();
		$where = $this->_buildWhere("WHERE", $this->_where);
		$limit = $this->_buildLimit();

		$this->_sql = "{$this->_explain}UPDATE ".$this->getTable()."{$join} SET ".$this->_buildUpdateSet()."{$where}{$limit}";
		return $this;
	}

	/**
	 * Build DELETE sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function delete() {
		$tbToCol = $this->_buildTableToCol();
		$join = $this->_buildJoin();
		$where = $this->_buildWhere("WHERE", $this->_where);
		$limit = $this->_buildLimit();

		$this->_sql = "{$this->_explain}DELETE{$tbToCol} FROM ".$this->getTable()."{$join}{$where}{$limit}";
		return $this;
	}

	/**
	 * Build CREATE VIEW sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function createView() {
		$this->select();
		$this->_sql = "CREATE VIEW ".$this->_viewName." AS {$this->_sql}";
		return $this;
	}

	/**
	 * Build CREATE OR REPLACE VIEW sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function replaceView() {
		$this->select();
		$this->_sql = "CREATE OR REPLACE VIEW ".$this->_viewName." AS {$this->_sql}";
		return $this;
	}

	/**
	 * Build DROP VIEW sql code (The method will be auto called in method _build)
	 * @return self
	 */
	protected function dropView() {
		$this->_sql = "DROP VIEW ".$this->_viewName;
		return $this;
	}

	/**
	 * Used to call methoed that builds SQL queryies
	 */
	private function _build() {
		if(method_exists($this, $this->_method)) {
			return $this->{$this->_method}();
		} else {
			if(is_null($this->_sql)) {
				throw new \Exception("Method \"{$this->_method}\" does not exists! You need to create a method that with same name as static, that will build the query you are after. Take a look att method @method->select.", 1);
			}
			
		}
	}

	/**
	 * Execute query result
	 * @return object (Mysql result)
	 */
	function execute() {
		$this->_build();
		if($result = Connect::_query($this->_sql)) {
			return $result;	

		} else {
			throw new \Exception(Connect::_DB()->error, 1);
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
		return Connect::_DB();
	}

	/**
	 * Get insert AI ID from prev inserted result
	 * @return int
	 */
	function insertID() {
		return Connect::_DB()->insert_id;
	}

	/**
	 * Start Transaction 
	 * @return Transaction instance. You can use instance to call: inst->rollback() OR inst->commit()
	 */
	static function _beginTransaction() {
		Connect::_DB()->begin_transaction();
		return Connect::_DB();
	}

	
	// Same as @_beginTransaction
	static function _transaction() {
		return self::_beginTransaction();
	}

	/**
	 * Commit transaction
	 * @return self
	 */
	static function _commit() {
		Connect::_DB()->commit();
		return self;
	}

	/**
	 * Rollback transaction
	 * @return self
	 */
	static function _rollback() {
		Connect::_DB()->rollback();
		return self;
	}

	/**
	 * Get current instance Table name with prefix attached
	 * @return string
	 */
	function getTable() {
		return Connect::_prefix().$this->_table;
	}

	/**
	 * Get current instance Columns
	 * @return array
	 */
	function getColumns() {
		return $this->_columns;
	}
	
	/**
	 * Will reset Where input
	 */
	private function _resetWhere() {
		$this->_whereAnd = "AND";
		$this->_compare = "=";
	}

	private function _camelLoop(array $camelCaseArr, array $valArr, callable $call) {
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
		return Connect::_prep($val);
	}

	/**
	 * Mysql Prep/protect array items
	 * @param  array        $arr
	 * @param  bool|boolean $enclose Auto enclose?
	 * @param  bool|boolean $trim    Auto trime
	 * @return array
	 */
	private function _prepArr(array $arr, bool $enclose = true, bool $trim = false) {
		$new = array();
		foreach($arr as $k => $v) {
			$key = $this->prep($k);
			if($trim) $v = trim($v);
			$value = $this->enclose($this->prep($v), $enclose);
			$new[$key] = $value;
		}
		return $new;
	}

	private function _buildTableToCol() {
		if(!is_null($this->_join)) {
			$new = array();
			$keys = array_keys($this->_join);
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

	private function _buildInsertSet(?array $arr = NULL) {
		if(is_null($arr)) $arr = $this->_set;
		$columns = array_keys($arr);
		$columns = implode(",", $columns); 
		$values = implode(",", $this->_set);
		return "({$columns}) VALUES ({$values})";
	}

	private function _buildUpdateSet(?array $arr = NULL) {
		if(is_null($arr)) $arr = $this->_set;
		$new = array();
		foreach($arr as $key => $val) $new[] = "{$key} = {$val}";
		return implode(",", $new);
	}

	private function _buildDuplicate() {
		if(!is_null($this->_dupSet)) {
			$set = (count($this->_dupSet) > 0) ? $this->_dupSet : $this->_set;
			return " ON DUPLICATE KEY UPDATE ".$this->_buildUpdateSet($set);
		}
		return "";
	}

	private function _buildWhere(string $prefix, ?array $where) {
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

	private function _buildJoin() {
		return (!is_null($this->_join)) ? " ".implode(" ", $this->_join) : "";
	}

	private function _buildLimit() {
		if(is_null($this->_limit) && !is_null($this->_offset)) $this->_limit = 1;
		$offset = (!is_null($this->_offset)) ? ",{$this->_offset}" : "";
		return (!is_null($this->_limit)) ? " LIMIT {$this->_limit}{$offset}" : "";
	}



	//...Whitelist...

	/**
	 * Whitelist comparison operators
	 * @param  string $val
	 * @return string
	 */
	private function _operator(string $val) {
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
	private function _orderSort(string $val) {
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
	private function _joinTypes(string $val) {
		$val = trim($val);
		if(in_array($val, $this::JOIN_TYPES)) {
			return $val;
		}
		return "INNER";
	}



	/**
	 * Profile mysql speed
	 */
	static function _startProfile() {
		Connect::_query("set profiling=1");
	}

	/**
	 * Close profile and print results
	 */
	static function _endProfile($html = true) {
		$totalDur = 0;
		$rs = Connect::_query("show profiles");

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