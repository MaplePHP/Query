<?php
/**
 * Wazabii MySqli - Funktion
 * Version: 3.0
 * Copyright: All right reserved for Creative Army
 */

namespace PHPFuse\Query;

class Connect {

	private $server;
	private $user;
	private $pass;
	private $dbname;
	private $charset = "utf8mb4";

	static private $prefix;
	static private $self;
	static private $DB;

	function __construct($server, $user, $pass, $dbname) {
		$this->server = $server;
		$this->user = $user;
		$this->pass = $pass;
		$this->dbname = $dbname;
		self::$self = $this;
	}

	function setCharset(?string $charset) {
		$this->charset = $charset;
		return $this;
	}

	static function setPrefix(string $prefix) {
		self::$prefix = $prefix;
	}

	function getDBName() {
		return $this->dbname;
	}

	function execute() {
		self::$DB = new \mysqli($this->server, $this->user, $this->pass, $this->dbname);
		if(mysqli_connect_error()) {
			die('Failed to connect to MySQL: ' . mysqli_connect_error());
			throw new \Exception('Failed to connect to MySQL: '.mysqli_connect_error(), 1);
		}

		if(!is_null($this->charset) && !mysqli_set_charset(self::$DB, $this->charset)) {
			throw new \Exception("Error loading character set ".$this->charset.": ".mysqli_error(self::$DB), 2);
		}
		
		mysqli_character_set_name(self::$DB);

	}

	static function inst() {
		return self::$self;
	}

	static function DB() {
		return self::$DB;
	}

	static function query(string $sql) {
		return self::DB()->query($sql);
	}

	static function prep(string $value) {
		return self::DB()->real_escape_string($value);
	}

	static function prefix() {
		return self::$prefix;
	}

	static function selectDB(string $DB, ?string $prefix = NULL) {
		mysqli_select_db(self::$DB, $DB);
		if(!is_null($prefix)) self::setPrefix($prefix);
	}
	
	static function multiQuery(string $sql, &$mysqli = NULL) {

		$c = 0;
		$err = array();
		$mysqli = self::$DB;

		if(mysqli_multi_query($mysqli, $sql)) {
		    do {
		    	if($result = mysqli_use_result($mysqli)) {
		            while($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
		            }
		        }
		        if(!mysqli_more_results($mysqli)) break;
		        if(!mysqli_next_result($mysqli) || mysqli_errno($mysqli)) {
		            $err[$c] = mysqli_error($mysqli);
		            break;
		        }
		        $c++;

		    } while(true);
		    if($result) mysqli_free_result($result);

		} else {
		    $err[$c] = mysqli_error($mysqli);
		}

		//mysqli_close($mysqli);
		return $err;
	}

}
