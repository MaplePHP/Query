<?php
/**
 * The MigrateInterface is used for creating Migrate Model files for the database "that" can 
 * also be used to communicate with the DB library
 */

namespace PHPFuse\Query\Interfaces;

interface MigrateInterface {

	/**
	 * Get build data and 
	 * @return Create
	 */
	public function getBuild();

	/**
	 * Will drop table when method execute is triggered
	 * @return void
	 */
	public function drop(): array;

	/**
	 * Get migration data
	 * @return array
	 */
	public function getData(): array;

	/**
	 * Read migration changes (before executing)
	 * @return string
	 */
	public function read(): string;

	/**
	 * Will create/alter all table
	 * @return array
	 */
	public function create(): array;

	/**
	 * Get message
	 * @param  array  $error
	 * @param  string $success
	 * @return string
	 */
	public function getMessage(array $error, string $success = "Success!"): string;

}
