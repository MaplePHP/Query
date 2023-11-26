# MaplePHP - MySQL queries
MaplePHP - MySQL queries is a powerful yet **user friendly** and **light weight** library for making **safe** database queries.

### Select 1:
```php
use MaplePHP\Query\DB;

$select = DB::select("id,firstname,lastname", "users a")->whereId(1)->where("status", 0, ">")->limit(1);
$select->join("login b", "b.user_id = a.id");
$obj = $select->get(); // Get one row result as object
```
### Select 2:
```php
$select = DB::select("id,name,content", "pages")->whereStatusParent(1, 0);
$array = $select->fetch(); // Get all rows as an array
```
### Where 1
```php 
$select->where("id", 1); // id = '1'
$select->where("parent", 0, ">");  // parent > '1'
```
### Where 2
```php 
$select->whereRoleStatusParent(1, 1, 0);  
// role = '1' AND status = '1' AND Parent = 0
$select->compare(">")->whereStatus(0)->or()->whereRole(1);
// status > '0' OR role = '1'
```
### Where 3
```php 
$select->whereBind(function($inst) {
    $select->where("start_date", "2023-01-01", ">=")
    ->where("end_date", "2023-01-14", "<=");
})->or()->whereStatus(1);
// (start_date >= '2023-01-01' AND end_date <= '2023-01-14') OR (status = '1')
```
### Where 4
```php 
$select->whereRaw("status = 1 AND visible = 1");  
// UNPROTECTED: status = 1 AND visible = 1
$select->whereRaw("status = %d AND visible = %d", [1, 1]);  
// PROTECTED: status = 1 AND visible = 1
```
### Having
Having command works the same as where command above with exception that you rename "where" in method to "having" and @havingBind do not exist. 
```php 
$select->having("id", 1); // id = '1'
$select->having("parent", 0, ">");  // parent > '1'
$select->havingRaw("status = 1 AND visible = 1");  
$select->havingRaw("status = %d AND visible = %d", [1, 1]);  
```

### Limit
```php 
$select->limit(1); // LIMIT 1
$select->offset(2); // OFFSET 2
$select->limit(10, 2); // LIMIT 10 OFFSET 2
```
### Order
```php 
$select->order("id"); 
// ORDER BY price ASC
$select->order("price", "DESC");
// ORDER BY price DESC
$select->order("id", "ASC")->order("parent", "DESC"); 
// ORDER BY id ASC, parent DESC
$select->orderRaw("id ASC, parent DESC"); 
// ORDER BY id ASC, parent DESC
```
### Limit
```php 
$select->join("tableName", "b.user_id = a.id"); // Default INNER join
$select->join("tableName", "b.user_id = '%d'", [872], "LEFT"); // PROTECTED INPUT
$select->joinInner("tableName", "b.user_id = a.id");
$select->joinLeft("tableName", "b.user_id = a.id");
$select->joinRight("tableName", "b.user_id = a.id");
$select->joinCross("tableName", "b.user_id = a.id");
```
### Insert
```php 
$insert = DB::insert("pages")->set(["id" => 36, "name" => "About us", "slug" => "about-us"])->onDupKey();
$insert->execute(); // bool
$insertID = $select->insertID(); // Get AI ID
```
### Update on duplicate
Will update row if primary key exist else Insert
```php 
$insert->onDupKey(); 
// Will update all the columns in the method @set
$insert->onDupKey(["name" => "About us"]); 
// Will only update the column name
```
### Update
```php 
$update = DB::update("pages")->set(["name" => "About us", "slug" => "about-us"])->whereId(34)->limit(1);
$update->execute();
```
### Delete
```php 
$delete = DB::delete("pages")->whereId(34)->limit(1);
$delete->execute();
```
### Set
```php 
$select->set("firstname", "John")->set("lastname", "Doe");
// Update/insert first- and last name
$select->set(["firstname" => "John", "lastname" => "Doe"])->set("lastname", "Doe"); 
// Same as above: Update/insert first- and last name
$select->setRaw("msg_id", "UUID()");
// UNPORTECTED and and will not be ENCLOSED!
```
### Preview SQL code before executing
```php 
echo $select->sql();
```
