<?php

namespace PHPFuse\Query;

class Backup
{
    private $dir;
    private $dbname;
    private $prefix;
    private $table;
    private $bundle;

    public function __construct($dir, $dbName, $prefix, $table)
    {
        $this->dir = $dir;
        $this->dbname = $dbName;
        $this->prefix = $prefix;
        $this->table = $table;
        $this->bundle = time();
    }

    /**
     * Create backup file - Should work on all servers
     * @return bool
     */
    public function create()
    {
        $output = array();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        if (!is_writable($this->dir)) {
            throw new \Exception("{$this->dir} is not writeable!", 1);
        }

        $worked = $this->mysqldump();
        if ((int)$worked === 0) {
            return $this->bundle;
        } else {
            return $this->withPhp();
        }
        return false;
    }

    /**
     * Load backup from bundle ID
     * DEPENDENCIES: EXEC is needs to be ENABLED!
     * @param  int $bundle bundle number
     * @return bool
     */
    public function load($bundle)
    {
        $output = array();
        $file = "{$this->dir}{$bundle}.sql";
        if (!function_exists("exec")) {
            throw new \Exception("exec needs to be enabled for query\backup::load(BUNDLE) to work!", 1);
        }

        if (is_file($file)) {
            $command = 'mysql -h' .DB_SERVER .' -u' .DB_USER .' -p' .DB_PASS .' ' .$this->dbname .' < ' .$file;
            exec($command, $output, $worked);

            if ((int)$worked !== 0) {
                throw new \Exception("An error occurred during the import. This is probobly becouse your webserver ".
                    "does not the right applications but you can dubble check the database credentials.", 1);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get Bauckup file path
     * @return string
     */
    private function file()
    {
        return "{$this->dir}{$this->bundle}.sql";
    }

    /**
     * Create .sql backup file with Mysqldump
     * @return If return "0" === success
     */
    protected function mysqldump()
    {

        $worked = 1; // Error
        if (function_exists("exec")) {
            $command = 'mysqldump --opt -h'.DB_SERVER.' -u' .DB_USER .' -p' .DB_PASS .' ' .
            $this->dbname .' '.$this->prefix.$this->table.' > '.$this->file();
            exec($command, $output, $worked);
        }
        return $worked;
    }

    /**
     * Create .sql backup file with PHP
     * @return Bundle int ID OR false
     */
    protected function withPhp()
    {
        $data = "";
        $rowArr = array();

        $result = Connect::query("SHOW CREATE TABLE ".$this->prefix.$this->table);
        $tbRow = $result->fetch_row();


        if (isset($tbRow[1])) {
            $data .= "\n\nDROP TABLE IF EXISTS `{$this->prefix}{$this->table}`;";
            $data .= "\n\n" . $tbRow[1] . ";\n\n";

            $select = DB::select("*", $this->table);
            $result = $select->execute();
            if ($result && $result->num_rows > 0) {
                $data .= "LOCK TABLES `{$this->prefix}{$this->table}` WRITE;\n\n";
                $k = 0;
                while ($row = $result->fetch_assoc()) {
                    if ($k === 0) {
                        $columns = array_keys($row);
                        $data .= "INSERT INTO `{$this->prefix}{$this->table}` (".implode(",", $columns).") VALUES \n";
                    }
                    $rowArr[] = "(".implode(',', array_map(function ($str) {
                        $str = str_replace(array("\n", "\r", "\t"), '', $str);
                        return "'{$str}'";
                    }, $row)).")";
                    $k++;
                }

                $data .= implode(",\n", $rowArr).";";
                $data .= "\n\nUNLOCK TABLES;\n\n";
            }

            file_put_contents($this->file(), $data);
            return $this->bundle;
        }

        return false;
    }
}
