<?php

use database\migrations\Test;
use database\migrations\TestCategory;
use MaplePHP\Query\Handlers\PostgreSQLHandler;
use MaplePHP\Query\Handlers\SQLiteHandler;
use MaplePHP\Unitary\Unit;
use MaplePHP\Query\Connect;
use MaplePHP\Query\DB;

// Only validate if there is a connection open!
if (Connect::hasInstance() && Connect::getInstance()->hasConnection()) {

    $unit = new Unit();

    // Add a title to your tests (not required)
    $unit->addTitle("Testing MaplePHP Query library!");
    $unit->add("MySql Query builder", function ($inst) {

        $db = Connect::getInstance();
        $select =  $db::select("id,a.name,b.name AS cat", ["test", "a"])->whereParent(0)->where("status", 0, ">")->limit(6);
        $select->join(["test_category", "b"], "tid = id");

        // 3 queries
        $obj = $select->get();
        $arr = $select->fetch();
        $pluck = DB::table("test")->pluck("name")->get();

        $inst->add($obj, [
            "isObject" => [],
            "missingColumn" => function () use ($obj) {
                return (isset($obj->name) && isset($obj->cat));
            }
        ], "Data is missing");

        $inst->add($arr, [
            "isArray" => [],
            "noRows" => function () use ($arr) {
                return (count($arr) > 0);
            }
        ], "Fetch feed empty");

        $inst->add($pluck, [
            "isString" => [],
            "length" => [1]
        ], "Pluck is expected to return string");

        $select =  $db::select("id,test.name,test_category.name AS cat", new Test)->whereParent(0)->where("status", 0, ">")->limit(6);
        $select->join(new TestCategory);
        $obj = $select->obj();

        $inst->add($obj, [
            "isObject" => [],
            "missingColumn" => function () use ($obj) {
                return (isset($obj->name) && isset($obj->cat));
            }
        ], "Data is missing");
    });

    /**
     * This will test multiple databases AND
     * validate sqLite database
     */
    $unit->add("sqLite Query builder", function ($inst) {

        $sqLiteHandler = new SQLiteHandler(__DIR__ . "/database.sqlite");
        $sqLiteHandler->setPrefix("mp_");
        $connect = Connect::setHandler($sqLiteHandler, "lite");
        $connect->execute();

        // Access sqLite connection
        $select = Connect::getInstance("lite")::select("id,name,content", "test")->whereStatus(1)->limit(3);
        $result = $select->fetch();
        $inst->add($result, [
            "isArray" => [],
            "rows" => function () use ($result) {
                return (count($result) === 3);
            }
        ], "Fetch should equal to 3");
    });

    /**
     * This will test multiple databases AND
     * validate sqLite database
     */
    $unit->add("sqLite Query builder", function ($inst) {

        $sqLiteHandler = new PostgreSQLHandler("127.0.0.1", "postgres", "", "maplephp");
        $sqLiteHandler->setPrefix("maple_");
        $connect = Connect::setHandler($sqLiteHandler, "psg");
        $connect->execute();

        // Access sqLite connection
        $select = Connect::getInstance("psg")::select("id,name", ["test", "a"])->limit(2);
        $result = $select->fetch();
        $inst->add($result, [
            "isArray" => [],
            "rows" => function () use ($result) {
                return (count($result) === 2);
            }
        ], "Fetch should equal to 2");
    });

    $unit->execute();
}
