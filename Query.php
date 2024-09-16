<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\DBInterface;

class Query
{
    private $sql;
    private $prepare;
    private ?array $bind = null;
    private ?string $pluck = null;
    private ConnectInterface $connection;

    public function __construct(ConnectInterface $connection, string|DBInterface $sql)
    {
        $this->sql = $sql;
        if ($sql instanceof DBInterface) {
            $this->prepare = $this->sql->__get("prepare");
            $this->sql = $sql->sql();
        }
        $this->connection = $connection;
    }

    public function setPluck(?string $pluck): void
    {
        $this->pluck = $pluck;
    }

    public function bind(array $set): self
    {
        $this->bind = $set;
        return $this;
    }

    /**
     * Execute query result
     * @return object|array|bool
     * @throws ConnectException
     */
    public function execute(): object|array|bool
    {
        if(!is_null($this->bind)) {
            $arr = [];
            $stmt = $this->connection->prepare($this->bind[0]->sql());
            foreach($this->bind as $dbInst) {
                $dbInst->sql();
                $ref = $dbInst->getQueryBuilder()->getSet();
                $stmt->bind_param(str_pad("", count($ref), "s"), ...$ref);
                $stmt->execute();
                $arr[] = $stmt;
            }
            //$stmt->close();

            return $arr;
        }

        if ($result = $this->connection->query($this->sql)) {
            return $result;
        } else {
            throw new ConnectException($this->connection->DB()->error, 1);
        }
    }

    /**
     * Execute query result And fetch as object
     * @return bool|object|string
     * @throws ConnectException
     */
    public function get(): bool|object|string
    {
        return $this->obj();
    }

    /**
     * SAME AS @get(): Execute query result And fetch as obejct
     * @return bool|object|string (Mysql result)
     * @throws ConnectException
     */
    final public function obj(string $class = "stdClass", array $constructor_args = []): bool|object|string
    {
        $result = $this->execute();
        if (is_object($result) && $result->num_rows > 0) {
            $obj = $result->fetch_object($class, $constructor_args);
            if(!is_null($this->pluck)) {
                $obj = $obj->{$this->pluck};
            }
            return $obj;
        }
        return false;
    }

    /**
     * Execute SELECT and fetch as array with nested objects
     * @param callable|null $callback callaback, make changes in query and if return then change key
     * @return array
     * @throws ConnectException
     */
    final public function fetch(?callable $callback = null, string $class = "stdClass", array $constructor_args = []): array
    {
        $key = 0;
        $select = null;
        $arr = array();
        $result = $this->execute();
        if (is_object($result) && $result->num_rows > 0) {
            while ($row = $result->fetch_object($class, $constructor_args)) {

                if(!is_null($this->pluck)) {
                    $row = $row->{$this->pluck};
                }

                if ($callback) {
                    $select = $callback($row, $key);
                }
                $data = ((!is_null($select)) ? $select : $key);
                if (is_array($data)) {
                    if (!is_array($select)) {
                        throw new \InvalidArgumentException("The return value of the callable needs to be an array!", 1);
                    }
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
     * @return string|int
     * @throws ConnectException
     */
    public function insertID(): string|int
    {
        return $this->connection->DB()->insert_id;
    }
}
