<?php 

namespace Query;


class PHPFuse\MigrateFromHistory {


    private $_prefix;
    private $_migArr = array();
    private $_migPath;
    private $_migHistory;
   

    function __construct(string $migPath, string $prefix, array $migHistory) {
        $this->_prefix = $prefix;
        $this->_migPath = $migPath;
        $this->_migHistory = $migHistory;
    }

    private function _selectMigClass($mig) {
        return $this->_migPath.$mig;
    }

    function versionFloat(?string $version) {
        $exp = explode(".", $version);
        if(count($exp) === 3) {
            $num = (float)"{$exp[0]}{$exp[1]}.{$exp[2]}";
            return (float)preg_replace("/\D,./", "", $num);
        }
        return NULL;
    }

    function _select(string $keyType, ?string $newVersion) {
        $version = (float)$this->versionFloat($newVersion);
        if(is_float($version)) foreach($this->_migHistory as $v => $arr) {
            $v = (float)$this->versionFloat($v);
            if((isset($arr[$keyType]) && is_array($arr[$keyType]) && count($arr[$keyType]) > 0) && $v > $version) {
                $this->_migArr[] = $arr[$keyType];
            }
        }
        return false;
    }

    function hasNewMigVersion(string $keyType, ?string $newVersion) {
        $this->_select($keyType, $newVersion);
        return (count($this->_migArr) > 0) ? $this->_migArr : false;
    }

    function execute(string $keyType, ?string $newVersion) {

        $this->_select($keyType, $newVersion);
        if(is_array($this->_migArr) && count($this->_migArr) > 0) {
            foreach($this->_migArr as $arr) {
                foreach($arr as $c) {
                    $c = str_replace("/", "\\", $c);
                    $class = $this->_selectMigClass("\\{$c}");
                    if(class_exists($class)) {
                        $migTable = new $class($this->_prefix, true);
                        $mig = $migTable->migrate();
                        $mig->execute();
                        //echo $mig->build()."\n\n\n\n";
                        
                    } else {
                        $logger = new \media\log(\media\log::_dir(), \media\log::_file());
                        $logger->error("migrateFromHistory ONLOAD: Could not find the migrate file \"{$class}\".");
                    }
                }
            }
        }
    }

}