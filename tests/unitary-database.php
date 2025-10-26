<?php

use MaplePHP\Query\DBTest;
use MaplePHP\Query\Handlers\MySQLHandler;
use MaplePHP\Query\Handlers\PostgreSQLHandler;
use MaplePHP\Query\Handlers\SQLiteHandler;
use MaplePHP\Unitary\Unit;
use MaplePHP\Query\Prepare;

$unit = new Unit();

$unit->skip(true)->case("Unitary test 3333", function () use ($unit) {

    $handler = new MySQLHandler(getenv("DATABASE_HOST"), getenv("DATABASE_USERNAME"), getenv("DATABASE_PASSWORD"), "test");
    //$handler = new PostgreSQLHandler("127.0.0.1", "postgres", "", "postgres");
    //$handler = new SQLiteHandler(__DIR__ . "/database.sqlite");
    $handler->setPrefix("maple_");
    $db = new DBTest($handler);

    //echo $db->select(["id", "name"], "test")->where('parent', 1)->limit(2)->returning("id");
    //echo "\n";

     /*


     die("ww");

     $test = $db->insert("test")->set([
        "id" => 11,
        "name" => "Lorem dwqdqw",
        "content" => "dwqdwqwdq",
        "parent" => 0,
        "status" => 1
    ])->onDuplicateKey(["content" => "Aight 2"])->returning("id");

    $test = $db->insert("test_category")->set([
        "cat_id" => 7,
        "tid" => 11,
        "name" => "Cat wdwqdq",
    ])->onDuplicateKey()->returning("id");

    $p1 = $db->table("test")->where("parent", 1);
    $prepare = new Prepare($p1);

    var_dump($prepare->execute());


    die("YE");
      */



    /*
     // Working multi-table delete
     $test = $db->delete("test")->set([
        "name" => "dwqdwqwdq",
        "content" => "dwqdwqwdq 22",
        "parent" => 0,
        "status" => 1
    ])->where("id", 11)->join("test_category_clone", ["tid" => "id"])->returning("id");

    $result = $test->execute();
    var_dump($result, $test->insertID());
    die;
     */

    //echo "\n";
    //echo $db->update("test")->set(["parent" => 1, "status" => 1])->where('parent', 1)->limit(2)->returning("id");

    //die("EHEH");


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
        for($i = 0; $i < 2; $i++) {
            $prepare->bind($p1->where("parent", 1));
            $prepare->execute();
        }
    });
    */

    $unit->performance(function() use ($db) {
        $p1 = $db->table("test")->where("parent", 1);

        $prepare = new Prepare($p1);
        $prepare->bind($p1->where("parent", 0));
        print_r($prepare->fetch());
        die;
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

