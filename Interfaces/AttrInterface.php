<?php

namespace PHPFuse\Query\Interfaces;

interface AttrInterface {

	/**
	 * Process string after your choises
	 * @return string
	 */
	function __toString();

	/**
	 * Get raw data from instance
	 * @return string|array|null
	 */
	public function getRaw(): string|array;

	/**
	 * Initiate the instance
	 * @param  string $value
	 * @return self
	 */
	public static function value(array|string|int|float $value): self;

	/**
	 * Enable/disable MySQL prep
	 * @param  bool   $prep
	 * @return self
	 */
	public function prep(bool $prep): self;

	/**
	 * Enable/disable XSS protection
	 * @param  bool   $encode (default true)
	 * @return self
	 */
	public function encode(bool $encode): self;

	/**
	 * Enable/disable string enclose
	 * @param  bool   $enclose
	 * @return self
	 */
	public function enclose(bool $enclose): self;


	/**
     * If Request[key] is array then auto convert it to json to make it database ready
     * @param  bool $yes = true
     * @return self
     */
    function jsonEncode(bool $jsonEncode = true): self;

	

}
