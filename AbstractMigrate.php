<?php
declare(strict_types=1);

namespace PHPFuse\Query;

use PHPFuse\Query\Create;
use PHPFuse\Query\Interfaces\MigrateInterface;

abstract class AbstractMigrate implements MigrateInterface
{
    protected $mig;
    protected $table;

    abstract protected function buildTable();

    public function __construct(string $table, ?string $prefix = null)
    {
        if (is_null($prefix)) {
            $prefix = getenv("MYSQL_PREFIX");
            if ($prefix === false) {
                throw new \Exception("Table prefix is required!", 1);
            }
        }
        $this->mig = new Create($table, $prefix);
        $this->table = $table;
    }

    /**
     * Get build data and
     * @return Create
     */
    public function getBuild(): Create
    {
        $this->buildTable();
        return $this->mig;
    }

    /**
     * Get build data and
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Will drop table when method execute is triggered
     * @return array
     */
    public function drop(): array
    {
        $this->mig->drop();
        return $this->mig->execute();
    }

    /**
     * Get migration data
     * @return array
     */
    public function getData(): array
    {
        $this->buildTable();
        return $this->mig->getColumns();
    }

    /**
     * Read migration changes (before executing)
     * @return string
     */
    public function read(): string
    {
        $this->buildTable();
        $this->mig->create();
        if (!$this->mig->getColumns()) {
            throw new \Exception("There is nothing to read in migration.", 1);
        }
        return $this->mig->build();
    }

    /**
     * Will create/alter all table
     * @return array
     */
    public function create(): array
    {
        $this->mig->create();
        $this->buildTable();
        if (!$this->mig->getColumns()) {
            throw new \Exception("There is nothing to commit in migration.", 1);
        }
        return $this->mig->execute();
    }

    /**
     * Get message
     * @param  array  $error
     * @param  string $success
     * @return string
     */
    public function getMessage(array $error, string $success = "Success!"): string
    {
        if (count($error) > 0) {
            $sqlMessage = "";
            foreach ($error as $key => $val) {
                $sqlMessage .= "{$key}: {$val}\n";
            }
        } else {
            $sqlMessage = $success;
        }
        return $sqlMessage;
    }
}
