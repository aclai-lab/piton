<?php

namespace aclai\piton\Rules;

use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;
use aclai\piton\Attributes\Attribute;
use aclai\piton\Antecedents\Antecedent;

/**
 * A single classification rule that predicts a specified class value.
 *
 * A rule consists of antecedents "AND"-ed together and the consequent (class
 * value) for the classification.
 */
class ClassificationRule extends Rule
{

    /** Constructor */
    function __construct(int $consequent)
    {
        if (!($consequent >= 0))
            Utils::die_error("Negative consequent ($consequent) found when building ClassificationRule");
        parent::__construct($consequent);
    }

    public function setConsequent($consequent)
    {
        if (!(is_int($consequent) && $consequent >= 0))
            Utils::die_error("Invalid consequent ($consequent) found when building ClassificationRule");
        $this->consequent = $consequent;
        return $this;
    }

    function hasConsequent(): bool
    {
        return ($this->consequent !== NULL && $this->consequent !== -1);
    }

    function toString(Attribute $classAttr = NULL): string
    {
        $ants = [];
        if ($this->hasAntecedents()) {
            for ($j = 0; $j < $this->getSize(); $j++) {
                $ants[] = "(" . $this->antecedents[$j]->toString(true) . ")";
            }
        }

        if ($classAttr === NULL) {
            $out_str = join(" and ", $ants) . " => [{$this->consequent}]";
        } else {
            $out_str = join(" and ", $ants) . " => " . $classAttr->getName() . "=" . $classAttr->reprVal($this->consequent);
        }

        return $out_str;
    }

    public function computeMeasures(Instances &$data, bool $returnFilteredData = false): array
    {
        $totWeight = $data->getSumOfWeights();

        if ($data->isWeighted()) {
            Utils::die_error("The code must be expanded to test with weighted datasets" . PHP_EOL);
        }
        $coveredWeight = 0;
        $tpWeight = 0;
        $totConsWeight = 0;

        if ($returnFilteredData) {
            $filteredData = Instances::createEmpty($data);
        }
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            if ($this->covers($data, $instance_id)) {
                /** Covered by antecedents */
                $coveredWeight += $data->inst_weight($instance_id);
                if ($data->inst_classValue($instance_id) == $this->consequent) {
                    /** True positive for the rule */
                    $tpWeight += $data->inst_weight($instance_id);
                }
            } else if ($returnFilteredData) {
                $filteredData->pushInstanceFrom($data, $instance_id);
            }
            if ($data->inst_classValue($instance_id) == $this->consequent) {
                /** Same consequent */
                $totConsWeight += $data->inst_weight($instance_id);
            }
        }

        $covered = $coveredWeight;
        $support = Utils::safe_div($coveredWeight, $totWeight);
        $confidence = Utils::safe_div(Utils::safe_div($tpWeight, $totWeight), $support);
        $supportCons = Utils::safe_div($totConsWeight, $totWeight);
        $lift = Utils::safe_div($confidence, $supportCons);
        $conviction = Utils::safe_div((1 - $support), (1 - $confidence));

        $out_dict = ["covered" => $covered,
            "support" => $support,
            "confidence" => $confidence,
            "lift" => $lift,
            "conviction" => $conviction];
        if ($returnFilteredData) {
            $out_dict["filteredData"] = $filteredData;
        }
        return $out_dict;
    }

    static function fromString(string $str, ?array $outputMap = NULL, ?array $attrs_map = NULL, $attributes = null)
    {
        if (!preg_match("/^\s*()\s*(?:=>|:)\s*(.*(?:\S))\s*$/", $str, $w) &&
            !preg_match("/^\s*()\(\s*\)\s*(?:=>|:)\s*(.*(?:\S))\s*$/", $str, $w) &&
            !preg_match("/^\s*(.*(?:\S))\s*(?:=>|:)\s*(.*(?:\S))\s*$/", $str, $w)) {
            Utils::die_error("Couldn't parse ClassificationRule string \"$str\".");
        }

        $antecedents_str = $w[1];
        if (preg_match("/^\s*\[(.*(?:\S))\]\s*$/", $w[2], $w2)) {
            $consequent_str = $w2[1];
            echo "consequent_str: " . Utils::get_var_dump($consequent_str) . PHP_EOL;
            $consequent = intval($consequent_str);
        } else if (preg_match("/^\s*(.*)=(.*(?:\S))\s*\([\d\.]+\/[\d\.]+\)\s*$/", $w[2], $w2)) {
            $consequent = $outputMap[$w2[2]];
        } else if (preg_match("/^\s*(.*(?:\S))\s*\([\d\.]+\/[\d\.]+\)\s*$/", $w[2], $w2)) {
            $consequent = $outputMap[$w2[1]];
        } else if (preg_match("/^\s*(.*(?:\S))(\s*\([\d\.]+(\/[\d\.]+)?\))?\s*$/", $w[2], $w2)) {
            $consequent = $outputMap[$w2[1]];
        } else {
            Utils::die_error("Couldn't parse ClassificationRule conseguent string \"$str\".");
        }

        $ants_str_arr = [];
        if ($antecedents_str != "") {
            $ants_str_arr = preg_split("/\s*and\s*/i", $antecedents_str);
        }

        if ($attrs_map !== NULL) {  // Case $attrs_map is present (I'm passing the attributes, not creating them)
            $antecedents = [];
            foreach ($ants_str_arr as $ant) {
                $antecedents[] = Antecedent::fromString($ant, $attrs_map, $attributes);
            }
        } else {  // Case $attrs_map is missing, I'm creating the attributes
            $antecedents = array_map(function ($str) {
                return Antecedent::fromString($str);
            }, $ants_str_arr);
        }

        $rule = new \aclai\piton\Rules\ClassificationRule($consequent);
        $rule->setAntecedents($antecedents);
        $ruleAttributes = [];
        foreach ($antecedents as $a) {
            $ruleAttributes[] = $a->getAttribute();
        }
        return [$rule, $ruleAttributes];
    }
}
