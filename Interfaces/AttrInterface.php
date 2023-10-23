<?php

namespace PHPFuse\Query\Interfaces;

interface AttrInterface {

	/**
	 * Process string after your choises
	 * @return string
	 */
	function __toString();

	/**
	 * Initiate the instance
	 * @param  string $value
	 * @return self
	 */
	public static function value(string $value): self;

	/**
	 * Enable/disable MySQL prep
	 * @param  bool   $prep
	 * @return self
	 */
	public function enclose(bool $enclose): self;

	/**
	 * Enable/disable string enclose
	 * @param  bool   $enclose
	 * @return self
	 */
	public function prep(bool $prep): self;

}
