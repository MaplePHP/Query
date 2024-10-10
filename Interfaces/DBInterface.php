<?php

/**
 * The MigrateInterface is used for creating Migrate Model files for the database "that" can
 * also be used to communicate with the DB library
 */

namespace MaplePHP\Query\Interfaces;

/**
 * @method bind(\MaplePHP\Query\Prepare $param, array $statements)
 */
interface DBInterface
{
    /**
     * Generate SQL string of current instance/query
     * @return string
     */
    public function sql(): string;

}
