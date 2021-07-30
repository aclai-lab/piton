<?php

namespace aclai\piton\Antecedents;

use aclai\piton\Attributes\Attribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;

/**
 * An antecedent with discrete attribute
 */
class DiscreteAntecedent extends Antecedent
{

    protected $sign;

    /**
     * Constructor
     */
    function __construct(Attribute $attribute)
    {
        if (!($attribute instanceof DiscreteAttribute))
            Utils::die_error("DiscreteAntecedent requires a DiscreteAttribute. Got "
                . get_class($attribute) . " instead.");
        parent::__construct($attribute);
    }

    /**
     * Splits the data into bags according to the nominal attribute value.
     * The infoGain for each bag is also calculated.
     *
     * @param Instances $data The data to be split
     * @param float $defAcRt The default accuracy rate for data
     * @param int $cla The class label to be predicted
     * @return array The array of data after split
     *
     * NOTE: JRip rules only allow sign=0
     */
    function splitData(Instances &$data, float $defAcRt, int $cla): ?array
    {
        $bag = $this->attribute->numValues();

        $splitData = [];
        for ($i = 0; $i < $bag; $i++) {
            $splitData[] = Instances::createEmpty($data);
        }
        $accurate = array_fill(0, $bag, 0);
        $coverage = array_fill(0, $bag, 0);

        $index = $this->attribute->getIndex();

        /* Split data */
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            // $val = $this->inst_valueOfAttr($data, $x);
            $val = $data->inst_val($instance_id, $index);
            if ($val !== NULL) {
                $splitData[$val]->pushInstanceFrom($data, $instance_id);
                $w = $data->inst_weight($instance_id);
                $coverage[$val] += $w;
                if ($data->inst_classValue($instance_id) == $cla) {
                    $accurate[$val] += $w;
                }
            }
        }

        /* Compute goodness and find best bag */
        for ($x = 0; $x < $bag; $x++) {
            $t = $coverage[$x] + 1.0;
            $p = $accurate[$x] + 1.0;
            $infoGain = $accurate[$x] * (log($p / $t, 2) - log($defAcRt, 2));

            if ($infoGain > $this->maxInfoGain) {
                $this->value = $x;
                $this->maxInfoGain = $infoGain;
                $this->accuRate = $p / $t;
                $this->cover = $coverage[$x];
                $this->accu = $accurate[$x];
            }
        }

        // NOTE: JRip rules only allow sign=0
        $this->sign = 0;
        return $splitData;
    }

    /**
     * Whether the instance is covered by this antecedent
     *
     * @param Instances $data The set of instances
     * @param int $instance_id The index of the instance in question
     * @return bool The boolean value indicating whether the instance is covered by this antecedent
     */
    function covers(Instances &$data, int $instance_id): bool
    {
        $isCover = false;

        $index = $this->attribute->getIndex();
        $val = $data->inst_val($instance_id, $index);

        if ($val !== NULL) {
            if (!($val == $this->value xor ($this->sign == 0))) {
                $isCover = true;
            }
        }

        #debug
        #echo "Antecedent : ";
        #echo $this . PHP_EOL;
        #echo "Val:";
        #echo  $val . PHP_EOL;
        #echo "Is cover: ";
        #echo $isCover ? "True" . PHP_EOL : "False" . PHP_EOL;
        #debug

        return $isCover;
    }

    static function fromString(string $str, ?array $attrs_map = NULL, ?array $attributes = null) : Antecedent
    {
        /* First I didn't match the final ')', now I match and trim it in case it's part of the att. value. */
        /* The previous match didn't work in case the attribute value ended with a closing parethesis. */
        /* I removed it from the non-capturing group and manually remove just one each time-*/
        //if (!preg_match("/^\s*\(?\s*(.*(?:\S))\s+(!=|=)\s+(.*(?:[^\s\)]))\s*\)*\s*$/", $str, $w)) {
        if (!preg_match("/^\s*\(?\s*(.*(?:\S))\s+(!=|=)\s+(.*(?:[^\s]))\s*\)*\s*$/", $str, $w)) {
            Utils::die_error("Couldn't parse DiscreteAntecedent string \"$str\".");
        }

        $name = $w[1];
        $sign = $w[2];
        $reprvalue = mb_substr($w[3], 0, -1);

        if ($attributes != null) {
          $domain = null;
          foreach ($attributes as $attr) {
            if ($attr->getIndex() == $attrs_map[$name]) {
              $domain = $attr->getDomain();
              break;
            }
          }
          $out_map = array_flip($domain);
          $attribute = new DiscreteAttribute($name, "parsed", [strval($domain[0]), strval($domain[1])]);
        }
        else {
          $attribute = new DiscreteAttribute($name, "parsed", [strval($reprvalue)]);
        }
        if ($attrs_map !== NULL) {
          $attribute->setIndex($attrs_map[$name]);
        }
        $ant = DiscreteAntecedent::createFromAttribute($attribute);
        $ant->sign = ($sign == "=" ? 0 : 1);
        if (isset($out_map)) {
          $ant->value = $out_map[strval($reprvalue)];
        }
        else {
          $ant->value = 0;
        }

        return $ant;
    }

    /**
     * Print a textual representation of the antecedent
     */
    function toString(bool $short = false) : string
    {
        if ($short) {
            return "{$this->attribute->getName()}" . ($this->sign == 0 ? " = " : " != ")
              . "{$this->attribute->reprVal($this->value)}";
        } else {
            return "DiscreteAntecedent: ({$this->attribute->getName()}" . ($this->sign == 0 ? " == " : " != ")
              . "\"{$this->attribute->reprVal($this->value)}\") (maxInfoGain={$this->maxInfoGain}, "
              . "accuRate={$this->accuRate}, cover={$this->cover}, accu={$this->accu})";
        }
    }

    /**
     * Print a serialized representation of the antecedent
     */
    function serialize() : string
    {
        return "{$this->attribute->getName()} == '{$this->attribute->reprVal($this->getValue())}'";
    }

    /**
     * Print an array serialized representation of the discrete antecedent.
     * @return array The serialized representation of the discrete antecedent.
     */
    function serializeToArray() : array
    {
        return [
            'feature_id' => $this->getAttribute()->getIndex(),
            'feature' => $this->attribute->getName(),
            'operator' => '==',
            'value' => $this->attribute->reprVal($this->getValue()),
        ];
    }

    /**
     * Print a "json logic" serialized representation of the discrete antecedent.
     * @return array The serialized representation of the discrete antecedent.
     *
     * In the rule:
     *  { "and" : [
     *    {"<" : [ { "var" : "temp" }, 110 ]},
     *    {"==" : [ { "var" : "pie.filling" }, "apple" ] }
     *  ] };
     * $rules = [ "and" => [
     *  [ "<" => [ [ "var" => "temp" ], 110 ] ],
     *    [ "==" => [ [ "var" => "pie.filling" ], "apple" ] ]
     *    ] ];
     *
     * This will correspond to:
     *  {"==" : [ { "var" : "pie.filling" }, "apple" ] }
     *  [ "==" => [ [ "var" => "pie.filling" ], "apple" ] ]
     */
    function serializeToJsonLogic() : array
    {
      return [
        "==" => [
          "var" => $this->attribute->getName(),
          $this->attribute->reprVal($this->getValue())
        ]
      ];
    }

    /**
     * Recreates a discrete antecedent from an array.
     * @param array $antecedentArray The antecedent in form of array.
     * @param array $attributes The array of attributes, needed to re-create discrete antecedents.
     * @return DiscreteAntecedent The relative object of type antecedent.
     */
    public static function createFromArray(array $antecedentArray, ?array $attributes = null) : DiscreteAntecedent
    {
        $antecedent = null;
        if ($attributes != null) {
          $domain = null;
          foreach ($attributes as $attribute) {
            if ($attribute->getName() == $antecedentArray['feature']) {
              $attribute->setIndex($antecedentArray['feature_id']);
              $domain = $attribute->getDomain();
              $out_map = array_flip($domain);
              $antecedent = new DiscreteAntecedent($attribute);
              #print_r($out_map);
              $antecedent->setValue($out_map[$antecedentArray['value']]);
              return $antecedent;
              #break;
            }
          }
          if ($domain === null) {
            Utils::die_error("Couldn't retrieve domain for attribute " . $antecedentArray['name']
              . "when creating it from array." . PHP_EOL);
          }
          #$out_map = array_flip($domain);

          /*$attribute = new DiscreteAttribute($antecedentArray['feature'], 'parsed',
            [strval($domain[0]), strval($domain[1])]);
          $attribute->setIndex($antecedentArray['feature_id']);
          $antecedent = new DiscreteAntecedent($attribute);*/
          #print_r($out_map); #debug
          #$antecedent->setValue($out_map[$antecedentArray['value']]); # TODO apparently a problem here
          #return $antecedent;
        }
        else {
          Utils::die_error("Unexpected error." . PHP_EOL);
        }
    }
}