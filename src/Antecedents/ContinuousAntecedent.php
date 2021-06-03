<?php

namespace aclai\piton\Antecedents;

use aclai\piton\Attributes\Attribute;
use aclai\piton\Attributes\ContinuousAttribute;
use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;

/**
 * An antecedent with continuous attribute
 */
class ContinuousAntecedent extends Antecedent
{

    /** The split point for this numeric antecedent */
    private $splitPoint;

    /**
     * Constructor
     */
    function __construct(Attribute $attribute)
    {
        if (!($attribute instanceof ContinuousAttribute))
            Utils::die_error("ContinuousAntecedent requires a ContinuousAttribute. Got "
                . get_class($attribute) . " instead.");
        parent::__construct($attribute);
        $this->splitPoint = NAN;
    }

    /**
     * Splits the data into two bags according to the
     * information gain of the numeric attribute value.
     * The infoGain for each bag is also calculated.
     *
     * @param Instances $data the data to be split
     * @param float $defAcRt the default accuracy rate for data
     * @param int $cla the class label to be predicted
     * @return array of data after split
     */
    function splitData(Instances &$data, float $defAcRt, int $cla): ?array
    {
        $split = 1; // Current split position
        $prev = 0; // Previous split position
        $finalSplit = $split; // Final split position
        $this->maxInfoGain = 0;
        $this->value = 0;

        $fstCover = 0;
        $sndCover = 0;
        $fstAccu = 0;
        $sndAccu = 0;

        $data->sortByAttr($this->attribute);
        $instance_ids = $data->getIds();

        // Total number of instances without missing value for att
        $total = $data->numInstances();
        $index = $this->attribute->getIndex();

        // Find the last instance without missing value
        $i = 0;
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            if ($data->inst_val($instance_id, $index) === NULL) {
                $total = $i;
                break;
            }

            $w = $data->inst_weight($instance_id);
            $sndCover += $w;
            if ($data->inst_classValue($instance_id) == $cla) {
                $sndAccu += $w;
            }
            $i++;
        }

        if ($total == 0) {
            return NULL; // Data all missing for the attribute
        }
        $this->splitPoint = $data->inst_val($instance_ids[$total - 1], $index);

        for (; $split <= $total; $split++) {
            if (($split == $total) ||
                ($data->inst_val($instance_ids[$split], $index) >
                    $data->inst_val($instance_ids[$prev], $index))) {

                for ($y = $prev; $y < $split; $y++) {
                    $w = $data->inst_weight($instance_ids[$y]);
                    $fstCover += $w;
                    if ($data->inst_classValue($instance_ids[$y]) == $cla) {
                        $fstAccu += $w; // First bag positive# ++
                    }
                }

                $fstAccuRate = ($fstAccu + 1.0) / ($fstCover + 1.0);
                $sndAccuRate = ($sndAccu + 1.0) / ($sndCover + 1.0);

                /* Which bag has higher information gain? */
                $isFirst;
                $fstInfoGain;
                $sndInfoGain;
                $accRate;
                $infoGain;
                $coverage;
                $accurate;

                $fstInfoGain = $fstAccu * (log($fstAccuRate, 2) - log($defAcRt, 2));

                $sndInfoGain = $sndAccu * (log($sndAccuRate, 2) - log($defAcRt, 2));

                if ($fstInfoGain > $sndInfoGain) {
                    $isFirst = true;
                    $infoGain = $fstInfoGain;
                    $accRate = $fstAccuRate;
                    $accurate = $fstAccu;
                    $coverage = $fstCover;
                } else {
                    $isFirst = false;
                    $infoGain = $sndInfoGain;
                    $accRate = $sndAccuRate;
                    $accurate = $sndAccu;
                    $coverage = $sndCover;
                }

                /* Check whether so far the max infoGain */
                if ($infoGain > $this->maxInfoGain) {
                    $this->value = ($isFirst) ? 0 : 1;
                    $this->maxInfoGain = $infoGain;
                    $this->accuRate = $accRate;
                    $this->cover = $coverage;
                    $this->accu = $accurate;
                    $this->splitPoint = $data->inst_val($instance_ids[$prev], $index);
                    $finalSplit = ($isFirst) ? $split : $prev;
                }

                for ($y = $prev; $y < $split; $y++) {
                    $w = $data->inst_weight($instance_ids[$y]);
                    $sndCover -= $w;
                    if ($data->inst_classValue($instance_ids[$y]) == $cla) {
                        $sndAccu -= $w; // Second bag positive# --
                    }
                }
                $prev = $split;
            }
        }

        /* Split the data */
        $splitData = [];
        $splitData[] = Instances::createFromSlice($data, 0, $finalSplit);
        $splitData[] = Instances::createFromSlice($data, $finalSplit, $total - $finalSplit);

        return $splitData;
    }

    /**
     * Whether the instance is covered by this antecedent
     *
     * @param data the set of instances
     * @param i the index of the instance in question
     * @return the boolean value indicating whether the instance is covered by
     *         this antecedent
     */
    function covers(Instances &$data, int $instance_id): bool
    {
        $isCover = true;
        $index = $this->attribute->getIndex();
        $val = $data->inst_val($instance_id, $index);

        if ($val !== NULL) {
            if ($this->value == 0) { // First bag
                if (!($val <= $this->splitPoint)) {
                    $isCover = false;
                }
            } else if ($this->value == 1) {
                if (!($val >= $this->splitPoint)) {
                    $isCover = false;
                }
            } else if ($this->value == 2) {
                if (!($val > $this->splitPoint)) {
                    $isCover = false;
                }
            } else if ($this->value == 3) {
                if (!($val < $this->splitPoint)) {
                    $isCover = false;
                }
            } else {
                $isCover = false;
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

    static function fromString(string $str, ?array $attrs_map = NULL, ?array $attributes = null) : ContinuousAntecedent
    {
        if (!preg_match("/^\s*\(?\s*(.*(?:\S))\s*(<=|>=|>|<)\s*(.*(?:[^\s\)]))\s*\)?\s*$/", $str, $w)) {
            Utils::die_error("Couldn't parse ContinuousAntecedent string \"$str\".");
        }
        $name = $w[1];
        $sign = $w[2];
        $reprvalue = $w[3];

        $attribute = new ContinuousAttribute($name, "parsed");
        if ($attrs_map !== NULL) {
            $attribute->setIndex($attrs_map[$name]);
        }
        $ant = ContinuousAntecedent::createFromAttribute($attribute);

        if ($sign == "<=")
            $ant->value = 0;
        else if ($sign == ">=")
            $ant->value = 1;
        else if ($sign == ">")
            $ant->value = 2;
        else if ($sign == "<")
            $ant->value = 3;
        else
            Utils::die_error("Invalid operator for continuous antecedent creation from string." . PHP_EOL);

        $ant->setSplitPoint($reprvalue);
        return $ant;
    }

  /**
   * Print a textual representation of the sign value (<=, >=, >, <).
   * @return string The textual representation of the sign value.
   */
    protected function getSignString() : string
    {
      $sign_str = null;
      if ($this->value == 0)
        $sign_str = " <= ";
      else if ($this->value == 1)
        $sign_str = " >= ";
      else if ($this->value == 2)
        $sign_str = " > ";
      else if ($this->value == 3)
        $sign_str = " < ";
      else
        Utils::die_error("Unexpected error when creating a continuous antecedent from string." . PHP_EOL);
      return $sign_str;
    }

    /**
     * Print a textual representation of the antecedent.
     */
    function toString(bool $short = false) : string
    {
        $sign_str = $this->getSignString();

        if ($short) {
            return "{$this->attribute->getName()}" . $sign_str .
                $this->splitPoint
                ;
        } else {
            return "ContinuousAntecedent: ({$this->attribute->getName()}" . $sign_str . $this->getSplitPoint()
                . ") (maxInfoGain={$this->maxInfoGain}, accuRate={$this->accuRate}, cover={$this->cover},"
                . "accu={$this->accu})";
        }
    }

    /**
     * Print a serialized representation of the antecedent.
     */
    function serialize() : string
    {
        return "{$this->attribute->getName()}" . (($this->value == 0) ? " <= " : " >= ") .$this->getSplitPoint();
    }

    /**
     * Gets information about the split point for the continuous antecedent.
     * @return float The split point for the continuous antecedent.
     */
    public function getSplitPoint() : float
    {
        return $this->splitPoint;
    }

    /**
     * Sets the split point value for the continuous antecedent.
     * @param float $splitPoint The split point for the continuous antecedent.
     * @return
     */
    protected function setSplitPoint(float $splitPoint) : void
    {
        $this->splitPoint = $splitPoint;
    }

    /**
     * Print an array serialized representation of the continuous antecedent.
     * @return array The serialized representation of the continuous antecedent.
     */
    public function serializeToArray() : array
    {
      $sign_str = $this->getSignString();

      return [
        'feature_id' => $this->getAttribute()->getIndex(),
        'feature' => $this->attribute->getName(),
        'operator' => $sign_str,
        'value' => $this->getsplitPoint()
      ];
    }

  /**
   * Print a "json logic" serialized representation of the continuous antecedent.
   * @return array The serialized representation of the continuous antecedent.
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
   *  {"<" : [ { "var" : "temp" }, 110 ]}
   *  [ "<" => [ [ "var" => "temp" ], 110 ] ],
   */
  public function serializeToJsonLogic() : array
  {
    $sign_str = null;
    if ($this->value == 0)
      $sign_str = "<=";
    else if ($this->value == 1)
      $sign_str = ">=";
    else if ($this->value == 2)
      $sign_str = ">";
    else if ($this->value == 3)
      $sign_str = "<";
    else
      Utils::die_error("Unexpected error when creating a continuous antecedent from string." . PHP_EOL);

    return [
      $sign_str => [
        "var" => $this->attribute->getName(),
        $this->getSplitPoint()
      ]
    ];
  }

    /**
     * Recreates a continuous antecedent from an array.
     * @param array $antecedentArray The antecedent in form of array.
     * @param array $attributes The array of attributes, needed to re-create discrete antecedents.
     * @return ContinuousAntecedent The relative object of type antecedent.
     */
    public static function createFromArray(array $antecedentArray, ?array $attributes = null) : ContinuousAntecedent
    {
        $attribute = new ContinuousAttribute($antecedentArray['feature'], 'parsed');
        $attribute->setIndex($antecedentArray['feature_id']);
        $antecedent = new ContinuousAntecedent($attribute);
        $sign_str = $antecedentArray['operator'];
        if ($sign_str == " <= ")
            $antecedent->setValue(0);
        else if ($sign_str == " >= ")
            $antecedent->setValue(1);
        else if ($sign_str == " > ")
            $antecedent->setValue(2);
        else if ($sign_str == " < ")
            $antecedent->setValue(3);
        else
            Utils::die_error("Unexpected error when creating a continuous antecedent from array." . PHP_EOL
            . $antecedentArray['feature'] . $sign_str . $antecedentArray['value'] . PHP_EOL);
        $antecedent->setSplitPoint($antecedentArray['value']);
        return $antecedent;
    }
}