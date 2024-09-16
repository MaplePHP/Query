<?php

namespace MaplePHP\Query\Utility;

use BadMethodCallException;
use InvalidArgumentException;
use MaplePHP\Query\Interfaces\AttrInterface;
use MaplePHP\DTO\Format\Encode;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\HandlerInterface;

/**
 * MAKE IMMUTABLE in future
 */
class Attr implements AttrInterface
{
    const RAW_TYPE = 0;
    const VALUE_TYPE = 1;
    const COLUMN_TYPE = 2;
    const VALUE_TYPE_NUM = 3;
    const VALUE_TYPE_STR = 4;

    private ConnectInterface|HandlerInterface $connection;
    private float|int|array|string|null $value = null;
    private float|int|array|string $raw;
    private array $set = [];
    //private bool $hasBeenEncoded = false;

    private int $type = 0;
    private bool $prep = true;
    private bool $sanitize = true;
    private bool $enclose = true;
    private bool $jsonEncode = false;
    private bool $encode = false;

    /**
     * Initiate the instance
     * @param ConnectInterface|HandlerInterface $connection
     */
    public function __construct(ConnectInterface|HandlerInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Process string after your choices
     * @return string
     */
    public function __toString(): string
    {
        return $this->getValue();
    }

    /**
     * IMMUTABLE: Set value you that want to encode against.
     * Will also REST all values to its defaults
     * @param float|int|array|string $value
     * @return self
     */
    public function withValue(float|int|array|string $value): self
    {
        $inst = new self($this->connection);
        $inst->value = $value;
        $inst->raw = $value;
        return $inst;
    }

    public function type(int $dataType): static
    {
        $inst = clone $this;
        if($dataType < 0 || $dataType > self::VALUE_TYPE_STR) {
            throw new InvalidArgumentException('The data type expects to be either "RAW_TYPE (0), VALUE_TYPE (1), 
                COLUMN_TYPE (2), VALUE_TYPE_NUM (3), VALUE_TYPE_STR (4)"!');
        }
        $inst->type = $dataType;
        if($dataType === self::RAW_TYPE) {
            $inst = $inst->prep(false)->enclose(false)->encode(false)->sanitize(false);
        }

        if($dataType === self::VALUE_TYPE_NUM) {
            $inst = $inst->enclose(false);
            $inst->value = (float)$inst->value;
        }

        // Will not "prep" column type by default but instead it will "sanitize"
        if($dataType === self::COLUMN_TYPE) {
            $inst = $inst->prep(false)->sanitize(true);
        }
        return $inst;
    }

    public function isType(int $type): int
    {
        return ($this->type === $type);
    }


    /**
     * IMMUTABLE: Enable/disable MySQL prep
     * @param  bool   $prep
     * @return static
     */
    public function prep(bool $prep): static
    {
        $inst = clone $this;
        $inst->prep = $prep;
        return $inst;
    }

    /**
     * Sanitize column types
     * @param bool $sanitize
     * @return $this
     */
    public function sanitize(bool $sanitize): static
    {
        $inst = clone $this;
        $inst->sanitize = $sanitize;
        return $inst;
    }

    /**
     * CHANGE name to RAW?
     * IMMUTABLE: Enable/disable string enclose
     * @param  bool   $enclose
     * @return static
     */
    public function enclose(bool $enclose): static
    {
        $inst = clone $this;
        $inst->enclose = $enclose;
        return $inst;
    }

    /**
     * IMMUTABLE: If Request[key] is array then auto convert it to json to make it database ready
     * @param  bool $jsonEncode
     * @return static
     */
    public function jsonEncode(bool $jsonEncode): static
    {
        $inst = clone $this;
        $inst->jsonEncode = $jsonEncode;
        return $inst;
    }

    /**
     * CHANGE name to special char??
     * IMMUTABLE: Enable/disable XSS protection
     * @param  bool   $encode (default true)
     * @return static
     */
    public function encode(bool $encode): static
    {
        $inst = clone $this;
        $inst->encode = $encode;
        return $inst;
    }

    /**
     * CHANGE NAME TO GET??
     * Can only be encoded once
     * Will escape and encode values the right way buy the default
     * If prepped then quotes will be escaped and not encoded
     * If prepped is disabled then quotes will be encoded
     * @return string
     */
    public function getValue(): string
    {

        $inst = clone $this;
        if(is_null($inst->value)) {
            throw new BadMethodCallException("You need to set a value first with \"withValue\"");
        }

        $inst->value = Encode::value($inst->value)
            ->specialChar($inst->encode, ($inst->prep ? ENT_NOQUOTES : ENT_QUOTES))
            ->sanitizeIdentifiers($inst->sanitize)
            ->urlEncode(false)
            ->encode();

        // Array values will automatically be json encoded
        if ($inst->jsonEncode || is_array($inst->value)) {
            // If prep is on then escape after json_encode,
            // otherwise json encode will possibly escape the escaped value
            $inst->value = json_encode($inst->value);
        }

        if($inst->prep) {
            $inst->value = $inst->connection->prep($inst->value);
        }

        if ($inst->enclose) {
            $inst->value = ($inst->type === self::COLUMN_TYPE) ? $this->getValueToColumn() : "'$inst->value'";
        }

        return $inst->value;
    }

    /**
     * Will convert a value to a column type
     * @return string
     */
    protected function getValueToColumn(): string
    {
        $arr = [];
        $exp = explode('.', $this->value);
        foreach($exp as $value) {
            $arr[] = "`$value`";
        }
        return implode('.', $arr);
    }

    /**
     * Get raw data from instance
     * @return string|array
     */
    public function getRaw(): string|array
    {
        return $this->raw;
    }
}
