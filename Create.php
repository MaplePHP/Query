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

class Create
{
    private $sql;
    private $add;
    private $addArr = array();
    private $prefix;
    private $type;
    private $args;
    private $table;
    private $tableText;
    private $col;
    private $prev;
    private $charset;
    private $engine;
    private $rowFormat;
    private $tbKeys;
    private $tbKeysType;
    //private $columnData;
    private $keys = array();
    private $ai = array();
    private $fk = array();
    private $fkList = array();
    private $colData = array();
    private $rename = array();
    private $hasRename = array();
    private $renameTable = array();
    private $primaryKeys = array();
    private $dropPrimaryKeys = false;

    private $build;
    private $columns;
    private $tableExists;

    public const ARGS = [
        "type" => null,
        "length" => null,
        "collate" => null,
        "attr" => null,
        "null" => null,
        "default" => null,
        "index" => null,
        "ai" => null,
        "fk" => null,
        "after" => null,
        "drop" => null,
        "rename" => null
    ];

    public const COLLATION = "utf8_general_ci";
    public const ATTRIBUTES = ["BINARY", "UNSIGNED", "UNSIGNED ZEROFILL", "on update CURRENT_TIMESTAMP"];
    public const INDEXES = ["PRIMARY", "UNIQUE", "INDEX", "FULLTEXT", "SPATIAL"];
    public const FULLTEXT_COLUMNS = ["CHAR", "VARCHAR", "TEXT", "TINYTEXT", "MEDIUMTEXT", "LONGTEXT", "JSON"];
    public const SPATIAL_COLUMNS = ["POINT", "LINESTRING", "POLYGON", "GEOMETRY", "MULTIPOINT",
    "MULTILINESTRING", "MULTIPOLYGON", "GEOMETRYCOLLECTION"];


    public function __construct(string $table, ?string $prefix = null)
    {
        if (!is_null($prefix)) {
            $this->prefix = Connect::prep($prefix);
        }
        $this->charset = "utf8";
        $this->engine = "InnoDB";
        $this->rowFormat = "DYNAMIC";
        $this->tableText = Connect::prep($table);
        $this->table = "{$this->prefix}{$this->tableText}";
    }

    public function getTable()
    {
        return $this->table;
    }

    /**
     * Lista all columns set i create/migration
     * @return self
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * IF table exists then Alter ELSE Create
     * @return self
     */
    public function auto()
    {

        if ($this->tableExists($this->table)) {
            $this->alter();
        } else {
            $this->create();
        }

        return $this;
    }

    /**
     * Create table
     * @return self
     */
    public function create()
    {
        $this->type = "create";
        $this->sql = "CREATE TABLE `{$this->table}` ";
        return $this;
    }

    /**
     * Alter table
     * @return self
     */
    public function alter()
    {
        $this->type = "alter";
        $this->sql = "ALTER TABLE `{$this->table}` ";
        return $this;
    }

    /**
     * Sets drop table
     * @return self
     */
    public function drop()
    {
        $this->type = "drop";
        $this->sql = "DROP TABLE `{$this->table}`";
        return $this;
    }

    /**
     * Rename: IF table name is "test" or "test1" then it will be renamed to "test2".
     * HAS to be the first method to be called
     * @param  array  $arr  ["test", "test1", "test2"]: will find table name test
     * or test1 and rename it to test2
     * The array argument will act as a history. If you first renamed table to
     * "test1" it might still be named "test" cross domains.
     * Thats is why ever change should persist as a history.
     * @return self
     */
    public function rename(array $arr): self
    {
        if (!is_null($this->sql)) {
            throw new QueryCreateException("The rename method has to be the FIRST method to be called!", 1);
        }
        array_unshift($arr, $this->table);

        $currentTB = false;
        foreach ($arr as $k => $tb) {
            $tb = ($k !== 0 ? $this->prefix : null) . $tb;
            if ($this->tableExists($tb)) {
                $currentTB = Connect::prep($tb);
                break;
            }
        }

        $newTB = Connect::prep($this->prefix . end($arr));

        if ($currentTB) {
            $this->table = $currentTB;
            if ($currentTB !== $newTB) {
                $this->renameTable[$currentTB] = $newTB;
            }
        } else {
            $this->table = $newTB;
        }

        return $this;
    }

    /**
     * Generate Dynamic json column fields
     * @return string
     */
    public function generated()
    {
        if (isset($this->args['generated'])) {
            $value = explode(",", $this->args['generated']['columns']);
            $colArr = array();
            if (isset($this->args['generated']['json_columns'])) {
                foreach ($value as $col) {
                    preg_match('#\{{(.*?)\}}#', $col, $match);

                    if (isset($match[1])) {
                        $col = trim($match[1]);
                        //$colArr[] = "JSON_EXTRACT(var_data, '$.".$col."')";
                        //JSON_UNQUOTE is required, if skipp it then data will be added with qutes (")
                        //and all int, floats will become 0 (zero!)
                        $colArr[] = "JSON_UNQUOTE(JSON_EXTRACT(var_data, '$." . $col . "'))";
                    } else {
                        $colArr[] = "'{$col}'";
                    }
                    //CONVERT('$.".$col."', UNSIGNED INTEGER)
                }
            } else {
                foreach ($value as $col) {
                    preg_match('#\{{(.*?)\}}#', $col, $match);
                    if (isset($match[1])) {
                        $col = trim($match[1]);
                        $colArr[] = "`{$col}`";
                    } else {
                        $colArr[] = "'{$col}'";
                    }
                }
            }
            if (count($colArr) > 1) {
                return "GENERATED ALWAYS AS (CONCAT(" . implode(",", $colArr) . "))";
            }

            return "GENERATED ALWAYS AS (" . implode(",", $colArr) . ")";
        }

        return "";
    }


    /**
     * Add diffrent kinds of attributes to column
     * @return array
     */
    private function adding()
    {
        $arr = array();
        $methodArr = array("type", "generated", "attributes", "collation", "null", "default");
        foreach ($methodArr as $method) {
            if ($val = $this->{$method}()) {
                $arr[] = $val;
            }
        }
        return $arr;
    }

    /**
     * Add primary keys
     * @param  array  $colArr
     * @return self
     */
    public function primary(array $colArr): self
    {
        $colArr = $this->clean($colArr);
        if (!is_array($colArr)) {
            throw new QueryCreateException("Argumnet could not be converted as an array!", 1);
        }
        $this->primaryKeys = array_merge($this->primaryKeys, $colArr);
        return $this;
    }


    public function clean($value): string|array
    {
        if (is_array($value)) {
            return array_map([$this, 'clean'], $value);
        } else {
            if (!is_string($value)) {
                throw new QueryCreateException("Argumnet value is expected to be either array or string!", 1);
            }
            $value = preg_replace("/[^a-zA-Z0-9_]/", "", $value);
            $value = trim($value);
        }
        return $value;
    }

    /**
     * Drop the primary key
     * @return self
     */
    public function primaryDrop()
    {
        $this->dropPrimaryKeys = true;
        return $this;
    }

    /**
     * Alias: Drop the primary key
     * @return self
     */
    public function dropPrimary()
    {
        return $this->primaryDrop();
    }

    /**
     * Prepare column with all the right arguments, indexs, keys and so on
     * @param  string $col Column name
     * @param  array  $arr Column arguments
     * @return self
     */
    public function column(string $col, array $arr)
    {
        $this->columns[$col] = $arr;
        return $this;
    }

    private function processColumnData()
    {

        foreach ($this->columns as $col => $arr) {
            $col = Connect::prep($col);
            $this->args = array_merge($this::ARGS, $arr);

            $this->hasRename = null;
            if ($hasRename = $this->hasRename()) {
                $this->hasRename[$col] = $hasRename;
                if (!$this->columnExists($this->table, $col)) {
                    foreach ($hasRename as $k) {
                        if ($this->columnExists($this->table, $k)) {
                            $col = Connect::prep($k);
                            break;
                        }
                    }
                }
            }

            //$this->columns[$col] = $arr;
            $this->col = $col;
            $this->add[$col] = "";
            $this->addArr = $this->adding();

            $attr = implode(" ", $this->addArr);

            if ($index = $this->index()) {
                $this->keys[$col] = $index;
            }
            if ($ai = $this->ai()) {
                $this->ai[$col] = ["type" => $this->type(), "value" => $ai];
            }
            if ($rename = $this->renameColumn()) {
                $this->rename[$col] = $rename;
            }
            if ($fk = $this->fk()) {
                $this->fkList = $this->fkExists($this->table, $col);
                $this->fk[$col] = $fk;
            }

            if ($this->type === "alter") {
                if ($result = $this->columnExists($this->table, $col)) {
                    if ($drop = $this->dropColumn()) {
                        $this->add[$col] .= "{$drop} `{$col}`";
                        if (isset($this->keys[$col])) {
                            unset($this->keys[$col]);
                        }
                        if (isset($this->ai[$col])) {
                            unset($this->ai[$col]);
                        }
                    } else {
                        $this->colData[$col] = $result->fetch_object();
                        $this->add[$col] .= "MODIFY `{$col}` {$attr}";
                    }
                } else {
                    //if ($drop = $this->dropColumn()) {
                    if ($this->dropColumn()) {
                        if (isset($this->keys[$col])) {
                            unset($this->keys[$col]);
                        }
                        if (isset($this->ai[$col])) {
                            unset($this->ai[$col]);
                        }
                    } else {
                        if (is_null($this->hasRename())) {
                            $this->add[$col] .= "ADD COLUMN `{$col}` {$attr}";
                            if (!is_null($this->prev)) {
                                $this->add[$col] .= " AFTER `" . $this->after() . "`";
                            }
                        }
                    }
                }
            } else {
                $this->add[$col] .= "`{$col}` {$attr}";
            }

            $this->add[$col] = trim($this->add[$col]);
            $this->prev = $col;
        }
    }

    /**
     * Sets Primary key
     */
    private function setPrimary()
    {
        if (count($this->primaryKeys) > 0) {
            //if ($keys = $this->tbKeys()) {
            if ($this->tbKeys()) {
                $this->add[] = "DROP PRIMARY KEY";
            }


            $this->primaryKeys = array_unique($this->primaryKeys);
            $imp = implode(",", $this->mysqlCleanArr($this->primaryKeys));

            if ($this->type === "create") {
                $this->add[] = "PRIMARY KEY({$imp})";
            } else {
                $this->add[] = "ADD PRIMARY KEY({$imp})";
            }
        }
    }

    /**
     * Build SQL code
     * @return string
     */
    public function build()
    {

        $this->processColumnData();

        if (is_array($this->add)) {
            $this->add = array_filter($this->add);
        }

        if (is_null($this->build)) {
            // Might add to primary
            $keyStr = $this->buildKeys();

            $this->setPrimary();



            if ($this->type === "drop") {
                $this->build = "{$this->sql};";
            } else {
                $this->build = "START TRANSACTION;\n\n";
                $this->build .= "SET FOREIGN_KEY_CHECKS=0;\n";
                $this->build .= $this->sql . "\n";
                switch ($this->type) {
                    case "create":
                        $this->build .= "(" . implode(",\n ", $this->add) . ") ENGINE={$this->engine} DEFAULT " .
                        "CHARSET={$this->charset} ROW_FORMAT={$this->rowFormat};";
                        break;
                    case "alter":
                        $this->build .= "" . implode(",\n ", $this->add) . ";";
                        break;
                }

                $this->build .= "\n\n";
                if ($keyStr) {
                    $this->build .= "{$keyStr}\n\n";
                }
                if ($aiStr = $this->buildAI()) {
                    $this->build .= "{$aiStr}\n\n";
                }
                if ($renameStr = $this->buildRename()) {
                    $this->build .= "{$renameStr}\n\n";
                }
                if ($fkStr = $this->buildFK()) {
                    $this->build .= "{$fkStr}\n\n";
                }

                if (count($this->renameTable) > 0) {
                    $new = reset($this->renameTable);
                    $current = key($this->renameTable);
                    $this->build .= "RENAME TABLE `{$current}` TO `{$new}`;\n\n";
                }

                $this->build .= "SET FOREIGN_KEY_CHECKS=1;\n\n";
                $this->build .= "COMMIT;";
            }
        }

        return $this->build;
    }

    /**
     * Execute
     * @return array errors.
     */
    public function execute()
    {
        $sql = $this->build();
        $error = Connect::multiQuery($sql, $mysqli);
        return $error;
    }

    public function mysqlCleanArr(array $arr)
    {
        $new = array();
        foreach ($arr as $a) {
            $new[] = Connect::prep($a);
        }
        return $new;
    }

    /**
     * Index lookup
     * @return array
     */
    private function tbKeys(): array
    {
        if (is_null($this->tbKeys)) {
            $this->tbKeysType = $this->tbKeys = array();
            if ($this->tableExists($this->table)) {
                $result = Connect::query("SHOW INDEXES FROM {$this->table}");
                if (is_object($result) && $result->num_rows > 0) {
                    while ($row = $result->fetch_object()) {
                        $type = ($row->Index_type === "FULLTEXT" ||
                            $row->Index_type === "SPATIAL") ? $row->Index_type : "INDEX";
                        $type = ($row->Key_name === "PRIMARY") ? $row->Key_name :
                            (((int)$row->Non_unique === 0) ? "UNIQUE" : $type);
                        $this->tbKeys[$row->Column_name][] = $row->Key_name;
                        $this->tbKeysType[$row->Column_name][] = $type;
                    }
                }
            }
        }

        return $this->tbKeys;
    }

    public function tbKeysType()
    {
        if (is_null($this->tbKeysType)) {
            $this->tbKeys();
        }
        return $this->tbKeysType;
    }

    /**
     * Drop column
     * @return string|null
     */
    public function dropColumn(): ?string
    {
        return (!is_null($this->args['drop']) && $this->args['drop'] === true) ? "DROP COLUMN" : null;
    }

    /**
     * Add column after
     * @return string
     */
    public function after()
    {
        return (!is_null($this->args['after'])) ? $this->args['after'] : $this->prev;
    }

    /**
     * Will rename column
     * @return string|null
     */
    public function renameColumn(): ?string
    {
        if ($rename = $this->hasRename()) {
            $rename = end($rename);
            if (!$this->columnExists($this->table, $rename)) {
                return $rename;
            }
        }
        return null;
    }

    public function hasRename()
    {
        return (!is_null($this->args['rename'])) ? $this->args['rename'] : null;
    }

    /**
     * Sets AI
     * @return string|null
     */
    public function ai(): ?string
    {
        $ai = (!is_null($this->args['ai']) && $this->args['ai'] !== false) ? "AUTO_INCREMENT" : null;
        if ($ai) {
            $i = (int)$this->args['ai'];
            if ($i > 1) {
                $ai .= ", AUTO_INCREMENT={$i}";
            }
        }

        return $ai;
    }

    //Foreign key
    public function fk()
    {
        return (is_array($this->args['fk'])) ? $this->args['fk'] : null;
    }

    /**
     * Sets column type
     * @return string
     */
    public function type()
    {
        return strtoupper($this->args['type']) . (!is_null($this->args['length']) ? "({$this->args['length']})" : null);
    }

    /**
     * Sets character and text collation
     * @return string|null
     */
    public function collation(): ?string
    {
        if ($this->args['collate']) {
            if ($this->args['collate'] === true) {
                $this->args['collate'] = $this::COLLATION;
            }
            return "CHARACTER SET " . Connect::prep($this->charset) . " COLLATE " . Connect::prep($this->args['collate']) . "";
        }
        return null;
    }

    /**
     * Sets NULL
     * @return string|null
     */
    public function null(): ?string
    {
        return ($this->args['null']) ? null : "NOT NULL";
    }

    /**
     * Sets default value
     * @return string|null
     */
    public function default(): ?string
    {
        return (!is_null($this->args['default']) && $this->args['default'] !== false) ? "DEFAULT '" .
        Connect::prep($this->args['default']) . "'" : null;
    }

    /**
     * Sets Mysql Attributes
     * @return string|null
     */
    public function attributes(): ?string
    {
        if (!is_null($this->args['attr'])) {
            $this->args['attr'] = strtoupper($this->args['attr']);
            if (!in_array($this->args['attr'], $this::ATTRIBUTES)) {
                throw new QueryCreateException("The attribute \"{$this->args['attr']}\" does not exist", 1);
            }
            return Connect::prep($this->args['attr']);
        }

        return null;
    }

    /**
     * Sets index
     * @return string|null
     */
    public function index(): ?string
    {
        if (!is_null($this->args['index'])) {
            if ($this->args['index'] !== 0) {
                $this->args['index'] = strtoupper($this->args['index']);
                $this->args['type'] = strtoupper($this->args['type']);
                if (!in_array($this->args['index'], $this::INDEXES)) {
                    throw new QueryCreateException("The attribute \"{$this->args['index']}\" does not exist", 1);
                }

                if ($this->args['index'] === "FULLTEXT" && !in_array($this->args['type'], static::FULLTEXT_COLUMNS)) {
                    throw new QueryCreateException("You can ony have \"{$this->args['index']}\" index on column " .
                        "types (" . implode(", ", static::FULLTEXT_COLUMNS) . "), you have \"{$this->args['type']}\".", 1);
                }

                if ($this->args['index'] === "SPATIAL" && !in_array($this->args['type'], static::SPATIAL_COLUMNS)) {
                    throw new QueryCreateException("You can ony have \"{$this->args['index']}\" index on column types " .
                        "(" . implode(", ", static::FULLTEXT_COLUMNS) . "), you have \"{$this->args['type']}\".", 1);
                }
                return Connect::prep($this->args['index']);
            }
        }
        return null;
    }

    /**
     * Build the foreign key
     * The foreign key name must be unique across the database, becouse of the name reflect make it unique
     * @return string
     */
    private function buildFK()
    {
        $sql = "";
        if (count($this->fk) > 0) {
            foreach ($this->fk as $col => $array) {
                foreach ($array as $arr) {
                    $arr['table'] = (isset($arr['table']) && (bool)$arr['table']) ? $arr['table'] : "";
                    $arr['update'] = $this->fkSwitch($arr['update']);
                    $arr['delete'] = $this->fkSwitch($arr['delete']);

                    // This is Foreign key constraint name found in query search.
                    $fkNameA = "fk_{$this->prefix}{$arr['table']}_{$this->tableText}_{$arr['column']}";
                    $fkRow = ($this->fkList[$fkNameA] ?? null);
                    $fkKey = ($fkRow->CONSTRAINT_NAME ?? null);

                    if (isset($arr['drop']) && $arr['drop'] === true) {
                        if (!is_null($fkKey)) {
                            $sql .= "ALTER TABLE `{$this->table}` DROP FOREIGN KEY `{$fkKey}`;";
                        }
                    } else {
                        if (!is_null($fkKey)) {
                            $sql .= "ALTER TABLE `{$this->table}` DROP FOREIGN KEY `{$fkKey}`;\n\n";
                            unset($this->fkList[$fkKey]);
                        }

                        $sql .= "ALTER TABLE `{$this->table}`\n ";
                        $sql .= "ADD CONSTRAINT `{$fkNameA}`\n ";
                        $sql .= "FOREIGN KEY (`{$col}`)\n ";
                        $sql .= "REFERENCES `{$this->prefix}{$arr['table']}`(`{$arr['column']}`)\n ";
                        $sql .= "ON DELETE {$arr['delete']}\n ";
                        $sql .= "ON UPDATE {$arr['update']};\n ";

                        if (count($this->fkList) > 0) {
                            foreach ($this->fkList as $key => $_notUsedRow) {
                                $sql .= "\nALTER TABLE `{$this->table}` DROP FOREIGN KEY `{$key}`;\n\n";
                            }
                        }
                    }
                }
            }
        }

        return $sql;
    }

    private function fkSwitch($val)
    {
        $val = strtoupper($val);
        switch ($val) {
            case 'CASCADE':
                return $val;
            case 'SET_NULL':
                return $val;
            case 'NO_ACTION':
                return $val;
            case 'RESTRICT':
                return $val;
            default:
                return null;
        }
    }

    /**
     * Build key sql output
     * @return string
     */
    private function buildKeys(): string
    {

        $sql = "";
        $tbKeys = $this->tbKeys();
        $prepareDrop = $this->tbKeysType();

        if (count($this->keys) > 0) {
            $sqlKeyArr = array();
            foreach ($this->keys as $col => $key) {
                $col = Connect::prep($col);
                $key = strtoupper(Connect::prep($key));

                // Prepare DROP
                if (isset($prepareDrop[$col]) && ($index = array_search($key, $prepareDrop[$col])) !== false) {
                    unset($prepareDrop[$col][$index]);
                }

                // Prepare ADD
                if (empty($this->colData[$col]) || !(bool)$this->colData[$col]->Key) {
                    switch ($key) {
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
            }

            // DROP Possible keys
            if (count($prepareDrop) > 0) {
                foreach ($prepareDrop as $a => $arr) {
                    if (($index = array_search("PRIMARY", $arr)) !== false) {
                        unset($arr[$index]);
                    }
                    if (count($arr) > 0) {
                        foreach ($tbKeys[$a] as $col) {
                            $sqlKeyArr[] = "DROP INDEX `{$col}`";
                        }
                    }
                }
            }

            // Build alter tabel keys
            if (count($sqlKeyArr) > 0) {
                $sql = "ALTER TABLE `{$this->table}`\n " . implode(",\n", $sqlKeyArr) . ";";
            }
        }

        return $sql;
    }


    public static function createDatabase($host, $user, $pass, $database)
    {

        $conn = new \mysqli($host, $user, $pass);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        if ($result = $conn->query("CREATE DATABASE IF NOT EXISTS `{$database}`")) {
            return $result;
        } else {
            throw new \Exception($conn->error, 1);
        }

        //$conn->close();
    }

    private function buildAI()
    {
        $sql = "";
        if (count($this->ai) > 0) {
            foreach ($this->ai as $col => $a) {
                //if(empty($this->colData[$col]) || strpos($this->colData[$col]->Extra, "auto_increment") === false) {
                $sql .= "ALTER TABLE `{$this->table}` MODIFY `{$col}` {$a['type']} UNSIGNED NOT NULL {$a['value']};";
                //}
            }
        }
        return $sql;
    }

    private function buildRename()
    {
        $sql = "";
        if (count($this->rename) > 0) {
            foreach ($this->rename as $col => $newCol) {
                $sql .= "ALTER TABLE `{$this->table}` CHANGE `{$col}` `{$newCol}` " . $this->type() . ";\n";
            }
        }
        return $sql;
    }

    public function fkExists(string $table, string $col)
    {
        $table = Connect::prep($table);
        $col = Connect::prep($col);
        $dbName = Connect::inst()->getDBName();
        $result = Connect::query("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME FROM " .
            "INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '{$dbName}' AND " .
            "TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'");

        $arr = array();
        if (is_object($result) && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $arr[$row->CONSTRAINT_NAME] = $row;
            }
        }
        return $arr;
    }

    public function tableExists(string $table = null)
    {
        if (is_null($this->tableExists)) {
            $this->tableExists = false;
            if (is_null($table)) {
                $table = $this->table;
            }
            $table = Connect::prep($table);
            $result = Connect::query("SHOW TABLES LIKE '{$table}'");
            if (is_object($result) && $result->num_rows > 0) {
                $this->tableExists = $result;
            }
        }
        return $this->tableExists;
    }

    public function columnExists(string $table, string $col)
    {
        if ($this->tableExists($table)) {
            $table = Connect::prep($table);
            $col = Connect::prep($col);
            $result = Connect::query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
            if (is_object($result) && $result->num_rows > 0) {
                return $result;
            }
        }
        return false;
    }
}
