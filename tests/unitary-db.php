<?php

use database\migrations\Test;
use database\migrations\TestCategory;
use MaplePHP\Query\DBTest;
use MaplePHP\Query\Handlers\MySQLHandler;
use MaplePHP\Query\Handlers\PostgreSQLHandler;
use MaplePHP\Query\Handlers\SQLiteHandler;
use MaplePHP\Query\Prepare;
use MaplePHP\Query\Utility\Attr;
use MaplePHP\Unitary\Unit;
use MaplePHP\Query\Connect;


// Only validate if there is a connection open!
if (Connect::hasInstance() && Connect::getInstance()->hasConnection()) {

    $unit = new Unit();

    $handler = new MySQLHandler(getenv("DATABASE_HOST"), getenv("DATABASE_USERNAME"), getenv("DATABASE_PASSWORD"), "test");
    $handler->setPrefix("maple_");

    $db = new DBTest($handler);
    $unit->addTitle("Testing MaplePHP Query library!");

    $unit->add("OK", function ($inst) use ($unit, $db) {

        $prepare = new Prepare();
        for($i = 1; $i < 6; $i++) {

            //

           /*
            $test = $db->table("test")
                ->columns("id", "name")
                ->where("id", 1);


            $test2 = $db->table("test")
                ->columns("id", "name")
                ->where("id", 3)
                ->order("id", "DESC")
                ->limit(20);
            */

           $inst = $db->table(["test", "a"])
                ->join(["test_category", "cat"], ["cat.tid" => "a.id"])
                ->columns("a.id", "a.name", ['cat.name' => "test"])
                ->where("id", $i);

           $prepare->query($inst);

            /*
            print_r($inst->fetch());
            echo "\n\n";
            //print_r($test->sql());
            die;
             */
        }

        print_r($prepare->execute());
        die;


    });

    $unit->execute();
}


/*

    $instances = [null, "postgresql", "sqlite"];

    $sqLiteHandler = new PostgreSQLHandler("127.0.0.1", "postgres", "", "postgres");
    $sqLiteHandler->setPrefix("maple_");
    $connect = Connect::setHandler($sqLiteHandler, "postgresql");
    $connect->execute();

    $sqLiteHandler = new SQLiteHandler(__DIR__ . "/database.sqlite");
    $sqLiteHandler->setPrefix("maple_");
    $connect = Connect::setHandler($sqLiteHandler, "sqlite");
    $connect->execute();


    // Add a title to your tests (not required)
    $unit->addTitle("Testing MaplePHP Query library!");
    foreach($instances as $key) {
        $message = "Error in " . (is_null($key) ? "mysql" : $key);
        $unit->add($message, function ($inst) use ($unit, $key, $instances) {

            // Select handler
            $db = Connect::getInstance($key);

            $inst->add($db->hasConnection(), [
                "equal" => [true],
            ], "Missing connection");

            $select =  $db::select("test.id", "test")
                ->whereBind(function ($inst) {
                    $inst->not()
                        ->where("status", 0)
                        ->or()
                        ->where("status", 0, ">");
                })
                ->whereParent(0)
                ->having("id", 0, ">")
                ->whereRaw("id > 0")
                ->havingRaw("COUNT(id) > 0")
                ->group("id")
                ->distinct("id")
                ->limit(2);
            $select->join(["test_category", "b"], "tid = id");
            $arr = $select->fetch();


            //$unit->command()->message($select->sql());
            $inst->add(count($arr), [
                "equal" => [2],
            ], "Data is missing");


            // Test union
            $union =  $db::select("id,name", "test");
            $unit->command()->message(Connect::$current);

            $select = $db::select("cat_id AS id,name", "test_category");



            $insert = $db::insert("test")->set([
                "name" => "Test row",
                "content" => "Delete this row",
                "status" => 1,
                "parent" => 0,
                "create_date" => date("Y-m-d H:i:s", time()),
            ]);




            //print_r($db->connection());
            //$insert->execute();

        });
    }
 */