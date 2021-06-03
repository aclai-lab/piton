<?php

namespace aclai-lab\piton\Attributes;

use DateTime;

/**
 * Continuous attribute
 */
class ContinuousAttribute extends Attribute {

    /** The type of the attribute (ARFF/Weka style)  */
    static $type2ARFFtype = [
        "parsed"    => "numeric"
        , "int"       => "numeric"
        , "float"     => "numeric"
        , "double"    => "numeric"
        , "date"      => "date \"yyyy-MM-dd\""
        , "datetime"  => "date \"yyyy-MM-dd'T'HH:mm:ss\""
    ];
    function getARFFType() : string {
        return self::$type2ARFFtype[$this->type];
    }

    /** Whether there can be a mapping from one attribute to another */
    function isAtLeastAsExpressiveAs(Attribute $otherAttr) {
        return get_class($this) == get_class($otherAttr)
            && $this->getName() == $otherAttr->getName()
            ;
    }

    /** Obtain the value for the representation of the attribute */
    function getKey($cl) {
        return $cl;
    }

    /** Obtain the representation of a value of the attribute */
    function reprVal($val) : string {
        if ($val < 0 || $val === NULL)
            return $val;
        switch ($this->getARFFType()) {
            case "date \"yyyy-MM-dd\"":
                $date = new DateTime();
                $date->setTimestamp($val);
                return $date->format("Y-m-d");
                break;
            case "date \"yyyy-MM-dd'T'HH:mm:ss\"":
                $date = new DateTime();
                $date->setTimestamp($val);
                return $date->format("Y-m-d\TH:i:s");
                break;
            default:
                return strval($val);
                break;
        }
    }

    function reprValAs(ContinuousAttribute $oldAttr, $oldVal) {
        return $oldVal;
    }


    /** Print a textual representation of the attribute */
    function __toString() : string {
        return $this->toString();
    }
    function toString($short = false) : string {
        return $short ? $this->name : "[ContinuousAttribute '{$this->name}' (type {$this->type}) ]";
    }

    /**
     * Print an array serialized representation of the continuous attribute.
     * @return array The serialized representation of the continuous attribute.
     */
    public function serializeToArray() : array
    {

      return [
        //'index' => $this->getIndex(),
        'name' => $this->getName(),
        'type' => $this->getType(),
        'metadata' => $this->getMetadata()
      ];
    }

    /**
     * Recreates a continuous attribute from an array.
     * @param array $attributeArray The attribute in form of array.
     * @return ContinuousAttribute The relative object of type attribute.
     */
    public static function createFromArray(array $attributeArray) : ContinuousAttribute
    {
      $attribute = new ContinuousAttribute($attributeArray['name'], $attributeArray['type']);
      //$attribute->setIndex($attributeArray['index']);
      return $attribute;
    }
}
