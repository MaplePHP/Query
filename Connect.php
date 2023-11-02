<?php
/**
 * Wazabii MySqli - Funktion
 * Version: 3.0
 * Copyright: All right reserved for Creative Army
 */

namespace PHPFuse\Query;

use mysqli, mysqli_result;
use PHPFuse\Query\Exceptions\ConnectException;

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

	/**
	 * Set MySqli charset
	 * @param string $charset
	 */
	function setCharset(string $charset): void 
	{
		$this->charset = $charset;
	}

	/**
	 * Set table prefix
	 * @param string $prefix
	 */
	static function setPrefix(string $prefix): void 
	{
		self::$prefix = $prefix;
	}

	/**
	 * Connect to database
	 * @return void
	 */
	function execute(): void 
	{
		self::$DB = new mysqli($this->server, $this->user, $this->pass, $this->dbname);
		if(mysqli_connect_error()) {
			die('Failed to connect to MySQL: ' . mysqli_connect_error());
			throw new ConnectException('Failed to connect to MySQL: '.mysqli_connect_error(), 1);
		}
		if(!is_null($this->charset) && !mysqli_set_charset(self::$DB, $this->charset)) {
			throw new ConnectException("Error loading character set ".$this->charset.": ".mysqli_error(self::$DB), 2);
		}
		mysqli_character_set_name(self::$DB);
	}

	/**
	 * Get current instance
	 * @return self
	 */
	static function inst(): self 
	{
		return static::$self;
	}

	/**
	 * Get current DB connection
	 */
	static function DB(): mysqli 
	{
		return static::$DB;
	}

	/**
	 * Get selected database name
	 * @return string
	 */
	function getDBName(): string 
	{
		return $this->dbname;
	}

	/**
	 * Get current table prefix
	 * @return string
	 */
	static function getPrefix(): string 
	{
		return static::$prefix;
	}

	/**
	 * Query sql string
	 * @param  string $sql
	 * @return mysqli_result|bool
	 */
	static function query(string $sql): mysqli_result|bool 
	{
		return static::DB()->query($sql);
	}

	/**
	 * Protect/prep database values from injections
	 * @param  string $value
	 * @return string
	 */
	static function prep(string $value): string 
	{
		return static::DB()->real_escape_string($value);
	}

	/**
	 * Select a new database
	 * @param  string      $DB
	 * @param  string|null $prefix Expected table prefix (NOT database prefix)
	 * @return void
	 */
	static function selectDB(string $DB, ?string $prefix = NULL): void 
	{
		mysqli_select_db(static::$DB, $DB);
		if(!is_null($prefix)) static::setPrefix($prefix);
	}
	
	/**
	 * Execute multiple quries at once (e.g. from a sql file)
	 * @param  string $sql
	 * @param  object|null &$mysqli
	 * @return array
	 */
	static function multiQuery(string $sql, object &$mysqli = NULL): array 
	{
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

	/**
	 * Get current table prefix
	 * @return string
	 */
	static function prefix(): string 
	{
		return static::getPrefix();
	}
	
	/**
	 * Profile mysql speed
	 */
	static function startProfile(): void 
	{
		Connect::query("set profiling=1");
	}
	
	/**
	 * Close profile and print results
	 */
	static function endProfile($html = true): string|array 
	{
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
			return $output;
		} else {
			return array("row" => $output, "total" => $total);
		}
	}

}
