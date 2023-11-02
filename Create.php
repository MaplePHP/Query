<?php
/**
	// USAGE:

	// Init class
	// Arg1: Table name
	$mig = new \query\create("test");

	// Rename: IF table name is "test" or "test1" then it will be renamed to "test2".
	// Has to be the first method to be called
	//$mig->rename(["test1", "test2"]);

	// Will create new stuff and alter current stuff
	$mig->auto();

	// Only create table
	//$mig->create();

	// Only alter table
	//$mig->alter();

	// Only drop table
	//$mig->drop();

	// Add/alter columns
	$result = $mig->column("id", [
	    "type" => "int",
	    "length" => 11,
	    "attr" => "unsigned",
	    "index" => "primary",
	    "ai" => true

	])->column("testKey", [
	    // Drop: Will drop the column
	    "drop" => true,
	    "type" => "int",
	    "length" => 11,
	    "index" => "index",
	    "attr" => "unsigned",
	    "default" => "0"

	])->column("name", [
	    "type" => "varchar",
	    "length" => 200,
	    "collate" => true,
	    "default" => ""

	])->column("loremname_1", [
	    // Rename: IF old column name is "loremname_1" or "loremname_2" then it will be renamed to "permalink"
	    "rename" => ["loremname_2", "permalink"],
	    "type" => "varchar",
	    "index" => "index",
	    "length" => 200,
	    "collate" => true

	]);

	// Will execute migration
	$mig->execute();

	// Get migration in SQL string (CAN be called before @execute);
	echo "<pre>";
	print_r($mig->build());
	echo "</pre>";
*/


namespace PHPFuse\Query;

use PHPFuse\Query\Exceptions\QueryCreateException;

class Create {

	private $_sql;
	private $_add;
	private $_addArr = array();
	private $_prefix;
	private $_type;
	private $_args;
	private $_table;
	private $_tableText;
	private $_col;
	private $_prev;
	private $_charset;
	private $_engine;
	private $_rowFormat;
	private $_tbKeys;
	private $_tbKeysType;

	//private $columnData;


	private $_keys = array();
	private $_ai = array();
	private $_fk = array();
	private $_fkList = array();
	private $_colData = array();
	private $_rename = array();
	private $_hasRename = array();
	

	private $_renameTable = array();
	private $_primaryKeys = array();
	private $_dropPrimaryKeys = false;

	private $_build;
	private $_columns;
	private $_tableExists;

	CONST ARGS = [
		"type" => NULL,
        "length" => NULL,
        "collate" => NULL,
        "attr" => NULL,
        "null" => NULL,
        "default" => NULL,
        "index" => NULL,
        "ai" => NULL,
        "fk" => NULL,
        "after" => NULL,
        "drop" => NULL,
        "rename" => NULL
	];

	const COLLATION = "utf8_general_ci";
	const ATTRIBUTES = ["BINARY", "UNSIGNED", "UNSIGNED ZEROFILL", "on update CURRENT_TIMESTAMP"];
	const INDEXES = ["PRIMARY", "UNIQUE", "INDEX", "FULLTEXT", "SPATIAL"];
	const FULLTEXT_COLUMNS = ["CHAR", "VARCHAR", "TEXT", "TINYTEXT", "MEDIUMTEXT", "LONGTEXT", "JSON"];
	const SPATIAL_COLUMNS = ["POINT", "LINESTRING", "POLYGON", "GEOMETRY", "MULTIPOINT", "MULTILINESTRING", "MULTIPOLYGON", "GEOMETRYCOLLECTION"];


	function __construct(string $table, ?string $prefix = NULL) {
		if(!is_null($this->_prefix)) $this->_prefix = Connect::prep($prefix);
		$this->_charset = "utf8";
		$this->_engine = "InnoDB";
		$this->_rowFormat = "DYNAMIC";
		$this->_tableText = Connect::prep($table);
		$this->_table = "{$this->_prefix}{$this->_tableText}";
	}

	function getTable() {
		return $this->_table;
	}

	/**
	 * Lista all columns set i create/migration
	 * @return self
	 */
	function getColumns() {
		return $this->_columns;
	}

	/**
	 * IF table exists then Alter ELSE Create
	 * @return self
	 */
	function auto() {

		if($this->tableExists($this->_table)) {
			$this->alter($this->_table);
		} else {
			$this->create();
		}

		return $this;
	}

	/**
	 * Create table
	 * @return self
	 */
	function create() {
		$this->_type = "create";
		$this->_sql = "CREATE TABLE `{$this->_table}` ";
		return $this;
	}

	/**
	 * Alter table
	 * @return self
	 */
	function alter() {
		$this->_type = "alter";
		$this->_sql = "ALTER TABLE `{$this->_table}` ";
		return $this;
	}

	/**
	 * Sets drop table
	 * @return self
	 */
	function drop() {
		$this->_type = "drop";
		$this->_sql = "DROP TABLE `{$this->_table}`";
		return $this;
	}
	
	/**
	 * Rename: IF table name is "test" or "test1" then it will be renamed to "test2".
	 * HAS to be the first method to be called
	 * @param  array  $arr 	["test", "test1", "test2"]: will find table name test or test1 and rename it to test2
	 * The array argument will act as a history. If you first renamed table to "test1" it might still be named "test" cross domains. 
	 * Thats is why ever change should persist as a history.
	 * @return slef
	 */
	function rename(array $arr) {
		if(!is_null($this->_sql)) throw new QueryCreateException("The rename method has to be the FIRST method to be called!", 1);
		array_unshift($arr, $this->_table);

		$currentTB = false;
		foreach($arr as $k => $tb) {
			$tb = ($k !== 0 ? $this->_prefix : NULL).$tb;
			if($this->tableExists($tb)) {
				$currentTB = Connect::prep($tb);
				break;
			}
		}

		$newTB = Connect::prep($this->_prefix.end($arr));
		
		if($currentTB) {
			$this->_table = $currentTB;
			if($currentTB !== $newTB) {
				$this->_renameTable[$currentTB] = $newTB;
			}
		} else {
			$this->_table = $newTB;
		}

		return $this;
	}

	/**
	 * Generate Dynamic json column fields
	 * @return string
	 */
	function generated() {
		if(isset($this->_args['generated'])) {
			$value = explode(",", $this->_args['generated']['columns']);
			$colArr = array();
			if(isset($this->_args['generated']['json_columns'])) {
				foreach($value as $col) {
					preg_match('#\{{(.*?)\}}#', $col, $match);

					if(isset($match[1])) {
						$col = trim($match[1]);
						//$colArr[] = "JSON_EXTRACT(var_data, '$.".$col."')";
						//JSON_UNQUOTE is required, if skipp it then data will be added with qutes (") 
						//and all int, floats will become 0 (zero!)
						$colArr[] = "JSON_UNQUOTE(JSON_EXTRACT(var_data, '$.".$col."'))";
						
					} else {
						$colArr[] = "'{$col}'";
					}
					//CONVERT('$.".$col."', UNSIGNED INTEGER)
				}
				
			} else {
				foreach($value as $col) {
					preg_match('#\{{(.*?)\}}#', $col, $match);
					if(isset($match[1])) {
						$col = trim($match[1]);
						$colArr[] = "`{$col}`";
					} else {
						$colArr[] = "'{$col}'";
					}		
				}
			}
			if(count($colArr) > 1) {
				return "GENERATED ALWAYS AS (CONCAT(".implode(",", $colArr)."))";
			}

			return "GENERATED ALWAYS AS (".implode(",", $colArr).")";
		}

		return "";
	}
	

	/**
	 * Add diffrent kinds of attributes to column
	 * @return array
	 */
	private function adding() {
		$arr = array();
		$methodArr = array("type", "generated", "attributes", "collation", "null", "default");
		foreach($methodArr as $method) {
			if($val = $this->{$method}()) $arr[] = $val;
		}
		return $arr;
	}

	/**
	 * Add primary keys
	 * @param  array  $colArr [description]
	 * @return [type]         [description]
	 */
	public function primary(array $colArr): self 
	{
		$colArr = $this->clean($colArr);
		$this->_primaryKeys = array_merge($this->_primaryKeys, $colArr);
		return $this;
	}


	public function clean($value): string|array 
	{
		if(is_array($value)) {
			return array_map([$this, 'clean'], $value);
		} else {
			$value = preg_replace("/[^a-zA-Z0-9_]/", "", $value);
			$value = trim($value);
		}
		return $value;
	}

	/**
	 * Drop the primary key
	 * @return self
	 */
	function primaryDrop() {
		$this->_dropPrimaryKeys = true;
		return $this;
	}

	/**
	 * Alias: Drop the primary key
	 * @return self
	 */
	function dropPrimary() {
		return $this->primaryDrop();
	}

	/**
	 * Prepare column with all the right arguments, indexs, keys and so on
	 * @param  string $col Column name
	 * @param  array  $arr Column arguments
	 * @return self
	 */
	function column(string $col, array $arr) {
		$this->_columns[$col] = $arr;
		return $this;
	}

	private function processColumnData() {

		foreach($this->_columns as $col => $arr) {
			$col = Connect::prep($col);
			$this->_args = array_merge($this::ARGS, $arr);

			$this->_hasRename = NULL;
			if($hasRename = $this->hasRename()) {
				$this->_hasRename[$col] = $hasRename;
				if(!$this->columnExists($this->_table, $col)) {
					foreach($hasRename as $k) {
						if($this->columnExists($this->_table, $k)) {
							$col = Connect::prep($k);
							break;
						}
					}
				}
			}

			//$this->_columns[$col] = $arr;
			$this->_col = $col;
			$this->_add[$col] = "";
			$this->_addArr = $this->adding();

			$attr = implode(" ", $this->_addArr);

			if($index = $this->index()) $this->_keys[$col] = $index;
			if($ai = $this->ai()) $this->_ai[$col] = ["type" => $this->type(), "value" => $ai];
			if($rename = $this->renameColumn()) $this->_rename[$col] = $rename;
			if($fk = $this->fk()) {
				$this->_fkList = $this->fkExists($this->_table, $col);
				$this->_fk[$col] = $fk; 
			}

			if($this->_type === "alter") {
				if($result = $this->columnExists($this->_table, $col)) {
					
					if($drop = $this->dropColumn()) {
						$this->_add[$col] .= "{$drop} `{$col}`";
						if(isset($this->_keys[$col])) unset($this->_keys[$col]);
						if(isset($this->_ai[$col])) unset($this->_ai[$col]);

					} else {
						$this->_colData[$col] = $result->fetch_object();
						$this->_add[$col] .= "MODIFY `{$col}` {$attr}";
					}

				} else {
					if($drop = $this->dropColumn()) {
						if(isset($this->_keys[$col])) unset($this->_keys[$col]);
						if(isset($this->_ai[$col])) unset($this->_ai[$col]);

					} else {
						if(is_null($this->hasRename())) {
							$this->_add[$col] .= "ADD COLUMN `{$col}` {$attr}";
							if(!is_null($this->_prev)) {
								$this->_add[$col] .= " AFTER `".$this->after()."`";
							}
						}
					}	
				}

			} else {
				$this->_add[$col] .= "`{$col}` {$attr}";	
			}

			$this->_add[$col] = trim($this->_add[$col]);
			$this->_prev = $col;
		}
	}

	/**
	 * Sets Primary key
	 */
	private function _setPrimary() {
		if(count($this->_primaryKeys) > 0) {

			if($keys = $this->_tbKeys()) $this->_add[] = "DROP PRIMARY KEY";


			$this->_primaryKeys = array_unique($this->_primaryKeys);
			$imp = implode(",", $this->_mysqlCleanArr($this->_primaryKeys));

			if($this->_type === "create") {
				$this->_add[] = "PRIMARY KEY({$imp})";
			} else {
				$this->_add[] = "ADD PRIMARY KEY({$imp})";
			}
		}
	}
	
	/**
	 * Build SQL code
	 * @return string
	 */
	function build() {

		$this->processColumnData();
		
		if(is_array($this->_add)) {
			$this->_add = array_filter($this->_add);
		}

		if(is_null($this->_build)) {

			// Might add to primary
			$keyStr = $this->_buildKeys();

			$this->_setPrimary();



			if($this->_type === "drop") {
				$this->_build = "{$this->_sql};";

			} else {
				$this->_build = "START TRANSACTION;\n\n";
				$this->_build .= "SET FOREIGN_KEY_CHECKS=0;\n";
				$this->_build .= $this->_sql."\n";
				switch($this->_type) {
					case "create":
						$this->_build .= "(".implode(",\n ", $this->_add).") ENGINE={$this->_engine} DEFAULT CHARSET={$this->_charset} ROW_FORMAT={$this->_rowFormat};";
					break;
					case "alter":
						$this->_build .= "".implode(",\n ", $this->_add).";";
					break;
				}

				$this->_build .= "\n\n";
				if($keyStr) $this->_build .= "{$keyStr}\n\n";
				if($aiStr = $this->_buildAI()) $this->_build .= "{$aiStr}\n\n";
				if($renameStr = $this->_buildRename()) $this->_build .= "{$renameStr}\n\n";
				if($fkStr = $this->_buildFK()) $this->_build .= "{$fkStr}\n\n";

				if(count($this->_renameTable) > 0) {
					$new = reset($this->_renameTable);
					$current = key($this->_renameTable);
					$this->_build .= "RENAME TABLE `{$current}` TO `{$new}`;\n\n";
				}

				$this->_build .= "SET FOREIGN_KEY_CHECKS=1;\n\n";
				$this->_build .= "COMMIT;";

			}
		}

		return $this->_build;
	}

	/**
	 * Execute
	 * @return array errors.
	 */
	function execute() {
		$sql = $this->build();
		$error = Connect::multiQuery($sql, $mysqli);
		return $error;
	}

	function _mysqlCleanArr(array $arr) {
		$new = array();
		foreach($arr as $a) $new[] = Connect::prep($a);
		return $new;
	}

	/**
	 * Index lookup
	 * @return int|effected rows
	 */
	private function _tbKeys(): array {
		if(is_null($this->_tbKeys)) {
			$this->_tbKeysType = $this->_tbKeys = array();
			if($this->tableExists($this->_table)) {
				$result = Connect::query("SHOW INDEXES FROM {$this->_table}");
				if($result && $result->num_rows > 0) {
					while ($row = $result->fetch_object()) {
						$type = ($row->Index_type === "FULLTEXT" || $row->Index_type === "SPATIAL") ? $row->Index_type : "INDEX";
						$type = ($row->Key_name === "PRIMARY") ? $row->Key_name : (((int)$row->Non_unique === 0) ? "UNIQUE" : $type);
						$this->_tbKeys[$row->Column_name][] = $row->Key_name;
						$this->_tbKeysType[$row->Column_name][] = $type;


						
					}
				}
			}
		}

		return $this->_tbKeys;
	}

	function tbKeysType() {
		if(is_null($this->_tbKeysType)) $this->_tbKeys();
		return $this->_tbKeysType;
	}
	
	/**
	 * Drop column
	 * @return string
	 */
	function dropColumn() {
		return (!is_null($this->_args['drop']) && $this->_args['drop'] === true) ? "DROP COLUMN" : NULL;
	}

	/**
	 * Add column after
	 * @return string
	 */
	function after() {
		return (!is_null($this->_args['after'])) ? $this->_args['after'] : $this->_prev;
	}

	/**
	 * Will rename column
	 * @return string
	 */
	function renameColumn() {
		if($rename = $this->hasRename()) {
			$rename = end($rename);
			if(!$this->columnExists($this->_table, $rename)) {
				return $rename;
			}
		}
		return NULL;
	}

	function hasRename() {
		return (!is_null($this->_args['rename'])) ? $this->_args['rename'] : NULL;
	}

	/**
	 * Sets AI
	 * @return string
	 */
	function ai() {
		$ai = (!is_null($this->_args['ai']) && $this->_args['ai'] !== false) ? "AUTO_INCREMENT" : NULL;
		if($ai) {
			$i = (int)$this->_args['ai'];
			if($i > 1) $ai .= ", AUTO_INCREMENT={$i}";
		}

		return $ai;
	}

	//Foreign key
	function fk() {
		return (is_array($this->_args['fk'])) ? $this->_args['fk'] : NULL;
	}

	/**
	 * Sets column type
	 * @return string
	 */
	function type() {
		return strtoupper($this->_args['type']).(!is_null($this->_args['length']) ? "({$this->_args['length']})" : NULL);
	}

	/**
	 * Sets character and text collation
	 * @return [type] [description]
	 */
	function collation() {
		if($this->_args['collate']) {
			if($this->_args['collate'] === true) $this->_args['collate'] = $this::COLLATION;
			return "CHARACTER SET ".Connect::prep($this->_charset)." COLLATE ".Connect::prep($this->_args['collate'])."";
		}
		return NULL;
	}

	/**
	 * Sets NULL
	 * @return string
	 */
	function null() {
		return ($this->_args['null']) ? NULL : "NOT NULL";
	}

	/**
	 * Sets default value
	 * @return string
	 */
	function default() {
		return (!is_null($this->_args['default']) && $this->_args['default'] !== false) ? "DEFAULT '".Connect::prep($this->_args['default'])."'" : NULL;
	}

	/**
	 * Sets Mysql Attributes
	 * @return string
	 */
	function attributes() {
		if(!is_null($this->_args['attr'])) {
			$this->_args['attr'] = strtoupper($this->_args['attr']);
			if(!in_array($this->_args['attr'], $this::ATTRIBUTES)) {
				throw new QueryCreateException("The attribute \"{$this->_args['attr']}\" does not exist", 1);
			}
			return Connect::prep($this->_args['attr']);
		}

		return NULL;
	}

	/**
	 * Sets index
	 * @return string
	 */
	function index() {
		if(!is_null($this->_args['index'])) {
			if($this->_args['index'] !== 0) {
				$this->_args['index'] = strtoupper($this->_args['index']);
				$this->_args['type'] = strtoupper($this->_args['type']);
				if(!in_array($this->_args['index'], $this::INDEXES)) {
					throw new QueryCreateException("The attribute \"{$this->_args['index']}\" does not exist", 1);
				}

				if($this->_args['index'] === "FULLTEXT" && !in_array($this->_args['type'], static::FULLTEXT_COLUMNS)) {
					throw new QueryCreateException("You can ony have \"{$this->_args['index']}\" index on column types (".implode(", ", static::FULLTEXT_COLUMNS)."), you have \"{$this->_args['type']}\".", 1);
				}

				if($this->_args['index'] === "SPATIAL" && !in_array($this->_args['type'], static::SPATIAL_COLUMNS)) {
					throw new QueryCreateException("You can ony have \"{$this->_args['index']}\" index on column types (".implode(", ", static::FULLTEXT_COLUMNS)."), you have \"{$this->_args['type']}\".", 1);
				}
				return Connect::prep($this->_args['index']);
			}
		}
		return NULL;
	}

	/**
	 * Build the foreign key
	 * The foreign key name must be unique across the database, becouse of the name reflect make it unique
	 * @return string
	 */
	private function _buildFK() {
		$sql = "";
		if(count($this->_fk) > 0) {
			
			foreach($this->_fk as $col =>  $array) {
				foreach($array as $arr) {

					$arr['table'] = (isset($arr['table']) && (bool)$arr['table']) ? $arr['table'] : "";
					$arr['update'] = $this->_fkSwitch($arr['update']);
					$arr['delete'] = $this->_fkSwitch($arr['delete']);

					// This is Foreign key constraint name found in query search.
					$fkNameA = "fk_{$this->_prefix}{$arr['table']}_{$this->_tableText}_{$arr['column']}";
					$fkRow = ($this->_fkList[$fkNameA] ?? NULL);
					$fkKey = ($fkRow->CONSTRAINT_NAME ?? NULL);

					if(isset($arr['drop']) && $arr['drop'] === true) {
						if(!is_null($fkKey)) $sql .= "ALTER TABLE `{$this->_table}` DROP FOREIGN KEY `{$fkKey}`;";

					} else {
						if(!is_null($fkKey)) {
							$sql .= "ALTER TABLE `{$this->_table}` DROP FOREIGN KEY `{$fkKey}`;\n\n";
							unset($this->_fkList[$fkKey]);
						}

						$sql .= "ALTER TABLE `{$this->_table}`\n ";
						$sql .= "ADD CONSTRAINT `{$fkNameA}`\n ";
						$sql .= "FOREIGN KEY (`{$col}`)\n ";
						$sql .= "REFERENCES `{$this->_prefix}{$arr['table']}`(`{$arr['column']}`)\n ";
						$sql .= "ON DELETE {$arr['delete']}\n ";
						$sql .= "ON UPDATE {$arr['update']};\n ";

						if(count($this->_fkList) > 0) {
							foreach($this->_fkList as $key => $row) {
								$sql .= "\nALTER TABLE `{$this->_table}` DROP FOREIGN KEY `{$key}`;\n\n";
							}
						}
					}
				}
			}
		}
		
		return $sql;
	}

	private function _fkSwitch($val) {
		$val = strtoupper($val);
		switch($val) {
			case 'CASCADE':
				return $val;
			break;
			case 'SET_NULL':
				return "SET NULL";
			break;
			case 'NO_ACTION':
				return "NO ACTION";
			break;
			case 'RESTRICT':
				return $val;
			break;
		}

		return NULL;
	}

	/**
	 * Build key sql output
	 * @return string
	 */
	private function _buildKeys(): string 
	{

		$sql = "";
		$tbKeys = $this->_tbKeys();
		$prepareDrop = $this->tbKeysType();

		if(count($this->_keys) > 0) {
			$sqlKeyArr = array();
			foreach($this->_keys as $col => $key) {
				$col = Connect::prep($col);
				$key = strtoupper(Connect::prep($key));

				// Prepare DROP
				if(isset($prepareDrop[$col]) && ($index = array_search($key, $prepareDrop[$col])) !== false) {
					unset($prepareDrop[$col][$index]);
				}

				// Prepare ADD
				if(empty($this->_colData[$col]) || !(bool)$this->_colData[$col]->Key) switch($key) {
					case 'INDEX':
						$sqlKeyArr[] = "ADD INDEX `{$col}` (`{$col}`)";

					break;
					case 'PRIMARY':
						$this->primary([$col]);
					break;
					default:
						$sqlKeyArr[] = "ADD {$key} INDEX `{$col}` (`{$col}`)";
					break;
				}		
			}

			// DROP Possible keys
			if(count($prepareDrop) > 0) {
				foreach($prepareDrop as $a => $arr) {
					if(($index = array_search("PRIMARY", $arr)) !== false) unset($arr[$index]);
					if(count($arr) > 0) foreach ($tbKeys[$a] as $b => $col) {
						$sqlKeyArr[] = "DROP INDEX `{$col}`";
					}
				}
			}

			// Build alter tabel keys 
			if(count($sqlKeyArr) > 0) {
				$sql = "ALTER TABLE `{$this->_table}`\n ".implode(",\n", $sqlKeyArr).";";
			}
		}

		return $sql;
	}


	static function _createDatabase($host, $user, $pass, $database) {

		$conn = new \mysqli($host, $user, $pass);
		if($conn->connect_error) die("Connection failed: " . $conn->connect_error);
		
 		if($result = $conn->query("CREATE DATABASE IF NOT EXISTS `{$database}`")) {
			return $result;	
		} else {
			throw new \Exception($conn->error, 1);
		}

		$conn->close();

	}

	private function _buildAI() {
		$sql = "";
		if(count($this->_ai) > 0) {
			foreach($this->_ai as $col => $a) {
				//if(empty($this->_colData[$col]) || strpos($this->_colData[$col]->Extra, "auto_increment") === false) {
					$sql .= "ALTER TABLE `{$this->_table}` MODIFY `{$col}` {$a['type']} UNSIGNED NOT NULL {$a['value']};";
				//}	
			}
		}
		return $sql;
	}

	private function _buildRename() {
		$sql = "";
		if(count($this->_rename) > 0) {
			foreach($this->_rename as $col => $newCol) {
				$sql .= "ALTER TABLE `{$this->_table}` CHANGE `{$col}` `{$newCol}` ".$this->type().";\n";
			}
		}
		return $sql;
	}

	function fkExists(string $table, string $col) {
		$table = Connect::prep($table);
		$col = Connect::prep($col);
		$dbName = Connect::inst()->getDBName();
		$result = Connect::query("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'");

		$arr = array();
		if($result && $result->num_rows > 0) while($row = $result->fetch_object()) {
			$arr[$row->CONSTRAINT_NAME] = $row;
		}
 		return $arr;

	}

	function tableExists(string $table = NULL) {
		if(is_null($this->_tableExists)) {
			$this->_tableExists = false;
			if(is_null($table)) $table = $this->_table;
			$table = Connect::prep($table);
	 		$result = Connect::query("SHOW TABLES LIKE '{$table}'");
	 		if($result && $result->num_rows > 0) {
	 			$this->_tableExists = $result;
	 		}
 		}
 		return $this->_tableExists;
	}

	function columnExists(string $table, string $col) {
		if($this->tableExists($table)) {
			$table = Connect::prep($table);
			$col = Connect::prep($col);
	 		$result = Connect::query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
	 		if($result && $result->num_rows > 0) {
	 			return $result;
	 		}
 		}
 		return false;
	}

}
