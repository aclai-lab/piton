<?php

namespace aclai\piton\DiscriminativeModels;

use Illuminate\Support\Facades\DB;
use aclai\piton\Antecedents\Antecedent;
use aclai\piton\Antecedents\ContinuousAntecedent;
use aclai\piton\Antecedents\DiscreteAntecedent;
use aclai\piton\Attributes\Attribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\ClassModel;
use aclai\piton\Facades\Utils;
use aclai\piton\Learners\Learner;
use aclai\piton\Instances\Instances;
use aclai\piton\ModelVersion;
use aclai\piton\Rules\ClassificationRule;
use aclai\piton\Rules\Rule;

class RuleBasedModel extends DiscriminativeModel
{

    /** The set of rules. */
    private $rules;

    /** The set of attributes which the rules refer to. */
    private $attributes;

    /**
     * Information about the rule based model. If it is normalized (for example, if it is the translation
     * of a decision tree), then the rules are mutual esclusive; if not, they are hierarchical, and therefore,
     * when printing the result of a prediction, the excluded rules aer to be printed too.
     */
    private $isNormalized;

    function __construct()
    {
        $this->rules = NULL;
        $this->attributes = NULL;
        $this->isNormalized = false;
    }

    /**
     * Get information about if a rule based model is normalized or not.
     */
    public function getIsNormalized() : bool
    {
        return $this->isNormalized;
    }

    /**
     * Sets if a rule based model is normalized or not.
     */
    public function setIsNormalized(bool $isNormalized) : void
    {
        $this->isNormalized = $isNormalized;
    }

    /* Train the model using an optimizer */
    function fit(Instances &$trainData, Learner &$learner)
    {
        $learner->teach($this, $trainData);
    }

    /**
     * Perform prediction onto some data.
     * @param Instances $testData
     * @param bool $useClassIndices
     * @param bool $returnPerRuleMeasures
     * @param array|float[]|null $rulesAffRilThresholds
     * @return array[]
     */
    function predict(Instances $testData, bool $useClassIndices = false, bool $returnPerRuleMeasures = false,
                     ?array $rulesAffRilThresholds = [0.2, 0.7]) : array
    {
        if (!(is_array($this->rules)))
            Utils::die_error("Can't use uninitialized rule-based model.");

        if (!(count($this->rules)))
            Utils::die_error("Can't use empty set of rules in rule-based model.");

        /* Extract the data in the same form that was seen during training */
        $allTestData = clone $testData;
        if ($this->attributes !== NULL) {
            // $allTestData->sortAttrsAs($this->attributes);
            #print_r($testData->getAttributes());
            $allTestData->sortAttrsAs($this->attributes, true);

            /* Predict */
            $classAttr = $allTestData->getClassAttribute();
            $predictions = [];

            /**
             * @var array
             * 
             * If the model is normalized, for each instance it stores the activated rule.
             * If the model isn't normalized, it stores all the rules until one is activated.
             * Therefore, the last rule is always the activated one.
             * To obtain the real full rule for not normalized models, do the interjection with
             * the negation of previous rules, or with the conjunction of their negated antecedents.
            */
            $storedRules = [];

            if ($rulesAffRilThresholds !== NULL) {
                $rule_types = [];
            }

            foreach ($allTestData->iterateInsts() as $instance_id => $inst) {
                $prediction = NULL;

                /* The rule which covered the instance and assigned the class value. */
                $storedRules[$instance_id] = [];

                #echo "Instance: " . $allTestData->inst_toString($instance_id, false) . PHP_EOL; #debug
                foreach ($this->rules as $r => $rule) {
                    if ($rule->covers($allTestData, $instance_id)) {
                        $idx = $rule->getConsequent();
                        if ($rulesAffRilThresholds !== NULL) {
                            $ruleMeasures = $rule->computeMeasures($allTestData);
                            $support = $ruleMeasures["support"];
                            $confidence = $ruleMeasures["confidence"];

                            $ril = $support > $rulesAffRilThresholds[0];
                            $aff = $confidence > $rulesAffRilThresholds[1];
                            $rule_type = ($ril ? "R" : "NR") . ($aff ? "A" : "NA");
                        }
                        $prediction = ($useClassIndices ? $idx : $classAttr->reprVal($idx));

                        $storedRules[$instance_id][] = $rule;

                        break;
                    }
                    else {
                        /**
                         * If the model isn't normalized, i store all the rules until I met the one
                         * which covers the instance.
                         */
                        if (!$this->getIsNormalized()) {
                            $storedRules[$instance_id][] = $rule;
                        }
                    }
                }
                $predictions[$instance_id]    = $prediction;
                if ($rule->covers($allTestData, $instance_id) && $rulesAffRilThresholds !== NULL) {
                    $rule_types[$instance_id] = $rule_type;
                }
            }

            if ($returnPerRuleMeasures) {
                $subTestData = clone $allTestData;
                $rules_measures = [];
                foreach ($this->getRules() as $r => $rule) {
                    $rules_measures[$r] = $this->computeRuleMeasures($rule, $allTestData, $subTestData);
                }
            }
        } else {
            Utils::die_error("RuleBasedModel needs attributesSet for predict().");
        }

        /* Count how many predictions resulted as null */
        $null_predictions = count(array_filter($predictions, function ($v) {
            return $v === NULL;
        }));
        if ($null_predictions != 0) {
            Utils::warn("Couldn't perform predictions for some instances (# null predictions: " .
                $null_predictions . "/" . $allTestData->numInstances() . ")");
        }

        $output = ["predictions" => $predictions];

        $output["storedRules"] = $storedRules;

        if ($returnPerRuleMeasures) {
            $output["rules_measures"] = $rules_measures;
        }
        if ($rulesAffRilThresholds !== NULL) {
            $output["rule_types"] = $rule_types;
        }
        return $output;
    }

    /* Save model to file */
    function save(string $path)
    {
        Utils::postfixisify($path, ".mod");

        $obj_repr = ["rules" => $this->rules, "attributes" => $this->attributes];
        file_put_contents($path, "RuleBasedModel\n" . serialize($obj_repr));
    }

    function load(string $path)
    {
        Utils::postfixisify($path, ".mod");
        $str = file_get_contents($path);
        $obj_str = strtok($str, "\n");
        $obj_str = strtok("\n");
        $obj_repr = unserialize($obj_str);
        $this->rules = $obj_repr["rules"];
        $this->attributes = $obj_repr["attributes"];
    }

    private function test(Instances $testData, bool $testRuleByRule = false,
                          ?array $rulesAffRilThesholds = [0.2, 0.7]) : array
    {
        $classAttr = $testData->getClassAttribute();
        $domain = $classAttr->getDomain();

        $ground_truths = [];

        foreach ($testData->iterateInsts() as $instance_id => $inst) {
            $ground_truths[$instance_id] = $testData->inst_classValue($instance_id);
        }

        $p = $this->predict($testData, true, $testRuleByRule, $rulesAffRilThesholds);
        $predictions = $p["predictions"];
        if ($testRuleByRule) {
            $rules_measures = $p["rules_measures"];
        }
        if ($rulesAffRilThesholds !== NULL) {
            $rule_types = $p["rule_types"];
        } else {
            $rule_types = NULL;
        }

        // For the binary case, one measure for YES class is enough
        if (count($domain) == 2) {
            if (Utils::startsWith($domain[0], "NO_"))
                $domain = [1 => $domain[1]];
            elseif (Utils::startsWith($domain[1], "NO_"))
                $domain = [0 => $domain[0]];
            else {
                $domain = [0 => $domain[0]];
            }
        }
        $measures = [];
        foreach ($domain as $classId => $className) {
            $measures[$classId] = $this->computeMeasures($ground_truths, $predictions, $classId, $rule_types);
        }

        $outArray = [
            "measures" => $measures,
            "ground_truths" => $ground_truths,
            "predictions" => $predictions,
            "rule_types" => $rule_types
        ];

        if ($testRuleByRule) {
            $outArray["rules_measures"] = $rules_measures;
            $outArray["totTest"] = $testData->numInstances();
        }

        return $outArray;
    }

    static function HTMLShowTestResults(array $testResults): string
    {
        $measures = NULL;
        $rules_measures = NULL;
        if (isset($testResults["measures"]) && isset($testResults["rules_measures"])) {
            $measures = $testResults["measures"];
            $rules_measures = $testResults["rules_measures"];
        } else {
            $measures = $testResults;
            $rules_measures = NULL;
        }

        $out = "";
        $out .= "<br><br>";

        if (isset($rules_measures)) {
            $out .= "Local measurements:<br>";
            $out .= "<table class='blueTable' style='border-collapse: collapse; ' border='1'>";
            $out .= "<thead>";
            $out .= "<tr>";
            $out .= "<th style='width:30px'>#</th>
<th>rule</th>
<th colspan='5' style='width:20%'>full rule</th>
<th colspan='5' style='width:20%'>sub rule (no model context)</th>
";
            $out .= "</tr>";
            $out .= "<tr>";
            $out .= "<th>#</th>
<th>rule</th>
<th colspan='2'>support</th>
<th>confidence</th>
<th>lift</th>
<th>conviction</th>
<th colspan='2'>support</th>
<th>confidence</th>
<th>lift</th>
<th>conviction</th>";
            $out .= "</tr>";
            $out .= "</thead>";
            $out .= "<tbody>";
            foreach ($rules_measures as $r => $rules_measure) {
                $out .= "<tr>";
                $out .= "<td>" . $r . "</td>";
                $out .= "<td>" . $rules_measure["rule"] . "</td>";
                $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["covered"] / $testResults["totTest"], 3)) . "</td><td>" . ($r == 0 ? "" : $rules_measure["covered"]) . "</td>";
                $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["confidence"], 3)) . "</td>";
                // $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["support"]*100, 2)) . "%</td>";
                // $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["confidence"]*100, 2)) . "%</td>";
                $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["lift"], 3)) . "</td>";
                $out .= "<td>" . ($r == 0 ? "" : number_format($rules_measure["conviction"], 3)) . "</td>";
                $out .= "<td>" . number_format($rules_measure["subSupport"], 3) . "</td><td>" . $rules_measure["subCovered"] . "</td>";
                $out .= "<td>" . number_format($rules_measure["subConfidence"], 3) . "</td>";
                // $out .= "<td>" . number_format($rules_measure["subSupport"]*100, 2) . "%</td>";
                // $out .= "<td>" . number_format($rules_measure["subConfidence"]*100, 2) . "%</td>";
                $out .= "<td>" . number_format($rules_measure["subLift"], 3) . "</td>";
                $out .= "<td>" . number_format($rules_measure["subConviction"], 3) . "</td>";
                $out .= "</tr>";
            }
            $out .= "</tbody>";
            $out .= "</table>";
        }

        if (isset($measures)) {
            $out .= "Global measurements:<br>";
            $out .= "<table class='blueTable' style='border-collapse: collapse; ' border='1'>";
            $out .= "<thead>";
            $out .= "<tr>";
            $out .= "<th>classId</th>
<th>positives</th>
<th>negatives</th>
<th>TP</th>
<th>TN</th>
<th>FP</th>
<th>FN</th>
<th>TP type I</th>
<th>TN type I</th>
<th>FP type I</th>
<th>FN type I</th>
<th>TP type II</th>
<th>TN type II</th>
<th>FP type II</th>
<th>FN type II</th>
<th>TP type III</th>
<th>TN type III</th>
<th>FP type III</th>
<th>FN type III</th>
<th>TP type IV</th>
<th>TN type IV</th>
<th>FP type IV</th>
<th>FN type IV</th>
<th>accuracy</th>
<th>sensitivity</th>
<th>specificity</th>
<th>PPV</th>
<th>NPV</th>";
            $out .= "</tr>";
            $out .= "</thead>";
            $out .= "<tbody>";
            foreach ($measures as $m => $measure) {
                $out .= "<tr>";
                $out .= "<td>" . $m . "</td>";
                $out .= "<td>" . ($measure["positives"]) . "</td>";
                $out .= "<td>" . ($measure["negatives"]) . "</td>";
                $out .= "<td>" . ($measure["TP"]) . "</td>";
                $out .= "<td>" . ($measure["TN"]) . "</td>";
                $out .= "<td>" . ($measure["FP"]) . "</td>";
                $out .= "<td>" . ($measure["FN"]) . "</td>";
                $out .= "<td>" . ($measure["TP_RA"]) . "</td>";
                $out .= "<td>" . ($measure["TP_RNA"]) . "</td>";
                $out .= "<td>" . ($measure["TP_NRA"]) . "</td>";
                $out .= "<td>" . ($measure["TP_NRNA"]) . "</td>";
                $out .= "<td>" . ($measure["TN_RA"]) . "</td>";
                $out .= "<td>" . ($measure["TN_RNA"]) . "</td>";
                $out .= "<td>" . ($measure["TN_NRA"]) . "</td>";
                $out .= "<td>" . ($measure["TN_NRNA"]) . "</td>";
                $out .= "<td>" . ($measure["FP_RA"]) . "</td>";
                $out .= "<td>" . ($measure["FP_RNA"]) . "</td>";
                $out .= "<td>" . ($measure["FP_NRA"]) . "</td>";
                $out .= "<td>" . ($measure["FP_NRNA"]) . "</td>";
                $out .= "<td>" . ($measure["FN_RA"]) . "</td>";
                $out .= "<td>" . ($measure["FN_RNA"]) . "</td>";
                $out .= "<td>" . ($measure["FN_NRA"]) . "</td>";
                $out .= "<td>" . ($measure["FN_NRNA"]) . "</td>";

                $out .= "<td>" . number_format($measure["accuracy"], 2) . "</td>";
                $out .= "<td>" . number_format($measure["sensitivity"], 2) . "</td>";
                $out .= "<td>" . number_format($measure["specificity"], 2) . "</td>";
                $out .= "<td>" . number_format($measure["PPV"], 2) . "</td>";
                $out .= "<td>" . number_format($measure["NPV"], 2) . "</td>";
                $out .= "</tr>";
            }
            $out .= "</tbody>";
            $out .= "</table>";

        }
        $out .= "<style>table.blueTable {
  border: 1px solid #1C6EA4;
  background-color: #EEEEEE;
  width: 100%;
  text-align: left;
  border-collapse: collapse;
}
table.blueTable td, table.blueTable th {
  border: 1px solid #AAAAAA;
  padding: 3px 2px;
}
table.blueTable tbody td {
  font-size: 13px;
}
table.blueTable tbody tr:nth-child(even) {
  background: #D0E4F5;
}
table.blueTable thead {
  background: #1C6EA4;
  background: -moz-linear-gradient(top, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  background: -webkit-linear-gradient(top, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  background: linear-gradient(to bottom, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  border-bottom: 2px solid #444444;
}
table.blueTable thead th {
  font-size: 15px;
  font-weight: bold;
  color: #FFFFFF;
  border-left: 2px solid #D0E4F5;
}
table.blueTable thead th:first-child {
  border-left: none;
}

table.blueTable tfoot {
  font-size: 14px;
  font-weight: bold;
  color: #FFFFFF;
  background: #D0E4F5;
  background: -moz-linear-gradient(top, #dcebf7 0%, #d4e6f6 66%, #D0E4F5 100%);
  background: -webkit-linear-gradient(top, #dcebf7 0%, #d4e6f6 66%, #D0E4F5 100%);
  background: linear-gradient(to bottom, #dcebf7 0%, #d4e6f6 66%, #D0E4F5 100%);
  border-top: 2px solid #444444;
}
table.blueTable tfoot td {
  font-size: 14px;
}
table.blueTable tfoot .links {
  text-align: right;
}
table.blueTable tfoot .links a{
  display: inline-block;
  background: #1C6EA4;
  color: #FFFFFF;
  padding: 2px 8px;
  border-radius: 5px;
}</style>";
        return $out;
    }

    function computeMeasures(array $ground_truths, array $predictions, int $classId, ?array $rule_types = NULL) : array
    {
        $positives = 0;
        $negatives = 0;
        $TP = 0;
        $TN = 0;
        $FP = 0;
        $FN = 0;

        $TP_rt = [];
        $TP_rt["RA"] = $TP_rt["RNA"] = $TP_rt["NRA"] = $TP_rt["NRNA"] = 0;
        $TN_rt = [];
        $TN_rt["RA"] = $TN_rt["RNA"] = $TN_rt["NRA"] = $TN_rt["NRNA"] = 0;
        $FP_rt = [];
        $FP_rt["RA"] = $FP_rt["RNA"] = $FP_rt["NRA"] = $FP_rt["NRNA"] = 0;
        $FN_rt = [];
        $FN_rt["RA"] = $FN_rt["RNA"] = $FN_rt["NRA"] = $FN_rt["NRNA"] = 0;

        foreach ($ground_truths as $instance_id => $val) {
            if ($ground_truths[$instance_id] == $classId) {
                $positives++;
                if ($predictions[$instance_id] === NULL) {
                    #Utils::die_error("TODO: how to evaluate with NULL predictions?");
                    #Utils::warn("NULL prediction".PHP_EOL);
                }
                #if ($ground_truths[$instance_id] == $predictions[$instance_id]) {
                else if ($ground_truths[$instance_id] == $predictions[$instance_id]) {
                    $TP++;
                    if ($rule_types !== NULL) {
                        $TP_rt[$rule_types[$instance_id]]++;
                    }
                } else {
                    $FN++;
                    if ($rule_types !== NULL) {
                        $FN_rt[$rule_types[$instance_id]]++;
                    }
                }
            } else {
                $negatives++;
                if ($predictions[$instance_id] === NULL) {
                    #Utils::die_error("TODO: how to evaluate with NULL predictions?");
                    #Utils::warn("NULL prediction".PHP_EOL);
                }
                #if ($ground_truths[$instance_id] == $predictions[$instance_id]) {
                else if ($ground_truths[$instance_id] == $predictions[$instance_id]) {
                    $TN++;
                    if ($rule_types !== NULL) {
                        $TN_rt[$rule_types[$instance_id]]++;
                    }
                } else {
                    $FP++;
                    if ($rule_types !== NULL) {
                        $FP_rt[$rule_types[$instance_id]]++;
                    }
                }
            }
        }
        $accuracy = Utils::safe_div(($TP + $TN), ($positives + $negatives));
        $sensitivity = Utils::safe_div($TP, $positives);
        $specificity = Utils::safe_div($TN, $negatives);
        $PPV = Utils::safe_div($TP, ($TP + $FP));
        $NPV = Utils::safe_div($TN, ($TN + $FN));

        return [
            "positives" => $positives,
            "negatives" => $negatives,

            "TP" => $TP, "TN" => $TN, "FP" => $FP, "FN" => $FN,

            "TP_typeRA" => $TP_rt["RA"], "TP_typeRNA" => $TP_rt["RNA"],
            "TP_typeNRA" => $TP_rt["NRA"], "TP_typeNRNA" => $TP_rt["NRNA"],
            "TN_typeRA" => $TN_rt["RA"], "TN_typeRNA" => $TN_rt["RNA"],
            "TN_typeNRA" => $TN_rt["NRA"], "TN_typeNRNA" => $TN_rt["NRNA"],
            "FP_typeRA" => $FP_rt["RA"], "FP_typeRNA" => $FP_rt["RNA"],
            "FP_typeNRA" => $FP_rt["NRA"], "FP_typeNRNA" => $FP_rt["NRNA"],
            "FN_typeRA" => $FN_rt["RA"], "FN_typeRNA" => $FN_rt["RNA"],
            "FN_typeNRA" => $FN_rt["NRA"], "FN_typeNRNA" => $FN_rt["NRNA"],

            "accuracy" => $accuracy,
            "sensitivity" => $sensitivity,
            "specificity" => $specificity,
            "PPV" => $PPV,
            "NPV" => $NPV];
    }

    /**
     * Save model to database.
    */
    function saveToDB(int $idModelVersion, int $recursionLevel = 0, ?string $fatherNode = null,
                      ?string $learnerName = null, ?Instances &$testData = NULL, ?Instances &$trainData = NULL,
                      ?array $rulesAffRilThresholds = [0.2, 0.7])
    {
        $allData = null;
        if ($testData !== NULL) {
            $testData = clone $testData;
            $testData->sortAttrsAs($this->attributes);
        }

        if ($trainData !== NULL && $testData !== NULL) {
            $allData = clone $trainData;
            $allData->sortAttrsAs($this->attributes);
            $allData->pushInstancesFrom($testData);
        }

        $classAttr = $this->getClassAttribute();

        /* Rules */
        /** Array containing all the rules of the rule based model. */
        $rulesArray = [];
        /** Array containing only positive rules in json logic format.  */
        /* Note: if the rbm isn't normalized, it also appends on each rule the negation of the previous rules. */
        $rulesJsonLogic = [];
        /** Array containing all the rules that hasn't been activated (both positive and negative). */
        /* Note: it is important to store the ruleId here. */
        $allRulesJsonLogic = [];
        /** The rule id. */
        $ruleId = 1;

        /* Testing values for the model I need to calculate here */
        $numRulesRA = 0;
        $numRulesRNA = 0;
        $numRulesNRA = 0;
        $numRulesNRNA = 0;
        $totNumRules = count($this->rules);

        /** For testing purposes on the rules considering their hierarchy, "a subset of testData". */
        $subTestData = clone $testData;

        /* Operations on the rules */
        foreach ($this->getRules() as $r => $rule) {
            $antecedents = [];
            $jsonLogicAntecedents["and"] = [];
            foreach ($rule->getAntecedents() as $antecedent) {
                $antecedents[] = $antecedent->serializeToArray();
                $jsonLogicAntecedents["and"][] = $antecedent->serializeToJsonLogic();
            }
            $rulesArray[$ruleId]['antecedents'] = $antecedents;
            $rulesArray[$ruleId]['consequent'] = strval($classAttr->reprVal($rule->getConsequent()));

            /* If it isn't normalized, I append the negation of the rules that haven't been activated. */
            if (!($this->getIsNormalized())) {
              /* Note: it is important to store the ruleId here in allRulesJsonLogic. */
              $allRulesJsonLogic[$ruleId] = $jsonLogicAntecedents;
              /* Only positive rules are stored in json logic field. */
              if (!Utils::startsWith(strval($classAttr->reprVal($rule->getConsequent())), "NO_")) {
                /* For each previous rule that hasn't been applied, pos. or neg., negate it. */
                foreach ($allRulesJsonLogic as $i => $jsonLogicRule) {
                  if ($i < $ruleId) {
                    $jsonLogicAntecedents["and"] = array_merge($jsonLogicAntecedents["and"],
                      $this->invertJsonLogicRule($jsonLogicRule)["and"]);
                  }
                }
              }
            }
            /* Only positive rules are stored in json logic field. */
            if (!Utils::startsWith(strval($classAttr->reprVal($rule->getConsequent())), "NO_")) {
              $rulesJsonLogic[] = $jsonLogicAntecedents;
            }

            /**
             * Single rule test results
             */
            $ruleMeasures["covered"] = null;
            $ruleMeasures["support"] = null;
            $ruleMeasures["confidence"] = null;
            $ruleMeasures["lift"] = null;
            $ruleMeasures["conviction"] = null;
            $ruleMeasures["globalCovered"] = null;
            $ruleMeasures["globalSupport"] = null;
            $ruleMeasures["globalConfidence"] = null;
            $ruleMeasures["globalLift"] = null;
            $ruleMeasures["globalConviction"] = null;

            if ($testData !== NULL) {
                $ruleMeasures = $this->computeRuleMeasures($rule, $testData, $subTestData);

                $ril = $ruleMeasures["support"] > $rulesAffRilThresholds[0];
                $aff = $ruleMeasures["confidence"] > $rulesAffRilThresholds[1];
                if ($ril && $aff) {
                    $numRulesRA++;
                } else if ($ril) {
                    $numRulesRNA++;
                } else if ($aff) {
                    $numRulesNRA++;
                } else {
                    $numRulesNRNA++;
                }
            }
            $rulesArray[$ruleId]['covered'] = $ruleMeasures["covered"];
            $rulesArray[$ruleId]['support'] = $ruleMeasures["support"];
            $rulesArray[$ruleId]['confidence'] = $ruleMeasures["confidence"];
            $rulesArray[$ruleId]['lift'] = $ruleMeasures["lift"];
            $rulesArray[$ruleId]['conviction'] = $ruleMeasures["conviction"];
            $rulesArray[$ruleId]['globalCovered'] = $ruleMeasures["globalCovered"];
            $rulesArray[$ruleId]['globalSupport'] = $ruleMeasures["globalSupport"];
            $rulesArray[$ruleId]['globalConfidence'] = $ruleMeasures["globalConfidence"];
            $rulesArray[$ruleId]['globalLift'] = $ruleMeasures["globalLift"];
            $rulesArray[$ruleId]['globalConviction'] = $ruleMeasures["globalConviction"];

            $ruleId++;
        }

        /** Running tests on the model */
        $valuesSql = array_fill(0, 29, null);

        if ($allData !== NULL) {
            $totMeasures = $this->test($allData, false, $rulesAffRilThresholds)["measures"];
        }

        if ($testData !== NULL) {
            $testMeasures = $this->test($testData, false, $rulesAffRilThresholds)["measures"];
        }

        if ($allData !== NULL || $testData !== NULL) {
            $classIds = [];
            if ($allData !== NULL) {
                $allColumns = ["positives" => "totPositives", "negatives" => "totNegatives"];
                $classIds = array_merge($classIds, array_keys($totMeasures));
            }
            if ($testData !== NULL) {
                $testColumns = ["positives" => "testPositives", "negatives" => "testNegatives",
                    "TP" => "TP", "TN" => "TN", "FP" => "FP", "FN" => "FN",
                    "TP_typeRA" => "TP_typeRA", "TP_typeRNA" => "TP_typeRNA",
                    "TP_typeNRA" => "TP_typeNRA", "TP_typeNRNA" => "TP_typeNRNA",
                    "TN_typeRA" => "TN_typeRA", "TN_typeRNA" => "TN_typeRNA",
                    "TN_typeNRA" => "TN_typeNRA", "TN_typeNRNA" => "TN_typeNRNA",
                    "FP_typeRA" => "FP_typeRA", "FP_typeRNA" => "FP_typeRNA",
                    "FP_typeNRA" => "FP_typeNRA", "FP_typeNRNA" => "FP_typeNRNA",
                    "FN_typeRA" => "FN_typeRA", "FN_typeRNA" => "FN_typeRNA",
                    "FN_typeNRA" => "FN_typeNRA", "FN_typeNRNA" => "FN_typeNRNA",
                    "accuracy" => "accuracy",
                    "sensitivity" => "sensitivity", "specificity" => "specificity", "PPV" => "PPV", "NPV" => "NPV"];
                $classIds = array_merge($classIds, array_keys($testMeasures));
            }
            $classIds = array_unique($classIds);

            foreach ($classIds as $classId) {
                $valuesSql = [];
                if ($allData !== NULL) {
                    $vals = $totMeasures[$classId];
                    $valuesArray = array_map(function ($col) use ($vals) {
                        return $vals[$col];
                    }, array_keys($allColumns));
                    $valuesSql = $valuesArray;
                }
                if ($testData !== NULL) {
                    $vals = $testMeasures[$classId];
                    $valuesArray = array_map(function ($col) use ($vals) {
                        return $vals[$col];
                    }, array_keys($testColumns));
                    $valuesSql = array_merge($valuesSql, $valuesArray);
                }
            }
        }

        /* Serialize attributes. */
        $serializedAttributes = [];
        $attributes = $this->getAttributes();
        array_shift($attributes);
          foreach ($attributes as $attribute) {
            $serializedAttributes[] = $attribute->serializeToArray();
        }

            $classAttrDomain = $classAttr->getDomain();
            #dd($classAttrDomain); #debug

            ClassModel::create([
            'id_model_version' => $idModelVersion,
            'recursion_level' => $recursionLevel,
            'father_node' => $fatherNode,
            'class' => json_encode([
              'name' => $classAttr->getName(),
              'domain' => [
                '0' => $classAttrDomain[0],
                '1' => $classAttrDomain[1]
              ],
            ]),
            'rules' => json_encode($rulesArray),
            'json_logic_rules' => json_encode($rulesJsonLogic),
            'attributes' => json_encode($serializedAttributes),
            'totNumRules' => $totNumRules,
            /** Test Results */
            'numRulesRA' => $numRulesRA,
            'numRulesRNA' => $numRulesRNA,
            'numRulesNRA' => $numRulesNRA,
            'numRulesNRNA' => $numRulesNRNA,

            'percRulesRA' => Utils::safe_div($numRulesRA, $totNumRules),
            'percRulesRNA' => Utils::safe_div($numRulesRNA, $totNumRules),
            'percRulesNRA' => Utils::safe_div($numRulesNRA, $totNumRules),
            'percRulesNRNA' => Utils::safe_div($numRulesNRNA, $totNumRules),
            'totPositives' => $valuesSql[0],
            'totNegatives' => $valuesSql[1],
            'totN' => ($valuesSql[0] + $valuesSql[1]),
            'totClassShare' => Utils::safe_div($valuesSql[0], ($valuesSql[0] + $valuesSql[1])),
            'testPositives' => $valuesSql[2],
            'testNegatives' => $valuesSql[3],
            'testN' => ($valuesSql[2] + $valuesSql[3]),
            'trainN' => (($valuesSql[0] + $valuesSql[1]) - ($valuesSql[2] + $valuesSql[3])),
            'TP' => $valuesSql[4],
            'TN' => $valuesSql[5],
            'FP' => $valuesSql[6],
            'FN' => $valuesSql[7],
            'TP_typeRA' => $valuesSql[8],
            'TP_typeRNA' => $valuesSql[9],
            'TP_typeNRA' => $valuesSql[10],
            'TP_typeNRNA' => $valuesSql[11],
            'TN_typeRA' => $valuesSql[12],
            'TN_typeRNA' => $valuesSql[13],
            'TN_typeNRA' => $valuesSql[14],
            'TN_typeNRNA' => $valuesSql[15],
            'FP_typeRA' => $valuesSql[16],
            'FP_typeRNA' => $valuesSql[17],
            'FP_typeNRA' => $valuesSql[18],
            'FP_typeNRNA' => $valuesSql[19],
            'FN_typeRA' => $valuesSql[20],
            'FN_typeRNA' => $valuesSql[21],
            'FN_typeNRA' => $valuesSql[22],
            'FN_typeNRNA' => $valuesSql[23],
            'accuracy' => $valuesSql[24],
            'sensitivity' => $valuesSql[25],
            'specificity' => $valuesSql[26],
            'PPV' => $valuesSql[27],
            'NPV' => $valuesSql[28],

            'test_date' => date("Y-m-d H:i:s", date_timestamp_get(date_create()))
        ]);
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        $this->reindexAttributes();
        return $this;
    }

    protected function reindexAttributes()
    {
        foreach ($this->attributes as $k => &$attribute) {
            $attribute->setIndex($k);
        }
    }

    function getClassAttribute()
    {
        // Note: assuming the class attribute is the first
        return $this->getAttributes()[0];
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function setRules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    public function resetRules()
    {
        return $this->setRules([]);
    }

    // TODO: here I'm asssumning a classification rule
    static function fromString(string $str, ?DiscreteAttribute $classAttr = NULL, ?array $attrs = NULL): RuleBasedModel
    {
        $rules_str_arr = array_filter(preg_split("/[\n\r]/", trim($str)), function ($v) {
            return $v !== "";
        });
        $rules = [];
        if ($classAttr === NULL) {
            $classAttr = new DiscreteAttribute("outputAttr", "parsedOutputAttr", []);
        }
        $outputMap = array_flip($classAttr->getDomain());
        if ($attrs === NULL) {  // I must create the attributes
            $attributes = [$classAttr];
            foreach ($rules_str_arr as $rule_str) {
                list($rule, $ruleAttributes) = ClassificationRule::fromString($rule_str, $outputMap);
                $rules[] = $rule;
                $attributes = array_merge($attributes, $ruleAttributes);
            }
        } else {  // I already have the attributes
            $attrs_map = [];
            $attributes = $attrs;
            foreach ($attrs as $attr) {
                if ($attr->getIndex() === NULL) {
                    Utils::die_error("The attribute $attr's index is not valid." . PHP_EOL);
                }
                $attrs_map[$attr->getName()] = $attr->getIndex();
            }
            foreach ($rules_str_arr as $rule_str) {
                list($rule, $ruleAttributes) = ClassificationRule::fromString($rule_str, $outputMap, $attrs_map, $attrs);
                $rules[] = $rule;
            }
        }

        $model = new RuleBasedModel();
        $model->setRules($rules);
        $model->setAttributes($attributes);
        return $model;
    }

    /* Print a textual representation of the rule */
    function __toString(): string
    {
        $rules = $this->getRules();
        $attrs = $this->attributes;
        $out_str = "    ";
        $out_str .= "RuleBasedModel with "
            . count($rules) . " rules"
            . ($attrs === NULL ? "" : " & " . count($attrs) . " attributes")
            . ": " . PHP_EOL . "    ";
        foreach ($rules as $x => $rule) {
            $out_str .= "R" . $x . ": " . $rule->toString() . PHP_EOL . "    ";
        }
        // foreach ($this->getAttributes() as $x => $attr) {
        //   $out_str .= $x . ": " . $attr->toString() . PHP_EOL . "    ";
        // }
        if ($attrs !== NULL && count($attrs)) {
            $x = 0;
            $out_str .= "A" . $x . ": " . $attrs[$x]->toString(false) . PHP_EOL . "    ";
            if (count($attrs) > 2) {
                $x = 1;
                $out_str .= "A" . $x . ": " . $attrs[$x]->toString(false) . PHP_EOL . "    ";
            }
            if (count($attrs) > 3) {
                $x = 2;
                $out_str .= "A" . $x . ": " . $attrs[$x]->toString(false) . PHP_EOL . "    ";
            }
            if (count($attrs) > 4) {
                $out_str .= "..." . PHP_EOL . "    ";
            }
            $x = count($attrs) - 1;
            $out_str .= "A" . $x . ": " . $attrs[$x]->toString(false) . PHP_EOL . "    ";
        }
        return $out_str;
    }

    /**
     * Compute measures on the single rules considering both their position in the hierarchy of rules and
     * their global value on all the testing dataset. It also updates the subTestData (considering the hierarchy).
     * @param Rule $rule The rule on which compute the measures
     * @param Instances $testData All instances of the testing dataset.
     * @param Instances $subTestData Remaining instances, measuring considering the hierarchy of the rules.
     * @return array The measures computed, such as covered, support, confidence, lift, conviction and
     * relative global measures.
     */
    protected function computeRuleMeasures(Rule $rule, Instances $testData, Instances &$subTestData) : array
    {
        /** Measures that consider the rules hierarchy in the model, testing on the remaining instances */
        $hierarchicalRuleMeasures = $rule->computeMeasures($subTestData, true);
        $subTestData = $hierarchicalRuleMeasures["filteredData"];
        /** Measures that don't consider the hierarchy, testing the rules on all instances */
        $globalRuleMeasures = $rule->computeMeasures($testData);

        $ruleMeasures["covered"] = $hierarchicalRuleMeasures["covered"];
        $ruleMeasures["support"] = $hierarchicalRuleMeasures["support"];
        $ruleMeasures["confidence"] = $hierarchicalRuleMeasures["confidence"];
        $ruleMeasures["lift"] = $hierarchicalRuleMeasures["lift"];
        $ruleMeasures["conviction"] = $hierarchicalRuleMeasures["conviction"];
        $ruleMeasures["globalCovered"] = $globalRuleMeasures["covered"];
        $ruleMeasures["globalSupport"] = $globalRuleMeasures["support"];
        $ruleMeasures["globalConfidence"] = $globalRuleMeasures["confidence"];
        $ruleMeasures["globalLift"] = $globalRuleMeasures["lift"];
        $ruleMeasures["globalConviction"] = $globalRuleMeasures["conviction"];

        return $ruleMeasures;
    }

    protected function invertJsonLogicRule(array $jsonLogicRule)
    {
      //echo "jsonLogicRule:" . PHP_EOL; #debug
      //print_r($jsonLogicRule); # debug
      $newJsonLogicRule["and"] = [];
      foreach ($jsonLogicRule["and"] as $i => $antecedent) {
        $sign = strval(array_keys($antecedent)[0]);
        $newSign = null;
        if ($sign == "<=")
          $newSign = ">";
        else if ($sign == ">=")
          $newSign = "<";
        else if ($sign == ">")
          $newSign = "<=";
        else if ($sign == "<")
          $newSign = ">=";
        else if ($sign == "==")
          $newSign = "!=";
        else if ($sign == "!=")
          $newSign = "==";
        else if ($sign != "and")
          dd($sign);
          //Utils::die_error("Unexpected error when inverting json logic rule. " . PHP_EOL);
        $newJsonLogicRule["and"][$i][$newSign] = $antecedent[$sign];
      }
      return $newJsonLogicRule;
    }

  /**
   * Re-creates an object of type RuleBasedModel from the database, given its id.
   * @param int $id The id of the rule based model.
   * @return RuleBasedModel The object RuleBasedModel associated to that id.
   */
    public static function createFromDB(int $id) : RuleBasedModel
    {
      /* Instantiation of a new instance of rule based model and of an array of rules. */
      $model = new RuleBasedModel();
      $rules = [];

      /* Reading classModel and relative modelVersion via foreign key from database. */
      $classModel = ClassModel::where('id', $id)->first();
      //$modelVersion = ModelVersion::where('id', $classModel->id_model_version)->first();

      /* From classModel I read information about the class and the associated rules. */
      $class = json_decode($classModel->class, true);
      $rulesArray = json_decode($classModel->rules, true);

      /* Re-creation of the attributes. */
      $classAttr = new DiscreteAttribute($class['name'], 'parsed', $class['domain']);
      $attributes = [$classAttr];
      /* From modelVersion I read information about the attributes. */
      $serializedAttributes = json_decode($classModel->attributes, true);
      if ($serializedAttributes === null) {
        Utils::die_error("Couldn't recreate model from db");
      }
      foreach ($serializedAttributes as $serializedAttribute) {
        $attributes[] = Attribute::createFromArray($serializedAttribute);
      }
      $model->setAttributes($attributes);
      $model->reindexAttributes();


      /* Re-creation of the rules. */
      foreach ($rulesArray as $ruleArray) {
        $antecedents = [];
        foreach ($ruleArray['antecedents'] as $antecedent) {
          if ($antecedent['operator'] === '==') {
            $antecedents[] = DiscreteAntecedent::createFromArray($antecedent, $attributes);
          } else {
            $antecedents[] = ContinuousAntecedent::createFromArray($antecedent);
          }
        }
        $out_map = array_flip($class['domain']);
        $consequent = intval($out_map[$ruleArray['consequent']]);
        $rule = new ClassificationRule($consequent);
        $rule->setAntecedents($antecedents);
        $rules[] = $rule;
      }

      /* Setting the model rules and attributes. */
      $model->setRules($rules);


      return $model;
    }
}