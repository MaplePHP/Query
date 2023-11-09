<?php

namespace PHPFuse\Query\Helpers;

use PHPFuse\Query\Connect;
use PHPFuse\Query\Interfaces\AttrInterface;
use PHPFuse\DTO\Format\Encode;

class Attr implements AttrInterface
{
    private $value;
    private $raw;
    private $hasBeenEncoded = false;
    private $prep = true;
    private $enclose = true;
    private $jsonEncode = true;
    private $encode = true;
    //private $mysqlVar = false;

    /**
     * Process string after your choises
     * @return string
     */
    public function __toString()
    {
        return $this->getValue();
    }

    public function getValue(): float|int|string
    {

        if (!$this->hasBeenEncoded) {
            $this->hasBeenEncoded = true;

            if ($this->encode) {
                $this->value = Encode::value($this->value)->encode(function ($val) {
                    if ($this->prep) {
                        $val = Connect::prep($val);
                    }
                    return $val;
                })->get();
            } else {
                if ($this->prep) {
                    $this->value = Connect::prep($this->value);
                }
            }
            if (is_array($this->value)) {
                $this->value = json_encode($this->value);
            }
            if ($this->enclose) {
                $this->value = "'{$this->value}'";
            }
        }
        return $this->value;
    }

    /**
     * Get raw data from instance
     * @return string|array|null
     */
    public function getRaw(): string|array
    {
        return $this->raw;
    }

    /**
     * Initiate the instance
     * @param  string $value
     * @return self
     */
    public static function value(array|string|int|float $value): self
    {
        $inst = new self();
        $inst->value = $value;
        $inst->raw = $value;
        return $inst;
    }

    /**
     * Enable/disable MySQL prep
     * @param  bool   $prep
     * @return self
     */
    public function prep(bool $prep): self
    {
        $this->prep = $prep;
        return $this;
    }

    /**
     * Enable/disable string enclose
     * @param  bool   $enclose
     * @return self
     */
    public function enclose(bool $enclose): self
    {
        $this->enclose = $enclose;
        return $this;
    }

    /**
     * If Request[key] is array then auto convert it to json to make it database ready
     * @param  bool $yes = true
     * @return self
     */
    public function jsonEncode(bool $jsonEncode = true): self
    {
        $this->jsonEncode = $jsonEncode;
        return $this;
    }

    /**
     * Enable/disable XSS protection
     * @param  bool   $encode (default true)
     * @return self
     */
    public function encode(bool $encode): self
    {
        $this->encode = $encode;
        return $this;
    }

    /**
     * // DEPRECATED
     * If Request[key] is array then auto convert it to json to make it database ready
     * @param  bool $yes = true
     * @return self
     */
    /*
    function mysqlVar(bool $mysqlVar = true): self
    {
        $this->mysqlVar = $mysqlVar;
        $this->enclose = false;
        $this->jsonEncode = false;
        $this->encode = false;
        $this->prep = false;
        return $this;
    }
     */
}
