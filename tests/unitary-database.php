<?php

use MaplePHP\Query\DBTest;
use MaplePHP\Query\Handlers\MySQLHandler;
use MaplePHP\Query\Handlers\PostgreSQLHandler;
use MaplePHP\Query\Handlers\SQLiteHandler;
use MaplePHP\Unitary\Unit;
use MaplePHP\Query\Prepare;

$unit = new Unit();

$unit->add("Unitary test 3333", function () use ($unit) {

    //$handler = new MySQLHandler(getenv("DATABASE_HOST"), getenv("DATABASE_USERNAME"), getenv("DATABASE_PASSWORD"), "test");
    //$handler = new PostgreSQLHandler("127.0.0.1", "postgres", "", "postgres");
    $handler = new SQLiteHandler(__DIR__ . "/database.sqlite");
    $handler->setPrefix("maple_");
    $db = new DBTest($handler);




    //$p1 = $db->table("test")->where("parent", 0);


    /*
     for($i = 0; $i < 80000; $i++) {
        //$db->getConnection()->query("SELECT * FROM maple_test WHERE parent=0");
        //$p1->query("SELECT * FROM maple_test WHERE parent=0")->execute();
        //$p1->query($p1->sql())->execute();
        //$p1->execute();
    }
     */




     /*
     $value = '0';
    $stmt = $db->getConnection()->prepare("SELECT * FROM maple_test WHERE parent=?");
    for($i = 0; $i < 80000; $i++) {
        $stmt->bind_param('s', $value);
        $value = '1';
        $stmt->execute();
    }
    $result = $stmt->get_result();
    $stmt->close();
      */

    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    $p1 = $db->table("test")->where("parent", 1);
    $unit->performance(function() use ($db) {
        $p1 = $db->table("test")->where("parent", 1);
        $prepare = new Prepare($p1);
        for($i = 0; $i < 40000; $i++) {
            $prepare->bind($p1->where("parent", 1));
            $prepare->execute();
        }
    });


    $p1 = $db->table("test")->where("parent", 1);
    $unit->performance(function() use ($db) {
        $p1 = $db->table("test")->where("parent", 1);
        $prepare = new Prepare($p1);
        for($i = 0; $i < 1000; $i++) {
            $prepare->bind($p1->where("parent", 1));
            $prepare->execute();
        }
    });

    $p1 = $db->table("test")->where("parent", 1);
    $unit->performance(function() use ($db) {
        $p1 = $db->table("test")->where("parent", 1);
        $prepare = new Prepare($p1);
        for($i = 0; $i < 10000; $i++) {
            $prepare->bind($p1->where("parent", 1));
            $prepare->execute();
        }
    });



    /*
     * $prepare = new Prepare($p1);
    for($i = 0; $i < 10000; $i++) {
        $prepare->bind($p1->where("parent", 1));
        $prepare->execute();
    }
     */



    /*



    for($i = 0; $i < 50000; $i++) {
         $p1->execute();
     }
     */



    die;

    /*
     Execution time: 28.407850027084 seconds
Memory used: 2291.7578125 KB
Peak memory used: 5136.6484375 KB
     */

    $this->add("Lorem ipsum dolor", [
        "isInt" => [],
        "length" => [1,200]

    ])->add(92928, [
        "isInt" => []

    ])->add("Lorem", [
        "isString" => [],
        "length" => function () {
            return $this->length(1, 50);
        }

    ], "The length is not correct!");

});

