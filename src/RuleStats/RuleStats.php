<?php

namespace aclai\piton\RuleStats;

use aclai\piton\Attributes\ContinuousAttribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;
use aclai\piton\Rules\Rule;

/**
 * This class implements the statistics functions used in the propositional rule
 * learner, from the simpler ones like count of true/false positive/negatives,
 * filter data based on the ruleset, etc. to the more sophisticated ones such as
 * MDL calculation and rule variants generation for each rule in the ruleset.
 * <p>
 *
 * Obviously the statistics functions listed above need the specific data and
 * the specific ruleset, which are given in order to instantiate an object of
 * this class.
 * <p>
 *
 * @author Xin Xu (xx5@cs.waikato.ac.nz)
 * @version $Revision$
 */
class RuleStats
{

    /** The specific ruleset in question */
    private $ruleset;

    /** The data on which the stats calculation is based */
    private $data;

    /** The set of instances filtered by the ruleset */
    private $filtered;

    /**
     * The total number of possible conditions that could appear in a rule
     */
    private $numAllConds;

    /** The redundancy factor in theory description length */
    private static $REDUNDANCY_FACTOR = 0.5;

    /** The theory weight in the MDL calculation */
    private $MDL_THEORY_WEIGHT = 1.0;

    /** The simple stats of each rule */
    private $simpleStats;

    /** The class distributions predicted by each rule */
    private $distributions;

    /** Constructor */
    function __construct(?Instances $data = NULL, array $rules = [], ?int $numAllConds = NULL)
    {
        $this->data = $data;
        $this->setNumAllConds($numAllConds);

        $this->ruleset = NULL;
        $this->filtered = NULL;
        $this->simpleStats = NULL;
        $this->distributions = NULL;

        foreach ($rules as $rule) {
            $this->pushRule($rule);
        }
    }

    /**
     * Frees up memory after classifier has been built.
     */
    function cleanUp()
    {
        $this->data = NULL;
        $this->filtered = NULL;
    }

    /**
     * Get the simple stats of one rule, including 6 parameters: 0: coverage;
     * 1:uncoverage; 2: true positive; 3: true negatives; 4: false positives; 5:
     * false negatives
     *
     * @param index the index of the rule
     * @return the stats
     */
    function getSimpleStats(int $index): ?array
    {
        if (($this->simpleStats !== NULL)
            && ($index < $this->getRulesetSize())) {
            return $this->simpleStats[$index];
        }
        return NULL;
    }

    /**
     * Get the data after filtering the given rule
     *
     * @param int $index
     * @return array|null data covered and uncovered by the rule
     */
    function getFiltered(int $index): ?array
    {
        if (($this->filtered !== NULL)
            && ($index < $this->getRulesetSize())) {
            return $this->filtered[$index];
        }
        return NULL;
    }

    function getRulesetSize(): int
    {
        return count($this->getRuleset());
    }

    /**
     * Get the class distribution predicted by the rule in given position
     *
     * @param index the index of the rule
     * @return the data covered and uncovered by the rule
     */
    function getDistributions(int $index): ?int
    {
        if (($this->distributions !== NULL)
            && ($index < $this->getRulesetSize())) {
            return $this->distributions[$index];
        }
        return NULL;
    }

    /**
     * The description length of data given the parameters of the data based on
     * the ruleset.
     * <p>
     * Details see Quinlan: "MDL and categorical theories (Continued)",ML95
     * <p>
     *
     * @param expFPOverErr expected FP/(FP+FN)
     * @param cover coverage
     * @param uncover uncoverage
     * @param fp False Positive
     * @param fn False Negative
     * @return the description length
     */
    static function dataDL(float $expFPOverErr, float $cover, float $uncover, float $fp, float $fn): float
    {
        $totalBits = log($cover + $uncover + 1.0, 2); // how much data?
        $coverBits = 0.0;
        $uncoverBits = 0.0; // What's the error?
        $expErr = 0.0; // Expected FP or FN

        if ($cover > $uncover) {
            $expErr = $expFPOverErr * ($fp + $fn);
            $coverBits = self::subsetDL($cover, $fp, $expErr / $cover);
            $uncoverBits = ($uncover > 0.0) ? self::subsetDL($uncover, $fn, $fn / $uncover)
                : 0.0;
        } else {
            $expErr = (1.0 - $expFPOverErr) * ($fp + $fn);
            $coverBits = ($cover > 0.0) ? self::subsetDL($cover, $fp, $fp / $cover) : 0.0;
            $uncoverBits = self::subsetDL($uncover, $fn, $expErr / $uncover);
        }

        /*
         * echo "!!!cover: " . $cover . "|uncover" . $uncover .
         * "|coverBits: " . $coverBits . "|uncBits: ". $uncoverBits .
         * "|FPRate: " . $expFPOverErr . "|expErr: ". $expErr .
         * "|fp: " . $fp . "|fn: " . $fn . "|total: " . $totalBits;
         */
        return ($totalBits + $coverBits + $uncoverBits);
    }

    /**
     * Subset description length: <br>
     * S(t,k,p) = -k*log2(p)-(n-k)log2(1-p)
     *
     * Details see Quilan: "MDL and categorical theories (Continued)",ML95
     *
     * @param t the number of elements in a known set
     * @param k the number of elements in a subset
     * @param p the expected proportion of subset known by recipient
     * @return the subset description length
     */
    static function subsetDL(int $t, int $k, float $p): float
    {
        $rt = ($p > 0.0) ? (-$k * log($p, 2)) : 0.0;
        $rt -= ($t - $k) * log(1 - $p, 2);
        return $rt;
    }


    /**
     * Try to reduce the DL of the ruleset by testing removing the rules one by
     * one in reverse order and update all the stats
     *
     * @param expFPRate expected FP/(FP+FN), used in dataDL calculation
     * @param checkErr whether check if error rate >= 0.5
     */
    function reduceDL(float $expFPRate, bool $checkErr)
    {

        $needUpdate = false;
        $rulesetStat = array_fill(0, 6, 0.0); // 6 statistics parameters
        for ($j = 0; $j < $this->getRulesetSize(); $j++) {
            // Covered stats are cumulative
            $rulesetStat[0] += $this->simpleStats[$j][0];
            $rulesetStat[2] += $this->simpleStats[$j][2];
            $rulesetStat[4] += $this->simpleStats[$j][4];
            if ($j == $this->getRulesetSize() - 1) { // Last rule
                $rulesetStat[1] = $this->simpleStats[$j][1];
                $rulesetStat[3] = $this->simpleStats[$j][3];
                $rulesetStat[5] = $this->simpleStats[$j][5];
            }
        }

        // Potential
        for ($k = $this->getRulesetSize() - 1; $k >= 0; $k--) {

            $ruleStat = $this->simpleStats[$k];

            // rulesetStat updated
            $ifDeleted = self::potential($k, $expFPRate, $rulesetStat, $ruleStat,
                $checkErr);
            if (!is_nan($ifDeleted)) {
                /*
                 * echo "!!!deleted ("+k+"): save ".$ifDeleted
                 * ." | ".$rulesetStat[0] ." | ".$rulesetStat[1] ." | ".$rulesetStat[4]
                 * ." | ".$rulesetStat[5];
                 */

                if ($k == ($this->getRulesetSize() - 1)) {
                    $this->popRule();
                } else {
                    array_splice($this->ruleset, $k, 1);
                    $needUpdate = true;
                }
            }
        }

        if ($needUpdate) {
            $this->filtered = NULL;
            $this->simpleStats = NULL;
            $this->reCountData();
        }
    }

    /**
     * Filter the data according to the ruleset and compute the basic stats:
     * coverage/uncoverage, true/false positive/negatives of each rule
     */
    function reCountData()
    {
        if (($this->filtered !== NULL) || ($this->ruleset === NULL) || ($this->data === NULL)) {
            return;
        }

        $size = $this->getRulesetSize();
        $this->filtered = [];
        $this->simpleStats = [];
        $this->distributions = [];
        $data = $this->data;

        for ($i = 0; $i < $size; $i++) {
            $stats = array_fill(0, 6, 0.0); // 6 statistics parameters
            $classCounts = array_fill(0, $this->data->getClassAttribute()->numValues(), 0.0);
            $filtered = self::computeSimpleStats($this->ruleset[$i], $data, $stats, $classCounts);
            $this->filtered[] = $filtered;
            $this->simpleStats[] = $stats;
            $this->distributions[] = $classCounts;
            $data = $filtered[1]; // Data not covered
        }
    }

    /**
     * Count data from the position index in the ruleset assuming that given data
     * are not covered by the rules in position 0...(index-1), and the statistics
     * of these rules are provided.<br>
     * This procedure is typically useful when a temporary object of RuleStats is
     * constructed in order to efficiently calculate the relative DL of rule in
     * position index, thus all other stuff is not needed.
     *
     * @param index the given position
     * @param uncovered the data not covered by rules before index
     * @param prevRuleStats the provided stats of previous rules
     */
    function countData(int $index, Instances $uncovered, array $prevRuleStats)
    {
        if (($this->filtered !== NULL) || ($this->ruleset === NULL)) {
            return;
        }

        $size = $this->getRulesetSize();
        $this->filtered = [];
        $this->simpleStats = [];
        $this->distributions = [];
        $data = [Instances::createEmpty($this->data), $uncovered];


        for ($i = 0; $i < $index; $i++) {
            if ($i + 1 == $index) {
                $this->filtered[] = $data;
            } else {
                $this->filtered[] = NULL; // Stuff sth.
            }
            $this->simpleStats[] = $prevRuleStats[$i];
        }

        for ($j = $index; $j < $size; $j++) {
            $stats = array_fill(0, 6, 0.0); // 6 statistical measures
            $filtered = self::computeSimpleStats($this->ruleset[$j], $data[1], $stats, NULL);
            $this->filtered[] = $filtered;
            $this->simpleStats[] = $stats;
            $data = $filtered; // Data not covered
        }
    }

    // /**
    //  * Stratify the given data into the given number of bags based on the class
    //  * values. It differs from the <code>Instances.stratify(int fold)</code> that
    //  * before stratification it sorts the instances according to the class order
    //  * in the header file. It assumes no missing values in the class.
    //  *
    //  * @param data the given data
    //  * @param folds the given number of folds
    //  * @return the stratified instances
    //  */
    // static function stratify(Instances &$data, int $numFolds) : Instances {
    //   if (DEBUGMODE > 2) echo "RuleStats::stratify(&[data], numFolds=$numFolds)" . PHP_EOL;
    //   // if (DEBUGMODE & DEBUGMODE_DATA) echo "data : " . $data->toString() . PHP_EOL;
    //   if (!($data->getClassAttribute() instanceof DiscreteAttribute)) {
    //     return $data;
    //   }

    //   $data_out = Instances::createEmpty($data);
    //   $bagsByClasses = [];
    //   for ($i = 0; $i < $data->numClasses(); $i++) {
    //     $bagsByClasses[] = Instances::createEmpty($data);
    //   }

    //   // Sort by class
    //   foreach ($data->iterateInsts() as $instance_id => $inst) {
    //     $bagsByClasses[$data->inst_classValue($instance_id)]->pushInstanceFrom($data, $instance_id);
    //   }

    //   // Randomize each class
    //   // foreach ($bagsByClasses as &$bag) {
    //   //   $bag->randomize();
    //   // }

    //   for ($k = 0; $k < $numFolds; $k++) {
    //     $offset = $k;
    //     $i_bag = 0;
    //     while (true) {
    //       while ($offset >= $bagsByClasses[$i_bag]->numInstances()) {
    //         $offset -= $bagsByClasses[$i_bag]->numInstances();
    //         if (++$i_bag >= count($bagsByClasses)) {
    //           break 2;
    //         }
    //       }

    //       $data_out->pushInstanceFrom($bagsByClasses[$i_bag], $offset);
    //       $offset += $numFolds;
    //     }
    //   }
    //   // if (DEBUGMODE & DEBUGMODE_DATA) echo "data_out : " . $data_out->toString() . PHP_EOL;

    //   return $data_out;
    // }
    /**
     * Stratify & partition the given data into two bags of the given ratio based on the class
     * values. It differs from the <code>Instances.stratify(int fold)</code> that
     * before stratification it sorts the instances according to the class order
     * in the header file. It assumes no missing values in the class.
     *
     * @param data the given data
     * @param folds the given number of folds
     * @return the stratified instances
     */
    static function stratifiedBinPartition(Instances &$data, int $numFolds): array
    {
        if (!($data->getClassAttribute() instanceof DiscreteAttribute)) {
            Utils::die_error("stratifiedBinPartition(): Class attribute has to be a DiscreteAttribute. Got "
                             . $data->getClassAttribute());
        }

        $bagsByClasses = [];
        for ($i = 0; $i < $data->numClasses(); $i++) {
            $bagsByClasses[] = Instances::createEmpty($data);
        }

        // Sort by class
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            $bagsByClasses[$data->inst_classValue($instance_id)]->pushInstanceFrom($data, $instance_id);
        }

        // Randomize each class
        // foreach ($bagsByClasses as &$bag) {
        //   $bag->randomize();
        // }
        $growData = Instances::createEmpty($data);
        $pruneData = Instances::createEmpty($data);

        foreach ($bagsByClasses as $bag) {
            list($grow, $prune) = RuleStats::partition($bag, $numFolds);

            $growData->pushInstancesFrom($grow);
            $pruneData->pushInstancesFrom($prune);
        }

        return [$growData, $pruneData];
    }

    /**
     * Patition the data into 2, first of which has (numFolds-1)/numFolds of the
     * data and the second has 1/numFolds of the data
     *
     *
     * @param data the given data
     * @param numFolds the given number of folds
     * @return the partitioned instances
     */
    static function partition(Instances &$data, int $numFolds): array
    {
        return Instances::partition($data, ($numFolds - 1) / $numFolds);
    }

    /**
     * Compute the number of all possible conditions that could appear in a rule
     * of a given data. For nominal attributes, it's the number of values that
     * could appear; for numeric attributes, it's the number of values * 2, i.e.
     * <= and >= are counted as different possible conditions.
     *
     * @param Instances $data the given data
     * @return int number of all conditions of the data
     */
    static function numAllConditions(Instances &$data): int
    {
        $total = 0.0;
        foreach ($data->getAttributes(false) as $attr) {
            switch (true) {
                case $attr instanceof DiscreteAttribute:
                    $total += $attr->numValues();
                    break;
                case $attr instanceof ContinuousAttribute:
                    $total += 2.0 * $data->numDistinctValues($attr);
                    break;
                default:
                    Utils::die_error("Unknown type of attribute encountered in "
                        . "RuleStats::numAllConditions(...): " . get_class($attr));
                    break;
            }
        }
        return $total;
    }

    /**
     * Add a rule to the ruleset and update the stats
     *
     * @param Rule $rule the rule to be added
     */
    function pushRule(Rule $rule)
    {
        $data = ($this->filtered === NULL) ? $this->data : $this->filtered[array_key_last($this->filtered)][1];
        $stats = array_fill(0, 6, 0.0);
        $classCounts = array_fill(0, $this->data->getClassAttribute()->numValues(), 0.0);
        $filtered = self::computeSimpleStats($rule, $data, $stats, $classCounts);

        if ($this->ruleset === NULL) {
            $this->ruleset = [];
        }
        $this->ruleset[] = $rule;

        if ($this->filtered === NULL) {
            $this->filtered = [];
        }
        $this->filtered[] = $filtered;

        if ($this->simpleStats === NULL) {
            $this->simpleStats = [];
        }
        $this->simpleStats[] = $stats;

        if ($this->distributions === NULL) {
            $this->distributions = [];
        }
        $this->distributions[] = $classCounts;
    }

    /**
     * Remove the last rule in the ruleset as well as it's stats. It might be
     * useful when the last rule was added for testing purpose and then the test
     * failed
     */
    function popRule()
    {
        array_pop($this->ruleset);
        array_pop($this->filtered);
        array_pop($this->simpleStats);
        array_pop($this->distributions);
    }


    /**
     * Static utility function to count the data covered by the rules after the
     * given index in the given rules, and then remove them. It returns the data
     * not covered by the successive rules.
     *
     * @param data the data to be processed
     * @param rules the ruleset
     * @param index the given index
     * @return the data after processing
     */
    static function rmCoveredBySuccessives(Instances &$data, array $rules, int $index): Instances
    {
        $data_out = Instances::createEmpty($data);

        foreach ($data->iterateInsts() as $instance_id => $inst) {
            $covered = false;

            for ($j = $index + 1; $j < count($rules); $j++) {
                $rule = $rules[$j];
                if ($rule->covers($data, $instance_id)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $data_out->pushInstanceFrom($data, $instance_id);
            }
        }
        return $data_out;
    }

    /**
     * Find all the instances in the dataset covered/not covered by the rule in
     * given index, and the correponding simple statistics and predicted class
     * distributions are stored in the given double array, which can be obtained
     * by getSimpleStats() and getDistributions().<br>
     *
     * @param index the given index, assuming correct
     * @param data the dataset to be covered by the rule
     * @param stats the given double array to hold stats, side-effected
     * @param dist the given array to hold class distributions, side-effected if
     *          null, the distribution is not necessary
     * @return the instances covered and not covered by the rule
     */
    static private function computeSimpleStats(Rule $rule, Instances $data,
                                               array &$stats, array &$dist = NULL): array
    {

        $out_data = [Instances::createEmpty($data), Instances::createEmpty($data)];

        foreach ($data->iterateInsts() as $instance_id => $inst) {
            $weight = $data->inst_weight($instance_id);
            if ($rule->covers($data, $instance_id)) {
                $out_data[0]->pushInstanceFrom($data, $instance_id); // Covered by this rule
                $stats[0] += $weight; // Coverage
                if ($data->inst_classValue($instance_id) == $rule->getConsequent()) {
                    $stats[2] += $weight; // True positives
                } else {
                    $stats[4] += $weight; // False positives
                }
                if ($dist !== NULL) {
                    $dist[$data->inst_classValue($instance_id)] += $weight;
                }
            } else {
                $out_data[1]->pushInstanceFrom($data, $instance_id); // Not covered by this rule
                $stats[1] += $weight;
                if ($data->inst_classValue($instance_id) != $rule->getConsequent()) {
                    $stats[3] += $weight; // True negatives
                } else {
                    $stats[5] += $weight; // False negatives
                }
            }
        }

        return $out_data;
    }


    /**
     * The description length (DL) of the ruleset relative to if the rule in the
     * given position is deleted, which is obtained by: <br>
     * MDL if the rule exists - MDL if the rule does not exist <br>
     * Note the minimal possible DL of the ruleset is calculated(i.e. some other
     * rules may also be deleted) instead of the DL of the current ruleset.
     * <p>
     *
     * @param index the given position of the rule in question (assuming correct)
     * @param expFPRate expected FP/(FP+FN), used in dataDL calculation
     * @param checkErr whether check if error rate >= 0.5
     * @return the relative DL
     */
    function relativeDL(int $index, float $expFPRate, bool $checkErr): float
    {

        return ($this->minDataDLIfExists($index, $expFPRate, $checkErr)
            + $this->theoryDL($index)
            - $this->minDataDLIfDeleted($index, $expFPRate, $checkErr));
    }


    /**
     * The description length of the theory for a given rule. Computed as:<br>
     * 0.5* [||k||+ S(t, k, k/t)]<br>
     * where k is the number of antecedents of the rule; t is the total possible
     * antecedents that could appear in a rule; ||K|| is the universal prior for k
     * , log2*(k) and S(t,k,p) = -k*log2(p)-(n-k)log2(1-p) is the subset encoding
     * length.
     * <p>
     *
     * Details see Quilan: "MDL and categorical theories (Continued)",ML95
     *
     * @param int $index the index of the given rule (assuming correct)
     * @return float theory DL, weighted if weight != 1.0
     */
    function theoryDL(int $index): float
    {

        $k = $this->ruleset[$index]->getSize();

        if ($k == 0) {
            return 0.0;
        }

        $tdl = log($k, 2);
        if ($k > 1) {
            $tdl += 2.0 * log($tdl, 2); // of log2 star
        }
        $tdl += $this->subsetDL($this->numAllConds, $k, $k / $this->numAllConds);
        // System.out.println("!!!theory: "+MDL_THEORY_WEIGHT * REDUNDANCY_FACTOR *
        // tdl);
        return $this->MDL_THEORY_WEIGHT * self::$REDUNDANCY_FACTOR * $tdl;
    }

    /**
     * Compute the minimal data description length of the ruleset if the rule in
     * the given position is deleted.<br>
     * The min_data_DL_if_deleted = data_DL_if_deleted - potential
     *
     * @param index the index of the rule in question
     * @param expFPRate expected FP/(FP+FN), used in dataDL calculation
     * @param checkErr whether check if error rate >= 0.5
     * @return the minDataDL
     */
    function minDataDLIfDeleted(int $index, float $expFPRate, bool $checkErr): float
    {
        // System.out.println("!!!Enter without: ");
        $rulesetStat = array_fill(0, 6, 0.0); // Stats of ruleset if deleted
        $more = $this->getRulesetSize() - 1 - $index; // How many rules after?
        $indexPlus = []; // Their stats

        // 0...(index-1) are OK
        for ($j = 0; $j < $index; $j++) {
            // Covered stats are cumulative
            $rulesetStat[0] += $this->simpleStats[$j][0];
            $rulesetStat[2] += $this->simpleStats[$j][2];
            $rulesetStat[4] += $this->simpleStats[$j][4];
        }

        // Recount data from index+1
        $data = ($index == 0) ? $this->data : $this->filtered[$index - 1][1];
        // System.out.println("!!!without: " + $data->getSumOfWeights());

        for ($j = ($index + 1); $j < $this->getRulesetSize(); $j++) {
            $stats = array_fill(0, 6, 0.0);
            $split = self::computeSimpleStats($this->ruleset[$j], $data, $stats, $tmp = NULL);
            $indexPlus[] = $stats;
            $rulesetStat[0] += $stats[0];
            $rulesetStat[2] += $stats[2];
            $rulesetStat[4] += $stats[4];
            $data = $split[1];
        }
        // Uncovered stats are those of the last rule
        if ($more > 0) {
            $rulesetStat[1] = $indexPlus[array_key_last($indexPlus)][1];
            $rulesetStat[3] = $indexPlus[array_key_last($indexPlus)][3];
            $rulesetStat[5] = $indexPlus[array_key_last($indexPlus)][5];
        } else if ($index > 0) {
            $rulesetStat[1] = $this->simpleStats[$index - 1][1];
            $rulesetStat[3] = $this->simpleStats[$index - 1][3];
            $rulesetStat[5] = $this->simpleStats[$index - 1][5];
        } else { // Null coverage
            $rulesetStat[1] = $this->simpleStats[0][0] + $this->simpleStats[0][1];
            $rulesetStat[3] = $this->simpleStats[0][3] + $this->simpleStats[0][4];
            $rulesetStat[5] = $this->simpleStats[0][2] + $this->simpleStats[0][5];
        }

        // Potential
        $potential = 0;
        for ($k = $index + 1; $k < $this->getRulesetSize(); $k++) {
            $ruleStat = $this->getSimpleStats($k - $index - 1);
            $ifDeleted = $this->potential($k, $expFPRate, $rulesetStat, $ruleStat,
                $checkErr);
            if (!is_nan($ifDeleted)) {
                $potential += $ifDeleted;
            }
        }

        // Data DL of the ruleset without the rule
        // Note that ruleset stats has already been updated to reflect
        // deletion if any potential
        $dataDLWithout = self::dataDL($expFPRate, $rulesetStat[0], $rulesetStat[1],
            $rulesetStat[4], $rulesetStat[5]);
        // System.out.println("!!!without: "+dataDLWithout + " |potential: "+
        // potential);
        // Why subtract potential again? To reflect change of theory DL??
        return ($dataDLWithout - $potential);
    }

    /**
     * Compute the minimal data description length of the ruleset if the rule in
     * the given position is NOT deleted.<br>
     * The min_data_DL_if_n_deleted = data_DL_if_n_deleted - potential
     *
     * @param index the index of the rule in question
     * @param expFPRate expected FP/(FP+FN), used in dataDL calculation
     * @param checkErr whether check if error rate >= 0.5
     * @return the minDataDL
     */
    function minDataDLIfExists(int $index, float $expFPRate, bool $checkErr): float
    {
        // System.out.println("!!!Enter with: ");
        $rulesetStat = array_fill(0, 6, 0.0); // Stats of ruleset if rule exists
        for ($j = 0; $j < $this->getRulesetSize(); $j++) {
            // Covered stats are cumulative
            $rulesetStat[0] += $this->simpleStats[$j][0];
            $rulesetStat[2] += $this->simpleStats[$j][2];
            $rulesetStat[4] += $this->simpleStats[$j][4];
            if ($j == $this->getRulesetSize() - 1) { // Last rule
                $rulesetStat[1] = $this->simpleStats[$j][1];
                $rulesetStat[3] = $this->simpleStats[$j][3];
                $rulesetStat[5] = $this->simpleStats[$j][5];
            }
        }

        // Potential
        $potential = 0;
        for ($k = $index + 1; $k < $this->getRulesetSize(); $k++) {
            $ruleStat = $this->getSimpleStats($k);
            $ifDeleted = $this->potential($k, $expFPRate, $rulesetStat, $ruleStat,
                $checkErr);
            if (!is_nan($ifDeleted)) {
                $potential += $ifDeleted;
            }
        }

        // Data DL of the ruleset without the rule
        // Note that ruleset stats has already been updated to reflect deletion
        // if any potential
        $dataDLWith = self::dataDL($expFPRate, $rulesetStat[0], $rulesetStat[1],
            $rulesetStat[4], $rulesetStat[5]);
        // System.out.println("!!!with: "+dataDLWith + " |potential: "+
        // potential);
        return ($dataDLWith - $potential);
    }


    /**
     * Calculate the potential to decrease DL of the ruleset, i.e. the possible DL
     * that could be decreased by deleting the rule whose index and simple
     * statstics are given. If there's no potentials (i.e. smOrEq 0 && error rate
     * < 0.5), it returns NaN.
     * <p>
     *
     * The way this procedure does is copied from original RIPPER implementation
     * and is quite bizzare because it does not update the following rules' stats
     * recursively any more when testing each rule, which means it assumes after
     * deletion no data covered by the following rules (or regards the deleted
     * rule as the last rule). Reasonable assumption?
     * <p>
     *
     * @param index the index of the rule in m_Ruleset to be deleted
     * @param expFPOverErr expected FP/(FP+FN)
     * @param rulesetStat the simple statistics of the ruleset, updated if the
     *          rule should be deleted
     * @param ruleStat the simple statistics of the rule to be deleted
     * @param checkErr whether check if error rate >= 0.5
     * @return the potential DL that could be decreased
     */
    function potential(int $index, float $expFPOverErr, array $rulesetStat,
                       array $ruleStat, bool $checkErr): float
    {
        // System.out.println("!!!inside potential: ");
        // Restore the stats if deleted
        $pcov = $rulesetStat[0] - $ruleStat[0];
        $puncov = $rulesetStat[1] + $ruleStat[0];
        $pfp = $rulesetStat[4] - $ruleStat[4];
        $pfn = $rulesetStat[5] + $ruleStat[2];

        $dataDLWith = $this->dataDL($expFPOverErr, $rulesetStat[0], $rulesetStat[1],
            $rulesetStat[4], $rulesetStat[5]);
        $theoryDLWith = $this->theoryDL($index);
        $dataDLWithout = $this->dataDL($expFPOverErr, $pcov, $puncov, $pfp, $pfn);

        $potential = $dataDLWith + $theoryDLWith - $dataDLWithout;
        $err = $ruleStat[4] / $ruleStat[0];
        /*
         * System.out.println("!!!"+dataDLWith +" | "+ theoryDLWith + " | "
         * +dataDLWithout+"|"+ruleStat[4] + " / " + ruleStat[0]);
         */
        $overErr = $err >= 0.5;
        if (!$checkErr) {
            $overErr = false;
        }

        if ($potential >= 0.0 || $overErr) {
            // If deleted, update ruleset stats. Other stats do not matter
            $rulesetStat[0] = $pcov;
            $rulesetStat[1] = $puncov;
            $rulesetStat[4] = $pfp;
            $rulesetStat[5] = $pfn;
            return $potential;
        } else {
            return NAN;
        }
    }

    /* Print a textual representation of the rulestats */
    function __toString(): string
    {
        return $this->toString();
    }

    function toString(): string
    {
        $out_str = "RuleStats(size {$this->getRulesetSize()}){";

        $out_str .= "Data: " . $this->data->toString(true);
        $out_str .= ", \nnumAllConds: " . $this->numAllConds;
        $out_str .= "}";

        return $out_str;
    }

    public function getData(): Instances
    {
        return $this->data;
    }

    public function setNumAllConds(?int $numAllConds): self
    {
        $this->numAllConds = $numAllConds;
        return $this;
    }

    public function getRuleset(): array
    {
        return $this->ruleset;
    }

    public function setRuleset(array $ruleset): self
    {
        $this->ruleset = $ruleset;
        return $this;
    }
}


