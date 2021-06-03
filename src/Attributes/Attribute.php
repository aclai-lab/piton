<?php

namespace aclai-lab\piton\Attributes;

use aclai-lab\piton\Antecedents\DiscreteAntecedent;
use aclai-lab\piton\Facades\Utils;

/**
 * Interface for continuous/discrete attributes
 */
abstract class Attribute
{
    /** The name of the attribute */
    protected $name;

    /** The type of the attribute */
    protected $type;

    /** The index of the attribute (useful when dealing with many attributes) */
    protected $index;

    /** Metadata */
    protected $metadata;

    public function __construct(string $name, string $type)
    {
        $this->name  = $name;
        $this->type  = $type;
    }

    /** The type of the attribute (ARFF/Weka style)  */
    abstract public function getARFFType() : string;

    /** Obtain the value for the representation of the attribute */
    abstract public function getKey($cl);

    /** Obtain the representation of a value of the attribute */
    abstract public function reprVal($val) : string;

    /** Print a textual representation of the attribute */
    abstract public function toString() : string;

    /** The type of the attribute (ARFF/Weka style)  */
    protected static $ARFFtype2type = [
        "numeric"  => "float"
        , "real"     => "float"
    ];

    public static function createFromARFF(string $line, string $csv_delimiter = "'") : Attribute
    {
        $wordChars = "\wA-Za-zÀ-ÖØ-öø-ÿ\/()-’";
        if (preg_match("/@attribute\s+'/i", $line)) {
            $regExp =  "/@attribute\s+'([\s$wordChars]+)'\s+(.*)/i";
        } else if (preg_match("/@attribute\s+\"/i", $line)) {
            /** Regex to ignore escaped quotes within quotes: https://stackoverflow.com/a/5696141/5646732 */
            $regExp =  "/@attribute\s+\"(" . '[^"\\\\]*(?:\\\\.[^"\\\\]+)*' . ")\"\s+(.*)/i";
        } else {
            $regExp =  "/@attribute\s+([$wordChars]+)\s+(.*)/i";
        }

        preg_match($regExp, $line, $matches);
        if (count($matches) < 2) {
            Utils::die_error("Malformed ARFF attribute line:" . PHP_EOL . $line . PHP_EOL . $regExp);
        }

        $name = $matches[1];
        $type = $matches[2];
        switch (true) {
            case preg_match("/\{\s*(.*)\s*\}/", $type, $domain_str):
                $domain_arr = array_map("trim",  array_map("trim", str_getcsv($domain_str[1], ",", $csv_delimiter)));
                $attribute = new DiscreteAttribute($name, "enum", $domain_arr);
                break;
            case isset(self::$ARFFtype2type[$type])
                && in_array(self::$ARFFtype2type[$type], ["float", "int"]):
                $attribute = new ContinuousAttribute($name, self::$ARFFtype2type[$type]);
                break;
            default:
                Utils::die_error("Unknown ARFF type encountered: " . $type);
                break;
        }
        return $attribute;
    }

    /**
     * Recreate the type and eventual domain for an attribute when reading it from a database table,
     * making use of column comments
     */
    public static function createFromDB(string $name, string $type, string $csv_delimiter = "'") : Attribute {
        switch (true) {
            case preg_match("/\{\s*(.*)\s*\}/", $type, $domain_str);
                $domain_arr = array_map("trim",  array_map("trim", str_getcsv($domain_str[1], ",", $csv_delimiter)));
                $attribute = new DiscreteAttribute($name, "enum", $domain_arr);
                break;
            case isset(self::$ARFFtype2type[$type])
                && in_array(self::$ARFFtype2type[$type], ["float", "int"]):
                $attribute = new ContinuousAttribute($name, self::$ARFFtype2type[$type]);
                break;
            default:
                Utils::die_error("Unknown ARFF type encountered: " . $type);
                break;
        }
        return $attribute;
    }

    /** Whether two attributes are equal (completely interchangeable) */
    public function isEqualTo(Attribute $otherAttr) : bool {
        return (get_class($this) === get_class($otherAttr))
            && ($this->name === $otherAttr->name)
            && ($this->type === $otherAttr->type)
            && ($this->index === $otherAttr->index);
    }

    /** Whether there can be a mapping from one attribute to another */
    abstract public function isAtLeastAsExpressiveAs(Attribute $otherAttr);

    public function getName() : string
    {
        return $this->name;
    }

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function getType() : string
    {
        return $this->type;
    }

    public function setType(string $type)
    {
        $this->type = $type;
    }

    public function getIndex() : int
    {
        if(!($this->index !== NULL))
            Utils::die_error("Attribute " . $this->getName() . " with uninitialized index");
        return $this->index;
    }

    public function setIndex(int $index)
    {
        $this->index = $index;
    }

    public function getMetadata()
    {
        return $this->metadata;
    }

    public function setMetadata($metadata)
    {
        $this->metadata = $metadata;
    }

    public abstract function serializeToArray() : array;

    /**
     * Recreates an attribute from an array.
     * @param array $attributeArray The array containing the attribute to be created.
     * @return ContinuousAttribute|DiscreteAttribute The attribute.
     */
    public static function createFromArray(array $attributeArray)
    {
      $attribute = null;
      if ($attributeArray['type'] == 'enum' || $attributeArray['type'] == 'bool') {
        $attribute = DiscreteAttribute::createFromArray($attributeArray);
      }
      else if ($attributeArray['type'] == 'float') {
        $attribute = ContinuousAttribute::createFromArray($attributeArray);
      }
      else {
        Utils::die_error("An error occured creating Attribute from Array." . PHP_EOL
        . "Attribute type: " . $attributeArray['type'] . PHP_EOL);
      }
      return $attribute;
    }
}
