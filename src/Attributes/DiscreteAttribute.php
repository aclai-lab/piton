<?php

namespace aclai-lab\piton\Attributes;

use aclai-lab\piton\Facades\Utils;

/**
 * Discrete attribute
 */
class DiscreteAttribute extends Attribute {

  /** Domain: discrete set of values that an instance can show for the attribute */
  private $domain;

  function __construct(string $name, string $type, array $domain = []) {
        parent::__construct($name, $type);
        $this->setDomain($domain);
    }

    // /** Whether two attributes are equal (completely interchangeable) */
    function isEqualTo(Attribute $otherAttr) : bool {
        return $this->getDomain() == $otherAttr->getDomain()
            && parent::isEqualTo($otherAttr);
    }

    /** Whether there can be a mapping from one attribute to another */
    function isAtLeastAsExpressiveAs(Attribute $otherAttr) {
        return get_class($this) == get_class($otherAttr)
            && $this->getName() == $otherAttr->getName()
            // && $this->getType() == $otherAttr->getType()
            && !count(array_diff($otherAttr->getDomain(), $this->getDomain()));
    }

    function numValues() : int { return count($this->domain); }
    function pushDomainVal(string $cl) : int { $this->domain[] = $cl; return $this->getKey($cl); }

    /** Obtain the value for the representation of the attribute */
    function getKey($cl, $safety_check = false) {
        $i = array_search($cl, $this->getDomain());
        if ($i === false && $safety_check) {
            Utils::die_error("Couldn't find element \"" . Utils::get_var_dump($cl)
                . "\" in domain of attribute {$this->getName()} ("
                . Utils::get_arr_dump($this->getDomain()) . "). ");
        }
        return $i;
    }

    /** Obtain the representation of a value of the attribute */
    function reprVal($val) : string {
        return $val < 0 || $val === NULL ? $val : strval($this->domain[$val]);
    }

    /** Obtain the representation for the attribute of a value
    that belonged to a different domain */
    function reprValAs(DiscreteAttribute $oldAttr, ?int $oldVal, bool $force = false) {
        if ($oldVal === NULL) return NULL;
        $cl = $oldAttr->reprVal($oldVal);
        $i = $this->getKey($cl);
        if ($i === false) {
            if ($force) {
                return $this->pushDomainVal($cl);
            } else {
                Utils::die_error("Can't represent nominal value \"$cl\" ($oldVal) within domain " . Utils::get_arr_dump($this->getDomain()));
            }
        }
        return $i;
    }


    /** The type of the attribute (ARFF/Weka style)  */
    function getARFFType() : string {
        return "{" . join(",", array_map(function ($val) { return "'" . addcslashes($val, "'") . "'"; }, $this->domain)) . "}";
    }

    /** Print a textual representation of the attribute */
    function __toString() : string {
        return $this->toString();
    }
    function toString($short = false) : string {
        return $short ? $this->name : "[DiscreteAttribute '{$this->name}' (type {$this->type}): " . Utils::get_arr_dump($this->domain) . " ]";
    }

    function getDomain() : array
    {
        return $this->domain;
    }

    /** Get the domain in the form 'value1','value2',...,'valueN' */
    function getDomainString() : string {
        return implode("','",$this->domain);
    }

    function setDomain(array $domain)
    {
        foreach ($domain as $val) {
            if(!(is_string($val)))
                Utils::die_error("Non-string value encountered in domain when setting domain "
                    . "for DiscreteAttribute \"{$this->getName()}\": " . gettype($val));
        }
        $this->domain = $domain;
    }

    /**
     * Print an array serialized representation of the discrete attribute.
     * @return array The serialized representation of the discrete attribute.
     */
      public function serializeToArray() : array
      {
        $domain = $this->getDomain();

        return [
          //'index' => $this->getIndex(),
          'name' => $this->getName(),
          'type' => $this->getType(),
          'metadata' => $this->getMetadata(),
          'domain' => isset($domain[1]) ?
            [$domain[0], $domain[1]] : [$domain[0]]
        ];
      }

    /**
     * Recreates a discrete attribute from an array.
     * @param array $attributeArray The attribute in form of array.
     * @return DiscreteAttribute The relative object of type attribute.
     */
    public static function createFromArray(array $attributeArray) : DiscreteAttribute
    {
      $attribute = new DiscreteAttribute($attributeArray['name'], $attributeArray['type']);
      //$attribute->setIndex($attributeArray['index']);
      $domain = $attributeArray['domain'];
      $attribute->setDomain($domain);
      return $attribute;
    }
}