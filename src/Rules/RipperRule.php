<?php

namespace aclai\piton\Rules;

use Exception;
use aclai\piton\Antecedents\Antecedent;
use aclai\piton\Antecedents\ContinuousAntecedent;
use aclai\piton\Instances\Instances;

/**
 * Rule class for RIPPER algorithm
 *
 * In this class, the Information Gain
 * (p*[log(p/t) - log(P/T)]) is used to select an antecedent and Reduced Error
 * Prunning (REP) with the metric of accuracy rate p/(p+n) or (TP+TN)/(P+N) is
 * used to prune the rule.
 *
 */
class RipperRule extends ClassificationRule
{

    /** Constructor */
    function __construct(int $consequent)
    {
        parent::__construct($consequent);
    }


    /**
     * Private function to compute default number of accurate instances in the
     * specified data for the consequent of the rule
     *
     * @param data the data in question
     * @return the default accuracy number
     */
    function computeDefAccu(Instances &$data): float
    {
        #if (DEBUGMODE > 2) echo "RipperRule->computeDefAccu(&[data])" . PHP_EOL;
        $defAccu = 0;
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            if ($data->inst_classValue($instance_id) == $this->consequent) {
                $defAccu += $data->inst_weight($instance_id);
            }
        }
        #if (DEBUGMODE > 2) echo "\$defAccu : $defAccu" . PHP_EOL;
        return $defAccu;
    }


    /**
     * Compute the best information gain for the specified antecedent
     *
     * @param instances the data based on which the infoGain is computed
     * @param defAcRt the default accuracy rate of data
     * @param antd the specific antecedent
     * @return the data covered by the antecedent
     */
    private function computeInfoGain(Instances &$data, float $defAcRt,
                                     Antecedent &$antd): ?Instances
    {

        /*
         * Split the data into bags. The information gain of each bag is also
         * calculated in this procedure
         */
        $splitData = $antd->splitData($data, $defAcRt, $this->consequent);

        /* Get the bag of data to be used for next antecedents */
        if ($splitData !== NULL) {
            return $splitData[$antd->getValue()];
        } else {
            return NULL;
        }
    }

    /**
     * Build one rule using the growing data
     *
     * @param data the growing data used to build the rule
     * @param minNo minimum weight allowed within the split
     */
    function grow(Instances &$growData, float $minNo)
    {
        #if (DEBUGMODE > 2) echo "RipperRule->grow(&[growData])" . PHP_EOL;
        #if (DEBUGMODE > 2) echo $this->toString() . PHP_EOL;

        if (!$this->hasConsequent()) {
            throw new Exception(" Consequent not set yet.");
        }

        $sumOfWeights = $growData->getSumOfWeights();
        if (!($sumOfWeights > 0.0)) {
            return;
        }

        /* Compute the default accurate rate of the growing data */
        $defAccu = $this->computeDefAccu($growData);
        $defAcRt = ($defAccu + 1.0) / ($sumOfWeights + 1.0);

        /* Keep the record of which attributes have already been used */
        $used = array_fill(0, $growData->numAttributes(), false);
        $numUnused = count($used);

        /* If there are already antecedents existing */
        foreach ($this->antecedents as $antecedent) {
            if (!($antecedent instanceof ContinuousAntecedent)) {
                $used[$antecedent->getAttribute()->getIndex()] = true;
                $numUnused--;
            }
        }

        while ($growData->numInstances() > 0
            && $numUnused > 0
            && $defAcRt < 1.0) {

            /* Build a list of antecedents */
            $maxInfoGain = 0.0;
            $maxAntd = NULL;
            $maxCoverData = NULL;

            /* Build one condition based on all attributes not used yet */
            foreach ($growData->getAttributes(false) as $attr) {

                // if (DEBUGMODE & DEBUGMODE_ALG) echo "\nAttribute '{$attr->toString()}'. (total weight = " . $growData->getSumOfWeights() . ")" . PHP_EOL;
                #if (DEBUGMODE & DEBUGMODE_ALG) {
                #    echo "\nOne condition: size = " . $growData->getSumOfWeights() . PHP_EOL;
                #}

                $antd = Antecedent::createFromAttribute($attr);

                if (!$used[$attr->getIndex()]) {
                    /*
                     * Compute the best information gain for each attribute, it's stored
                     * in the antecedent formed by this attribute. This procedure
                     * returns the data covered by the antecedent
                     */
                    $coverData = $this->computeInfoGain($growData, $defAcRt, $antd);
                    if ($coverData !== NULL) {
                        $infoGain = $antd->getMaxInfoGain();

                        if ($infoGain > $maxInfoGain) {
                            $maxAntd = $antd;
                            $maxCoverData = $coverData;
                            $maxInfoGain = $infoGain;
                        }
                        // if (DEBUGMODE & DEBUGMODE_ALG) {
                        //   echo "Test of {" . $antd->toString()
                        //     . "}:\n\tinfoGain = " . $infoGain . " | Accuracy = "
                        //     . $antd->getAccuRate()*100 . "% = " . $antd->getAccu() . "/"
                        //     . $antd->getCover() . " | def. accuracy: $defAcRt"
                        //     . "\n\tmaxInfoGain = " . $maxInfoGain . PHP_EOL;
                        // }
                        #if (DEBUGMODE & DEBUGMODE_ALG) {
                        #    "Test of \'" . $antd->toString(true)
                        #    . "\': infoGain = " . $infoGain . " | Accuracy = "
                        #    . $antd->getAccuRate() . "=" . $antd->getAccu() . "/"
                        #    . $antd->getCover() . " def. accuracy: " . $defAcRt;
                        #}
                    }
                }
            }

            if ($maxAntd === NULL) {
                break; // Cannot find antds
            }
            if ($maxAntd->getAccu() < $minNo) {
                break;// Too low coverage
            }

            /* Numeric attributes can be used more than once */
            if (!($maxAntd instanceof ContinuousAntecedent)) {
                $used[$maxAntd->getAttribute()->getIndex()] = true;
                $numUnused--;
            }

            $this->antecedents[] = $maxAntd;
            $growData = $maxCoverData; // Grow data size shrinks
            $defAcRt = $maxAntd->getAccuRate();
        }
        #if (DEBUGMODE > 2) echo $this->toString() . PHP_EOL;
    }


    /**
     * Prune all the possible final sequences of the rule using the pruning
     * data. The measure used to prune the rule is based on the given flag.
     *
     * @param pruneData the pruning data used to prune the rule
     * @param useWhole flag to indicate whether use the error rate of the whole
     *          pruning data instead of the data covered
     */
    function prune(Instances &$pruneData, bool $useWhole)
    {
        #if (DEBUGMODE > 2) echo "RipperRule->grow(&[growData])" . PHP_EOL;
        #if (DEBUGMODE > 2) echo "Rule: " . $this->toString() . PHP_EOL;
        #if (DEBUGMODE & DEBUGMODE_DATA) echo "Data: " . $pruneData->toString() . PHP_EOL;

        $sumOfWeights = $pruneData->getSumOfWeights();
        if (!($sumOfWeights > 0.0)) {
            return;
        }

        /* The default accurate # and rate on pruning data */
        $defAccu = $this->computeDefAccu($pruneData);

        #if (DEBUGMODE > 2) {
        #    echo "Pruning with " . $defAccu . " positive data out of "
        #        . $sumOfWeights . " instances" . PHP_EOL;
        #}

        $size = $this->getSize();
        if ($size == 0) {
            return; // Default rule before pruning
        }

        $worthRt = array_fill(0, $size, 0.0);
        $coverage = array_fill(0, $size, 0.0);
        $worthValue = array_fill(0, $size, 0.0);

        /* Calculate accuracy parameters for all the antecedents in this rule */
        #if (DEBUGMODE > 2) echo "Calculating accuracy parameters for all the antecedents..." . PHP_EOL;

        // True negative used if $useWhole
        $tn = 0.0;
        foreach ($this->antecedents as $x => $antd) {
            $newData = $pruneData;
            $pruneData = Instances::createEmpty($newData); // Make data empty

            foreach ($newData->iterateInsts() as $instance_id => $inst) {
                if ($antd->covers($newData, $instance_id)) { // Covered by this antecedent
                    $classValue = $newData->inst_classValue($instance_id);
                    $weight = $newData->inst_weight($instance_id);

                    $coverage[$x] += $weight;
                    $pruneData->pushInstanceFrom($newData, $instance_id); // Add to data for further pruning
                    if ($classValue == $this->consequent) {
                        $worthValue[$x] += $weight;
                    }
                } else if ($useWhole) { // Not covered
                    if ($classValue != $this->consequent) {
                        $tn += $weight;
                    }
                }
            }

            if ($useWhole) {
                $worthValue[$x] += $tn;
                $worthRt[$x] = $worthValue[$x] / $sumOfWeights;
            } else {
                $worthRt[$x] = ($worthValue[$x] + 1.0) / ($coverage[$x] + 2.0);
            }

            #if (DEBUGMODE > 2) echo $antd->toString() . ": coverage=" . $coverage[$x] . ", worthValue=" . $worthValue[$x] . PHP_EOL;
        }

        $maxValue = ($defAccu + 1.0) / ($sumOfWeights + 2.0);
        #if (DEBUGMODE > 2) echo "maxValue=$maxValue";
        $maxIndex = -1;
        for ($i = 0; $i < $size; $i++) {
            #if (DEBUGMODE > 2) {
            #    echo $i . " : (useAccuracy? " . !$useWhole . "): "
            #        . $worthRt[$i] . " ~= " . $worthValue[$i] . "/" . ($useWhole ? $sumOfWeights : $coverage[$i]) . PHP_EOL;
            #}
            // Prefer to the shorter rule
            if ($worthRt[$i] > $maxValue) {
                $maxValue = $worthRt[$i];
                $maxIndex = $i;
            }
        }

        /* Prune the antecedents according to the accuracy parameters */
        #if (DEBUGMODE > 2) var_dump("maxIndex " . $maxIndex . " " . count($this->antecedents));
        #if (DEBUGMODE > 2) var_dump($this->antecedents);
        array_splice($this->antecedents, $maxIndex + 1);
        #if (DEBUGMODE > 2) var_dump($this->antecedents);
    }

    /**
     * Removes redundant tests in the rule.
     *
     * @param data
     */
    function cleanUp(Instances &$data)
    {
        #if (DEBUGMODE > 2) echo "RipperRule->cleanUp(&[data])" . PHP_EOL;
        #if (DEBUGMODE > 2) echo "Rule: " . $this->toString() . PHP_EOL;
        #if (DEBUGMODE & DEBUGMODE_DATA) echo "Data: " . $data->toString() . PHP_EOL;

        $mins = array_fill(0, $data->numAttributes(), INF);
        $maxs = array_fill(0, $data->numAttributes(), -INF);

        for ($i = $this->getSize() - 1; $i >= 0; $i--) {
            #if (DEBUGMODE > 2) var_dump($this->antecedents);
            $j = $this->antecedents[$i]->getAttribute()->getIndex();
            if ($this->antecedents[$i] instanceof ContinuousAntecedent) {
                $splitPoint = $this->antecedents[$i]->getSplitPoint();
                if ($this->antecedents[$i]->getValue() == 0) {
                    if ($splitPoint < $mins[$j]) {
                        $mins[$j] = $splitPoint;
                    } else {
                        array_splice($this->antecedents, $i, 1);
                    }
                } else {
                    if ($splitPoint > $maxs[$j]) {
                        $maxs[$j] = $splitPoint;
                    } else {
                        array_splice($this->antecedents, $i, 1);
                    }
                }
            }
        }
    }
}