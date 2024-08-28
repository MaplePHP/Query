<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use Exception;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\ResultException;
use MaplePHP\Query\Interfaces\HandlerInterface;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\MigrateInterface;

class ConnectTest
{


    public string $handler;
    public static self $inst;

    function __construct(string $handler)
    {
        $this->handler = $handler;

        if(is_null(self::$inst)) {
            self::$inst = $this;
        }
    }

    public static function getConnection(): self
    {
        return self::$inst;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        $inst = DBTest::{$name}(...$arguments);
        $inst->setConnection(self::$inst);
    }


}
