<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers\SQLite;

use ReflectionClass;
use ReflectionException;
use SQLite3;
use SQLite3Result;

class SQLiteResult
{
    public int|string $num_rows = 0;
    private int $index = -1;
    private array|bool $rows = false;
    private array|bool $rowsObj = false;
    private SQLite3 $connection;
    private SQLite3Result|false $query = false;

    function __construct(SQLite3 $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get query
     * @param $sql
     * @return false|$this|self
     */
    public function query($sql): self|false
    {
        if($this->query = $this->connection->query($sql)) {
            $this->preFetchData();
            return $this;
        }
        return false;
    }

    /**
     * Fetch the next row of a result set as an object
     * @param string $class
     * @param array $constructor_args
     * @return object|false|null
     * @throws ReflectionException
     */
    public function fetch_object(string $class = "stdClass", array $constructor_args = []): object|false|null
    {
        if(!$this->startIndex()) {
            return false;
        }
        $data = $this->rowsObj[$this->index] ?? false;
        if ($class !== 'stdClass' && is_object($data)) {
            $data = $this->bindToClass($data, $class, $constructor_args);
        }
        $this->endIndex();

        return $data;
    }

    /**
     * Fetch the next row of a result set as an associative, a numeric array, or both
     * @param int $mode Should be the database default const mode value e.g. MYSQLI_BOTH|PGSQL_BOTH|SQLITE3_BOTH
     * @return array|false|null
     */
    public function fetch_array(int $mode = PGSQL_BOTH): array|false|null
    {
        if($mode !== SQLITE3_ASSOC) {
            return $this->query->fetchArray($mode);
        }
        if(!$this->startIndex()) {
            return false;
        }
        $data = $this->rows[$this->index] ?? false;
        $this->endIndex();
        return $data;

    }

    /**
     * Fetch the next row of a result set as an associative array
     * @return array|false|null
     */
    public function fetch_assoc(): array|false|null
    {
        if(!$this->startIndex()) {
            return false;
        }
        $data = $this->rows[$this->index] ?? false;
        $this->endIndex();
        return $data;
    }

    /**
     * Fetch the next row of a result set as an enumerated array
     * @return array|false|null
     */
    public function fetch_row(): array|false|null
    {
        return $this->query->fetchArray(SQLITE3_NUM);
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free(): void
    {
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function close(): void
    {
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free_result(): void
    {
    }

    /**
     * This will prepare the fetch data with information
     * @return void
     */
    protected function preFetchData(): void
    {
        $this->rowsObj = $this->rows = [];
        $this->num_rows = 0;
        $obj = $arr = array();
        while ($row = $this->query->fetchArray(SQLITE3_ASSOC)) {
            $arr[] = $row;
            $obj[] = (object)$row;
            $this->num_rows++;
        }

        if(count($arr) > 0) {
            $this->rows = $arr;
            $this->rowsObj = $obj;
        }
    }

    /**
     * Start the indexing
     * @return bool
     */
    protected function startIndex(): bool
    {
        if(($this->rows === false)) {
            return false;
        }
        $this->index++;
        return true;
    }

    /**
     * End the indexing and clean up
     * @return void
     */
    protected function endIndex(): void
    {
        if($this->index >= $this->num_rows) {
            $this->index = -1;
        }
    }

    /**
     * Bind object to a class
     * @param object|array $data
     * @param string $class
     * @param array $constructor_args
     * @return object|string|null
     * @throws ReflectionException
     */
    final protected function bindToClass(object|array $data, string $class, array $constructor_args = []): object|string|null
    {
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceArgs($constructor_args);
        foreach ($data as $key => $value) {
            if (property_exists($object, $key) && is_null($object->{$key})) {
                $object->{$key} = $value;
            }
        }
        return $object;
    }
}
