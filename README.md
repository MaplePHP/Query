
# MaplePHP - MySQL queries
MaplePHP - MySQL queries is a powerful yet **user-friendly** library for making **safe** database queries.

### Contents
- [Connect to the database](#connect-to-the-database)
- [Make queries](#make-queries)
- [Attributes](#attributes)
- *Migrations (Coming soon)*

## Connect to the database

```php
use MaplePHP\Query\Connect;

$connect = new Connect($server, $user, $password, $databaseName);
$connect->setCharset("utf8mb4");
// Recommened: Set TABLE prefix. This will make your life easier
// MaplePHP DB class will "automatically prepend" it to the table names.
$connect->setPrefix("maple_");
$connect->execute();

```

## Make queries
Start with the namespace
```php
use MaplePHP\Query\DB;
```

### Select 1:
```php
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
"compare", "or"/"and" and "not".
```php 
$select->whereRoleStatusParent(1, 1, 0);  
// role = '1' AND status = '1' AND Parent = 0
$select->compare(">")->whereStatus(0)->or()->whereRole(1);
// status > '0' OR role = '1'
$select->not()->whereId(1)->whereEmail("john.doe@gmail.com");
// NOT id = '1' AND email = 'john.doe@gmail.com'
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
Having command works the same as where command above with exception that you rename "where" method to "having" and that the method "havingBind" do not exist. 
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
### Join
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

## Attributes
Each value is automatically escaped by default in the most effective manner to ensure consequential and secure data storage, guarding against SQL injection vulnerabilities. While it's possible to exert complete control over SQL input using various **Raw** methods, such an approach is not advisable due to the potential for mistakes that could introduce vulnerabilities. A safer alternative is to leverage the **Attr** class. The **Attr** class offers comprehensive configuration capabilities for nearly every value in the DB library, as illustrated below:
```php 
$idValue = DB::withAttr("1")
    ->prep(true)
    ->enclose(true)
    ->encode(true)
    ->jsonEncode(true);
    
$select->where("id",  $idValue);
```
#### Escape values and protect against SQL injections
```php 
public function prep(bool $prep): self;
```
**Example:**
- Input value: Lorem "ipsum" dolor
- Output value: Lorem \\"ipsum\\" dolor

#### Enable/disable string enclose
```php 
public function enclose(bool $enclose): self;
```
**Example:**
- Input value:  1186
- Output value: '1186'
*E.g. will add or remove quotes to values*

#### Enable/disable XSS protection
Some like to have the all the database data already HTML special character escaped.
```php 
public function encode(bool $encode): self;
```
**Example:**
- Input value: Lorem <strong>ipsum</strong> dolor
- Output value:  Lorem \<strong\>ipsum\</strong\> dolor

#### Automatically json encode array data
A pragmatic function that will automatically encode all array input data to a json string
```php 
public function jsonEncode(bool $jsonEncode): self;
```
**Example:**
- Input value: array("firstname" => "John", "lastname" => "Doe");
- Output value:  {"firstname":"John","lastname":"Doe"}

The default values vary based on whether it is a table column, a condition in a WHERE clause, or a value to be set. For instance, columns default to enclose set to false, whereas for WHERE or SET inputs, it defaults to true. Regardless, every value defaults to **prep**, **encode** and **jsonEncode** being set to **true**.

