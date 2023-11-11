<?php

/**
 * Wazabii DB - For main queries
 */

namespace PHPFuse\Query;

use PHPFuse\Query\Exceptions\ConnectException;
use PHPFuse\Query\Interfaces\DBInterface;
use PHPFuse\Query\Connect;

class Query
{
    private $sql;

    public function __construct(string|DBInterface $sql)
    {
        $this->sql = $sql;
        if ($sql instanceof DBInterface) {
            $this->sql = $sql->sql();
        }
    }

    /**
     * Execute query result
     * @return object|array|bool
     */
    public function execute(): object|array|bool
    {
        if ($result = Connect::query($this->sql)) {
            return $result;
        } else {
            throw new ConnectException(Connect::DB()->error, 1);
        }
        return false;
    }

    /**
     * Execute query result And fetch as obejct
     * @return bool|object|array
     */
    public function get(): bool|object|array
    {
        return $this->obj();
    }

    /**
     * SAME AS @get(): Execute query result And fetch as obejct
     * @return bool|object (Mysql result)
     */
    final public function obj(): bool|object
    {
        if (($result = $this->execute()) && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        return false;
    }

    /**
     * Execute SELECT and fetch as array with nested objects
     * @param  function $callback callaback, make changes in query and if return then change key
     * @return array
     */
    final public function fetch(?callable $callback = null): array
    {
        $key = 0;
        $select = null;
        $arr = array();

        if (($result = $this->execute()) && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                if ($callback) {
                    $select = $callback($row, $key);
                }
                $data = ((!is_null($select)) ? $select : $key);
                if (is_array($data)) {
                    $arr = array_replace_recursive($arr, $select);
                } else {
                    $arr[$data] = $row;
                }

                $key++;
            }
        }
        return $arr;
    }

    /**
     * Get insert AI ID from prev inserted result
     * @return int
     */
    public function insertID()
    {
        return Connect::DB()->insert_id;
    }
}
