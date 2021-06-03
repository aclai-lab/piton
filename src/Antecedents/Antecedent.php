<?php

namespace aclai-lab\piton\Antecedents;

use aclai-lab\piton\Attributes\Attribute;
use aclai-lab\piton\Attributes\ContinuousAttribute;
use aclai-lab\piton\Attributes\DiscreteAttribute;
use aclai-lab\piton\Facades\Utils;
use aclai-lab\piton\Instances\Instances;

/**
 * A single antecedent in the rule, composed of an attribute and a value for it.
 */
abstract class Antecedent
{
    /** The attribute of the antecedent */
    protected $attribute;

    /**
     * The attribute value of the antecedent. For numeric attribute, it represents the operator (<= or >=)
     */
    protected $value;

    /**
     * The maximum infoGain achieved by this antecedent test in the growing data
     */
    protected $maxInfoGaitn;

    /** The accurate rate of this antecedent test on the growing data */
    protected $accuRate;

    /** The coverage of this antecedent in the growing data */
    protected $cover;

    /** The accurate data for this antecedent in the growing data */
    protected $accu;


    /**
     * Constructor
     */
    function __construct(Attribute $attribute)
    {
        $this->attribute = $attribute;
        $this->value = NAN;
        $this->maxInfoGain = 0;
        $this->accuRate = NAN;
        $this->cover = NAN;
        $this->accu = NAN;
    }

    static function createFromAttribute(Attribute $attribute)
    {
        $antecedent = null;
        switch (true) {
            case $attribute instanceof DiscreteAttribute:
                $antecedent = new DiscreteAntecedent($attribute);
                break;
            case $attribute instanceof ContinuousAttribute:
                $antecedent = new ContinuousAntecedent($attribute);
                break;
            default:
                Utils::die_error("Unknown type of attribute encountered! " . get_class($attribute));
                break;
        }
        return $antecedent;
    }

    static function fromString(string $str, ?array $attrs_map = NULL, ?array $attributes = null)
    {
        $antecedent = null;
        switch (true) {
            /* I had a problem here with antecedent values that terminated with a parenthesis */
            //case preg_match("/^\s*\(?\s*(.*(?:\S))\s+(!=|=)\s+(.*(?:[^\s\)]))\s*\)?\s*$/", $str):
            case preg_match("/^\s*\(?\s*(.*(?:\S))\s+(!=|=)\s+(.*(?:[^\s\)]))\s*\)*\s*$/", $str):
                $antecedent = DiscreteAntecedent::fromString($str, $attrs_map, $attributes);
                break;
            case preg_match("/^\s*\(?\s*(.*(?:\S))\s+(<=|>=|>|<)\s+(.*(?:[^\s\)]))\s*\)?\s*$/", $str):
                #case preg_match("/^\s*\(?\s*(.*(?:\S))\s*(<=|>=|>|<)\s*(.*(?:[^\s\)]))\s*\)?\s*$/", $str):
                $antecedent = ContinuousAntecedent::fromString($str, $attrs_map);
                break;
            default:
                Utils::die_error("Invalid antecedent string encountered: " . PHP_EOL . $str);
                break;
        }
        return $antecedent;
    }

    /**
     * Functions for the single data instance
     */

    /* The abstract members for inheritance */
    abstract function splitData(Instances &$data, float $defAcRt, int $cla): ?array;

    abstract function covers(Instances &$data, int $instance_id): bool;

    /* Print a textual representation of the antecedent */
    function __toString(): string
    {
        return $this->toString();
    }

    abstract function toString(): string;


    /**
     * Print a serialized representation of the antecedent.
     * @return string The serialized representation of the antecedent.
     */
    abstract function serialize() : string;

    /**
     * Print an array serialized representation of the antecedent.
     * @return array The array serialized representation of the antecedent.
     */
    abstract function serializeToArray() : array;

    /**
     * Print a "json logic" serialized representation of the antecedent.
     * @return array The "json logic" serialized representation of the antecedent.
     */
    abstract function serializeToJsonLogic() : array;

    /**
     * Recreates an antecedent from an array.
     * @param array $antecedentArray The array containing the antecedent to be created.
     * @param array $attributes The array of attributes, needed to re-create discrete antecedents.
     * @return ContinuousAntecedent|DiscreteAntecedent The antecedent.
     */
    public abstract static function createFromArray(array $antecedentArray, ?array $attributes = null);


    function __clone()
    {
        $this->attribute = clone $this->attribute;
    }

    function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    function getValue()
    {
        return $this->value;
    }

    function setValue(int $value)
    {
        $this->value = $value;
    }

    function getMaxInfoGain(): float
    {
        return $this->maxInfoGain;
    }

    function getAccuRate(): float
    {
        return $this->accuRate;
    }

    function getCover(): float
    {
        return $this->cover;
    }

    function getAccu(): float
    {
        return $this->accu;
    }
}
