<?php

namespace PHPFuse\Query;

use PHPFuse\Query\Interfaces\AttrInterface;

class Attr implements AttrInterface {

	private $value;
	private $prep = true;
	private $enclose = true;
	
	/**
	 * Process string after your choises
	 * @return string
	 */
	function __toString() {
		if($this->prep) $this->value = Connect::prep($this->value);
		if($this->enclose) $this->value = "'{$this->value}'";
		return $this->value;
	}

	/**
	 * Initiate the instance
	 * @param  string $value
	 * @return self
	 */
	public static function value(string $value): self 
	{
		$inst = new self();
		$inst->value = $value;
		return $inst;
	}

	/**
	 * Enable/disable MySQL prep
	 * @param  bool   $prep
	 * @return self
	 */
	public function prep(bool $prep): self 
	{
		$this->prep = $prep;
		return $this;
	}

	/**
	 * Enable/disable string enclose
	 * @param  bool   $enclose
	 * @return self
	 */
	public function enclose(bool $enclose): self 
	{
		$this->enclose = $enclose;
		return $this;
	}

}
