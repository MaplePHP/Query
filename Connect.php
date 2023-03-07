<?php
/**
 * Wazabii MySqli - Funktion
 * Version: 3.0
 * Copyright: All right reserved for Creative Army
 */

namespace Query;

class Connect {

	private $_server;
	private $_user;
	private $_pass;
	private $_dbname;
	private $_charset = "utf8";

	static private $_prefix;
	static private $_DB;

	function __construct($server, $user, $pass, $dbname) {
		$this->_server = $server;
		$this->_user = $user;
		$this->_pass = $pass;
		$this->_dbname = $dbname;
	}

	function setCharset(?string $charset) {
		$this->_charset = $charset;
		return $this;
	}

	function setPrefix(string $prefix) {
		self::_setPrefix($prefix);
		return $this;
	}

	function execute() {
		self::$_DB = new \mysqli($this->_server, $this->_user, $this->_pass, $this->_dbname);
		if(mysqli_connect_error()) {
			die('Failed to connect to MySQL: ' . mysqli_connect_error());
			throw new \Exception('Failed to connect to MySQL: '.mysqli_connect_error(), 1);
		}

		if(!is_null($this->_charset) && !mysqli_set_charset(self::$_DB, $this->_charset)) {
			throw new \Exception("Error loading character set ".$this->_charset.": ".mysqli_error(self::$_DB), 2);
		}
		
		mysqli_character_set_name(self::$_DB);

	}

	static function _DB() {
		return self::$_DB;
	}

	static function _query(string $sql) {
		return self::_DB()->query($sql);
	}

	static function _prep(string $value) {
		return self::_DB()->real_escape_string($value);
	}

	static function _prefix() {
		return self::$_prefix;
	}

	static function _setPrefix(string $prefix) {
		self::$_prefix = $prefix;
	}

	static function _selectDB(string $DB, ?string $prefix = NULL) {
		mysqli_select_db(self::$_DB, $DB);
		if(!is_null($prefix)) self::setPrefix($prefix);
	}
	
	static function _multiQuery(string $sql, &$mysqli = NULL) {

		$c = 0;
		$err = array();
		$mysqli = self::$_DB;

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

?>