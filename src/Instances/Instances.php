<?php

namespace aclai\piton\Instances;

use aclai\piton\Facades\Utils;
use aclai\piton\Attributes\Attribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\Attributes\ContinuousAttribute;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use mysqli;

/**
 * A set of data instances. Essentially, a table with metadata.
 * Each instance has values for the same number of attributes.
 * We assume that the attribute we want to predict is nominal
 * (i.e categorical). We also assume it to be placed in the first
 * position of the set of attributes.
 * Each instance is represented with an array, and has a weight (defaulted to 1).
 * The weight is stored at the end of each array.
 */
class Instances
{
    /** Metadata for the attributes */
    private $attributes;

    /** The data table itself */
    private $data;

    /** The sum of weights */
    private $sumOfWeights;

    public function __construct(array $attributes, array $data, $weights = 1)
    {
        /** Checks */
        if (is_array($weights)) {
            if (!(count($weights) == count($data)))
                Utils::die_error("Malformed data/weights pair encountered when building Instances(). "
                    . "Need exactly " . count($data) . " weights, but "
                    . count($weights) . " were found.");
        } else if (!(is_int($weights)) && $weights !== NULL) {
            Utils::die_error("Malformed weights encountered when building Instances(). "
                . "Weights argument can only be an integer value or an array (or NULL), but got \""
                . gettype($weights) . "\".");
        }

        $this->setAttributes($attributes);

        if (!($this->getClassAttribute() instanceof DiscreteAttribute))
            Utils::die_error("Instances' class attribute (here \"{$this->getClassAttribute()->toString()}\")"
                . " can only be nominal (i.e categorical).");

        $this->sumOfWeights = 0;

        if ($weights !== NULL) {
            /** If weights aren't included in data array */
            foreach ($data as $instance_id => $inst) {
                if (!(count($this->attributes) == count($inst)))
                    Utils::die_error("Malformed data encountered when building Instances(). "
                        . "Need exactly " . count($this->attributes) . " columns, but "
                        . count($inst) . " were found (on row/inst $instance_id).");
            }

            foreach ($data as $instance_id => &$inst) {
                if (is_array($weights)) {
                    $w = $weights[$instance_id];
                } else if (is_numeric($weights)) {
                    $w = $weights;
                }
                $inst[] = $w;
                $this->sumOfWeights += $w;
            }
        } else {
            /** If weights are included in data array */
            foreach ($data as $instance_id => $inst) {
                if (!(count($this->attributes) == count($inst) - 1))
                    Utils::die_error("Malformed data encountered when building Instances(). "
                        . "Need exactly " . count($this->attributes) . " columns, but "
                        . count($inst) . " were found (on row/inst $instance_id).");
            }

            foreach ($data as $instance_id => &$inst) {
                $w = $inst[array_key_last($inst)];
                $this->sumOfWeights += $w;
            }
        }

        $this->data = $data;
    }

    /**
     * Static utils
     */
    public static function createFromSlice(Instances &$insts, int $offset, int $length = NULL): Instances
    {
        $data = $insts->data;
        $preserve_keys = true;
        $newData = array_slice($data, $offset, $length, $preserve_keys);
        return new Instances($insts->attributes, $newData, NULL);
    }

    public static function createEmpty(Instances &$insts): Instances
    {
        return new Instances($insts->attributes, []);
    }

    public static function &partition(Instances &$data, float $firstRatio): array
    {
        $rt = [];

        $offset = round($data->numInstances() * $firstRatio);
        $rt[0] = Instances::createFromSlice($data, 0, $offset);
        $rt[1] = Instances::createFromSlice($data, $offset);

        return $rt;
    }

    /**
     * Read data from file, (dense) ARFF/Weka format
     */
    public static function createFromARFF(string $path, string $csv_delimiter = "'")
    {
        $f = fopen($path, "r");

        $ID_piton_is_present = false;
        /* Attributes */
        $attributes = [];
        $key = 0;
        while (!feof($f)) {
            $line = /* mb_strtolower */
                (fgets($f));
            if (Utils::startsWith(mb_strtolower($line), "@attribute")) {
                if (!Utils::startsWith(mb_strtolower($line), "@attribute '__id_piton__'")) {
                    $attributes[] = Attribute::createFromARFF($line, $csv_delimiter);
                    $key++;
                } else {
                    $ID_piton_is_present = true;
                    $id_key = $key;
                }
            }
            if (Utils::startsWith(mb_strtolower($line), "@data")) {
                break;
            }
        }
        $classAttr = array_pop($attributes);  // class Attribute must be in the last column
        array_unshift($attributes, $classAttr);

        /* Print the internal representation given the ARFF value read */
        $getVal = function ($ARFFVal, Attribute $attr) {
            $ARFFVal = trim($ARFFVal);
            if ($ARFFVal === "?") {
                return NULL;
            }
            $k = $attr->getKey($ARFFVal, true);
            return $k;
        };

        /* Data */
        $data = [];
        $weights = [];
        $i = 0;

        /** If the arff doesn't have an ID column, i create one, starting from 1 */
        if (!$ID_piton_is_present)
            $instance_id = 0;
        while (!feof($f) && $line = /* mb_strtolower */ (fgets($f))) {
            // echo $i;
            $row = str_getcsv($line, ",", $csv_delimiter);

            if ($ID_piton_is_present) {
                preg_match("/\s*(\d+)\s*/", $row[$id_key], $id);
                $instance_id = intval($id[1]);
                array_splice($row, $id_key, 1);
            } else {
                $instance_id += 1;
            }

            if (count($row) == count($attributes) + 1) {
                preg_match("/\s*\{\s*([\d\.]+)\s*\}\s*/", $row[array_key_last($row)], $w);

                $weights[$instance_id] = floatval($w[1]); # debug
                array_splice($row, array_key_last($row), 1);
            } else if (count($row) != count($attributes)) {
                Utils::die_error("ARFF data wrongfully encoded. Found data row [$i] with " .
                    count($row) . " values when there are " . count($attributes) .
                    " attributes.");
            }

            $classVal = array_pop($row);
            array_unshift($row, $classVal);

            $data[$instance_id] = array_map($getVal, $row, $attributes);
            $i++;
        }

        if (!count($weights)) {
            $weights = 1;
        }

        fclose($f);

        return new Instances($attributes, $data, $weights);
    }

    /**
     * Instances & attributes handling
     */
    public function numAttributes(): int
    {
        return count($this->attributes);
    }

    public function numInstances(): int
    {
        return count($this->data);
    }

    public function pushInstancesFrom(Instances $data, $safety_check = false)
    {
        if ($safety_check) {
            if ($data->sortAttrsAs($this->getAttributes(), true) == false) {
                Utils::die_error("Couldn't pushInstancesFrom, since attribute sets do not match. "
                    . $data->toString(true) . PHP_EOL . PHP_EOL
                    . $this->toString(true));
            }
        }
        foreach ($data->iterateRows() as $instance_id => $row) {
            $this->pushInstanceFrom($data, $instance_id);
        }
    }

    public function iterateRows()
    {
        foreach ($this->data as $instance_id => $row) {
            yield $instance_id => $row;
        }
    }

    public function iterateInsts()
    {
        foreach ($this->data as $instance_id => $row) {
            yield $instance_id => array_slice($row, 0, -1);
        }
    }

    public function iterateWeights()
    {
        $numAttrs = $this->numAttributes();
        foreach ($this->data as $instance_id => $row) {
            yield $instance_id => $row[$numAttrs];
        }
    }

    public function _getInstance(int $instance_id, bool $includeClassAttr): array
    {
        if ($includeClassAttr) {
            return array_slice($this->data[$instance_id], 0, -1);
        } else {
            return array_slice($this->data[$instance_id], 1, -1);
        }
    }

    public function getInstance(int $instance_id): array
    {
        return array_slice($this->data[$instance_id], 0, -1);
    }

    private function getRow(int $instance_id): array
    {
        return $this->data[$instance_id];
    }

    /**
     * Functions for the single data instance
     */

    public function inst_valueOfAttr(int $instance_id, Attribute $attr)
    {
        $j = $attr->getIndex();
        return $this->inst_val($instance_id, $j);
    }

    public function getRowInstance(array $row, bool $includeClassAttr): array
    {
        if ($includeClassAttr) {
            return array_slice($row, 0, -1);
        } else {
            return array_slice($row, 1, -1);
        }
    }

    public function inst_weight(int $instance_id): int
    {
        return $this->data[$instance_id][$this->numAttributes()];
    }

    function getRowWeight(array $row)
    {
        return $row[$this->numAttributes()];
    }

    public function reprClassVal($classVal)
    {
        return $this->getClassAttribute()->reprVal($classVal);
    }

    public function inst_classValue(int $instance_id): int
    {
        // Note: assuming the class attribute is the first
        return (int)$this->inst_val($instance_id, 0);
    }

    public function inst_setClassValue(int $instance_id, int $cl)
    {
        // Note: assuming the class attribute is the first
        $this->data[$instance_id][0] = $cl;
    }

    public function inst_val(int $instance_id, int $j)
    {
        return $this->data[$instance_id][$j];
    }

    public function getInstanceVal(array $inst, int $j)
    {
        return $inst[$j];
    }

    public function getRowVal(array $row, int $j)
    {
        return $row[$j];
    }

    public function pushColumn(array $column, Attribute $attribute)
    {
        foreach ($this->data as $instance_id => &$inst) {
            array_splice($inst, 1, 0, [$column[$instance_id]]);
        }
        array_splice($this->attributes, 1, 0, [$attribute]);
        $this->reindexAttributes();
    }

    private function pushRow(array $row, ?int $instance_id = NULL)
    {
        if (!(count($this->attributes) + 1 == count($row)))
            Utils::die_error("Malformed data encountered when pushing an instance to Instances() object. "
                . "Need exactly " . count($this->attributes) . " columns, but "
                . count($row) . " were found.");
        if ($instance_id === NULL) {
            Utils::die_error("pushInstance without an instance_id is not allowed anymore.");
            $this->data[] = $row;
        } else {
            if (isset($this->data[$instance_id])) {
                Utils::die_error("instance of id $instance_id is already in Instances. " . Utils::get_var_dump($row)
                                 . PHP_EOL . Utils::get_var_dump($this->data));
            }
            $this->data[$instance_id] = $row;
        }

        $this->sumOfWeights += $this->inst_weight($instance_id);
    }

    public function pushInstanceFrom(Instances $data, int $instance_id)
    {
        $this->pushRow($data->getRow($instance_id), $instance_id);
    }

    public function getWeights(): array
    {
        return Utils::array_column_assoc($this->data, $this->numAttributes());
    }

    public function getSumOfWeights(): int
    {
        return $this->sumOfWeights;
    }

    public function isWeighted(): bool
    {
        foreach ($this->iterateWeights() as $weight) {
            if ($weight != 1) {
                return true;
            }
        }
        return false;
    }

    public function getClassAttribute()
    {
        // Note: assuming the class attribute is the first
        return $this->getAttributes()[0];
    }

    public function getClassValues(): array
    {
        return array_map([$this, "inst_classValue"], $this->getIds());
    }

    /**
     * Remove instances with missing values for the output column
     */
    public function removeUselessInsts()
    {
        $instance_ids = $this->getIds();
        for ($x = $this->numInstances() - 1; $x >= 0; $x--) {
            if ($this->inst_classValue($instance_ids[$x]) === NULL) {
                $this->sumOfWeights -= $this->inst_weight($instance_ids[$x]);
                array_splice($this->data, $instance_ids[$x], 1);
            }
        }
    }

    protected function reindexAttributes()
    {
        foreach ($this->attributes as $k => &$attribute) {
            $attribute->setIndex($k);
        }
    }

    public function numClasses(): int
    {
        return $this->getClassAttribute()->numValues();
    }

    public function getAttributes(bool $includeClassAttr = true): array
    {
        // Note: assuming the class attribute is the first
        return $includeClassAttr ? $this->attributes : array_slice($this->attributes, 1);
    }

    public function _getAttributes(?array $attributesSubset = NULL): array
    {
        // Note: assuming the class attribute is the first
        return $attributesSubset === NULL ? $this->attributes : Utils::sub_array($this->attributes, $attributesSubset);
    }

    protected function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        $this->reindexAttributes();
        return $this;
    }

    public function getIds(): array
    {
        return array_keys($this->data);
    }

    /**
     * Sort the instances by the values they hold for an attribute
     */
    public function sortByAttr(Attribute $attr)
    {
        $j = $attr->getIndex();

        uasort($this->data, function ($a, $b) use ($j) {
            $A = $a[$j];
            $B = $b[$j];
            if ($A == $B) return 0;
            if ($B === NULL) return -1;
            if ($A === NULL) return 1;
            return ($A < $B) ? -1 : 1;
        });
    }

    /**
     * Resort attributes and data according to an extern attribute set
     */
    public function sortAttrsAs(array $newAttributes, bool $allowDataLoss = false, bool $allowDummyAttributes = false): bool
    {
        $sameAttributes = true;

        #echo "This attributes: " . PHP_EOL;
        #print_r($this->attributes);
        #echo "New attributes: " . PHP_EOL;
        #print_r($newAttributes);


        $copyMap = [];

        $attributes = $this->attributes;
        /* Find new attributes in the current list of attributes */
        foreach ($newAttributes as $i_attr => $newAttribute) {
            /* Look for the correspondent attribute */
            $oldAttribute = NULL;
            foreach ($attributes as $i => $attr) {
                if ($newAttribute->getName() == $attr->getName()) {
                    $oldAttribute = $attr;
                    break;
                }
            }
            if ($oldAttribute === NULL) {
                if (!$allowDummyAttributes) {
                    Utils::die_error("Couldn't find attribute '{$newAttribute->getName()}' in the current attribute list "
                                     . Utils::get_arr_dump($this->attributes) . " in Instances->sortAttrsAs");
                }
                // else {
                //     TODO
                // }
            }

            if (!$newAttribute->isEqualTo($oldAttribute)) {
                $sameAttributes = false;
            }

            if (!$newAttribute->isAtLeastAsExpressiveAs($oldAttribute)) {
                if (!$allowDataLoss) {
                    Utils::die_error("Found a target attribute that is not as expressive as the requested one. "
                        . "This may cause loss of data. "
                        . "\nnewAttribute: " . $newAttribute->toString(false)
                        . "\noldAttribute: " . $oldAttribute->toString(false));
                }
            }

            $copyMap[] = [$oldAttribute, $newAttribute];
        }

        $newData = [];
        foreach ($this->iterateRows() as $instance_id => $row) {
            $newRow = [];
            foreach ($copyMap as $i => $oldAndNewAttr) {
                $oldAttr = $oldAndNewAttr[0];
                $newAttr = $oldAndNewAttr[1];
                $new_i = $newAttr->getIndex();
                $oldVal = $this->getRowVal($row, $oldAttr->getIndex());
                if ($allowDataLoss) {
                    $newRow[$new_i] = $newAttr->reprValAs($oldAttr, $oldVal, true);
                } else {
                    $newRow[$new_i] = $newAttr->reprValAs($oldAttr, $oldVal);
                }
            }
            $newRow[] = $this->getRowWeight($row);

            $newData[$instance_id] = $newRow;
        }

        $this->data = $newData;
        $this->setAttributes($newAttributes);

        return $sameAttributes;
    }

    /**
     * Randomize the order of the instances
     */
    public function randomize()
    {
        Utils::shuffle_assoc($this->data);
    }

    /**
     * Sort the classes of the attribute to predict by frequency
     */
    public function sortClassesByCount()
    {
        $classes = $this->getClassAttribute()->getDomain();

        $class_counts = $this->getClassCounts();

        $indices = range(0, count($classes) - 1);

        array_multisort($class_counts, SORT_ASC, $classes, $indices);
        $class_map = array_flip($indices);

        foreach ($this->iterateRows() as $instance_id => $row) {
            $cl = $this->inst_classValue($instance_id);
            $this->inst_setClassValue($instance_id, $class_map[$cl]);
        }
        $this->getClassAttribute()->setDomain($classes);

        return $class_counts;
    }

    /**
     * Number of unique values appearing in the data, for an attribute.
     */
    public function numDistinctValues(Attribute $attr): int
    {
        $j = $attr->getIndex();
        $valPresence = [];
        foreach ($this->iterateInsts() as $instance_id => $inst) {
            $val = $this->getInstanceVal($inst, $j);
            if (!isset($valPresence[$val])) {
                $valPresence[$val] = 1;
            }
        }
        return count($valPresence);
    }

    public function checkCutOff(float $cutOffValue): bool
    {
        $class_counts = $this->getClassCounts();
        $total = array_sum($class_counts);
        foreach ($class_counts as $class => $counts) {
            if ((float)$counts / $total < $cutOffValue)
                return false; // $counts/$total;
        }
        return true;
    }

    public function getClassShare(int $classId): float
    {
        $class_counts = $this->getClassCounts();
        $total = array_sum($class_counts);
        return (float)$class_counts[$classId] / $total;
    }

    public function getClassCounts(): array
    {
        $classes = $this->getClassAttribute()->getDomain();
        $class_counts = array_fill(0, count($classes), 0);
        foreach ($this->iterateRows() as $instance_id => $row) {
            $val = $this->inst_classValue($instance_id);
            $class_counts[$val]++;
        }
        return $class_counts;
    }

    /* Perform prediction onto some data. */
    public function appendPredictions(DiscriminativeModel $model)
    {
        $new_col = $model->predict($this, true)["predictions"];
        $newAttr = clone $this->getClassAttribute();
        $newAttr->setName($newAttr->getName() . "_" // . $model->getName()
            . "predictions");
        $this->pushColumn($new_col, $newAttr);
    }

    /**
     * Save data to file, (dense) ARFF/Weka format
     */
    public function saveToARFF(string $path)
    {
        Utils::postfixisify($path, ".arff");
        $relName = $path;
        Utils::depostfixify($relName, ".arff");
        $f = fopen($path, "w");
        fwrite($f, "\n");
        fwrite($f, "@RELATION '" . addcslashes(basename($relName), "'") . "'\n\n");

        // Move output attribute from first to last position
        $attributes = $this->getAttributes();
        $classAttr = array_shift($attributes);
        array_push($attributes, $classAttr);
        $ID_piton_is_present = false;

        /* Attributes */
        fwrite($f, "@ATTRIBUTE '__ID_piton__' numeric");
        fwrite($f, "\n");
        foreach ($attributes as $attr) {
            if ($attr->getName() === '__ID_piton__') {
                $ID_piton_is_present = true;
            } else {
                fwrite($f, "@ATTRIBUTE '" . addcslashes($attr->getName(), "'")
                       . "' {$attr->getARFFType()}");
                fwrite($f, "\n");
            }
        }

        /* Print the ARFF representation of a value of the attribute */
        $getARFFRepr = function ($val, Attribute $attr) {
            return $val === NULL ? "?" : ($attr instanceof DiscreteAttribute ? "'"
                                          . addcslashes($attr->reprVal($val), "'")
                                          . "'" : $attr->reprVal($val));
        };

        /* Data */
        fwrite($f, "\n@DATA\n");
        foreach ($this->iterateRows() as $instance_id => $row) {
            $row_perm = array_map($getARFFRepr, $this->getInstance($instance_id), $this->getAttributes());
            $classVal = array_shift($row_perm);
            array_push($row_perm, $classVal);

            if ($ID_piton_is_present === false) {
                fwrite($f, "$instance_id, " . join(",", $row_perm) . ", {"
                       . $this->inst_weight($instance_id) . "}\n");
            } else {
                fwrite($f, "" . join(",", $row_perm) . ", {" . $this->inst_weight($instance_id) . "}\n");
            }
        }

        fclose($f);
    }

    /**
     * Save data to file, CSV format
     */
    public function saveToCSV(string $path, bool $includeClassAttr = true)
    {
        Utils::postfixisify($path, ".csv");
        $f = fopen($path, "w");

        /* Attributes */
        $attributes = $this->getAttributes($includeClassAttr);
        $header_row = ["ID"];
        foreach ($attributes as $attr) {
            $header_row[] = $attr->getName();
        }
        if ($this->isWeighted()) {
            $header_row[] = "WEIGHT";
        }
        fputcsv($f, $header_row);

        /* Print the CSV representation of a value of the attribute */
        $getCSVAttrRepr = function ($val, Attribute $attr) {
            return $val === NULL ? "" : $attr->reprVal($val);
        };

        $getCSVRepr = function ($val, Attribute $attr) {
            return $val === NULL ? "" : $val;
        };

        /* Data */

        if (!$this->isWeighted()) {
            foreach ($this->iterateRows() as $instance_id => $row) {
                fputcsv($f, array_merge([$instance_id], array_map($getCSVAttrRepr,
                        $this->getRowInstance($row, $includeClassAttr), $attributes)));
            }
        } else {
            foreach ($this->iterateRows() as $instance_id => $row) {
                fputcsv($f, array_merge([$instance_id], array_map($getCSVAttrRepr,
                        $this->getRowInstance($row, $includeClassAttr), $attributes), [$this->getRowWeight($row)]));
            }
        }

        /* Stats */

        if (!$this->isWeighted()) {
            foreach ($this->computeStats() as $statName => $statRow) {
                fputcsv($f, array_merge([$statName], array_map($getCSVRepr,
                        $this->getRowInstance($statRow, $includeClassAttr), $attributes)));
            }
        } else {
            foreach ($this->computeStats() as $statName => $statRow) {
                fputcsv($f, array_merge([$statName], array_map($getCSVRepr,
                        $this->getRowInstance($statRow, $includeClassAttr), $attributes), [$this->getRowWeight($row)]));
            }
        }

        fclose($f);
    }

    /**
     * Compute statistical indicators such as min, max, average and standard deviation
     *  for numerical attributes.
     */
    public function computeStats(): array
    {
        $attrs = $this->getAttributes();

        $fillNonNumWithNull = function (&$arr) use ($attrs) {
            foreach ($attrs as $i => $attr) {
                if (!($attr instanceof ContinuousAttribute)) {
                    $arr[$i] = NULL;
                }
            }
        };

        $row_min = [];
        $row_max = [];
        $fillNonNumWithNull($row_min);
        $fillNonNumWithNull($row_max);
        $row_sum = array_fill(0, count($attrs) + 1, 0);
        $fillNonNumWithNull($row_sum);
        $row_count = array_fill(0, count($attrs) + 1, 0);;
        $row_weight = array_fill(0, count($attrs) + 1, 0);;
        foreach ($this->iterateRows() as $instance_id => $row) {
            $weight = $this->getRowWeight($row);
            foreach ($row as $i => $val) {
                if ($val !== NULL) {

                    if ($row_sum[$i] !== NULL) {
                        if (!isset($row_min[$i])) {
                            $row_min[$i] = $val;
                        } else {
                            $row_min[$i] = min($row_min[$i], $val);
                        }

                        if (!isset($row_max[$i])) {
                            $row_max[$i] = $val;
                        } else {
                            $row_max[$i] = max($row_max[$i], $val);
                        }

                        $row_sum[$i] += $weight * $val;
                    }

                    $row_count[$i]++;
                    $row_weight[$i] += $weight;
                }
            }
        }

        ksort($row_min);
        ksort($row_max);

        $row_avg = array_fill(0, count($attrs) + 1, 0);
        $fillNonNumWithNull($row_avg);
        foreach ($row_sum as $i => $val) {
            if ($row_sum[$i] !== NULL) {
                $row_avg[$i] = Utils::safe_div($val, $row_weight[$i]);
            }
        }

        $row_stdev = array_fill(0, count($attrs) + 1, 0);
        $fillNonNumWithNull($row_stdev);
        foreach ($this->iterateRows() as $instance_id => $row) {
            foreach ($row as $i => $val) {
                if ($row_sum[$i] !== NULL && $row_avg[$i] !== NULL && $row_avg[$i] !== NAN && $val !== NULL) {
                    $row_stdev[$i] += pow(($row_avg[$i] - $val), 2);
                }
            }
        }

        foreach ($row_stdev as $i => $val) {
            if ($row_sum[$i] !== NULL) {
                $row_stdev[$i] = sqrt(Utils::safe_div($val, $row_weight[$i]));
            }
        }

        return ["MIN" => $row_min,
            "MAX" => $row_max,
            "AVG" => $row_avg,
            "STDEV" => $row_stdev,
            "COUNT" => $row_count,
            "WCOUNT" => $row_weight];
    }

    /**
     * Print a textual representation of the instances
     */
    public function inst_toString(int $instance_id, bool $short = true): string
    {
        $out_str = "";
        if (!$short) {
            $out_str .= "\n";
            $out_str .= str_repeat("======|=", 1 + $this->numAttributes() + 1) . "|\n";
            $out_str .= "";
            $index = 0;
            foreach ($this->getAttributes() as $att) {
                #$out_str .= substr($att->toString(), 0, 7) . "\t";
                $out_str .= $index++ . ": " . $att->toString(true). "\t";
            }
            $out_str .= "weight";
            $out_str .= "\n";
            $out_str .= str_repeat("======|=", 1 + $this->numAttributes() + 1) . "|\n";
        }
        $out_str .= str_pad($instance_id, 7, " ", STR_PAD_BOTH) . "\t";
        $index = 0;
        foreach ($this->getInstance($instance_id) as $val) {
            if ($val === NULL) {
                $x = "N/A";
            } else {
                $x = "{$val}";
            }
            $out_str .= $index++ . ": " . str_pad($x, 7, " ", STR_PAD_BOTH) . "\t";
        }
        $out_str .= "{" . $this->inst_weight($instance_id) . "}";
        if (!$short) {
            $out_str .= "\n";
            $out_str .= str_repeat("======|=", 1 + $this->numAttributes() + 1) . "|\n";
        }
        return $out_str;
    }

    /**
     * Print a textual representation of the instances
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(bool $short = false, ?array $attributesSubset = NULL): string
    {
        $attributes = $this->_getAttributes($attributesSubset);
        $out_str = "";
        $atts_str = [];
        foreach ($attributes as $i => $att) {
            $atts_str[] = "[$i]:" . $att->toString(false) . PHP_EOL;
        }
        if ($short) {
            $out_str .= "Instances{{$this->numInstances()} instances; "
                . ($this->numAttributes() - 1) . "+1 attributes (classAttribute: " . $this->getClassAttribute() . ")}";
        } else {
            $out_str .= "\n";
            $out_str .= "Instances{{$this->numInstances()} instances; "
                . ($this->numAttributes() - 1) . "+1 attributes [" . PHP_EOL . join(";", $atts_str) . "]}";
            $out_str .= "\n";
            $out_str .= str_repeat("======|=", 1 + count($attributes) + 1) . "|\n";
            $out_str .= "";
            $out_str .= str_pad("ID", 7, " ", STR_PAD_BOTH) . "\t";
            foreach ($attributes as $i => $att) {
                // $out_str .= substr($att->toString(), 0, 7) . "\t";
                $out_str .= str_pad("[$i]", 7, " ", STR_PAD_BOTH) . "\t";
            }
            $out_str .= "weight";
            $out_str .= "\n";
            $out_str .= str_repeat("======|=", 1 + count($attributes) + 1) . "|\n";
            foreach ($this->iterateRows() as $instance_id => $row) {
                $out_str .= str_pad($instance_id, 7, " ", STR_PAD_BOTH) . "\t";
                foreach ($this->getInstance($instance_id) as $j => $val) {
                    if ($attributesSubset !== NULL && !in_array($j, $attributesSubset)) {
                        continue;
                    }
                    if ($val === NULL) {
                        $x = "N/A";
                    } else {
                        $x = Utils::toString($val);
                    }
                    $out_str .= str_pad($x, 7, " ", STR_PAD_BOTH) . "\t";
                }
                $out_str .= "{" . $this->inst_weight($instance_id) . "}";
                $out_str .= "\n";
            }
            $out_str .= str_repeat("======|=", 1 + count($attributes) + 1) . "|\n";
        }
        return $out_str;
    }

    public function __clone()
    {
        $this->attributes = array_map("aclai\\piton\\Facades\\Utils::clone_object", $this->attributes);
    }

    /**
     * -----------------------------------------------------------------------------------------------------------------
     * Saves the current Instances in a table of name $tableName into the database.
     * If there is a table in the database with the same name of $tableName, it is overwritten.
     * -----------------------------------------------------------------------------------------------------------------
     * Warning: the fact that ID_piton is present can't be evaluated here, so instances are re-indexed.
     * -----------------------------------------------------------------------------------------------------------------
     * Note: a createFromDB method was also designed in the original project; however, working with
     * column comments is not contemplated here, and the experimented turnarounds revealed the side-effect
     * of reducing compatibility to mySQL only. Given the minimum utility of the method, it has been discarded here.
     * -----------------------------------------------------------------------------------------------------------------
     */
    public function saveToDB(string $tableName)
    {
        /**
         * Attributes
         *
         * The class attribute, which in the Instances structure is saved as the first attribute,
         * is moved at the end becoming the last attribute.
         */
        $attributes = $this->getAttributes();
        $classAttr = array_shift($attributes);
        array_push($attributes, $classAttr);

        Schema::connection('piton_connection')->dropIfExists($tableName);
        Schema::connection('piton_connection')->create($tableName, function (Blueprint $table)
                                                                         use (&$ID_piton_is_present, $attributes) {
            $table->increments('__ID_piton__');
            foreach ($attributes as $attr) {
                if ($attr instanceof DiscreteAttribute) {
                    /* Using string throws an ERROR:
                    SQLSTATE[42000]: Syntax error or access violation: 1118 Row size too large. */
                    // $table->string($attr->getName(), 256)->nullable();
                    $table->text($attr->getName())->nullable();
                } else if ($attr instanceof ContinuousAttribute) {
                    $table->decimal($attr->getName(), 20, 16)->nullable();
                } else {
                    Utils::die_error("Error: couldn't decide the type of attribute {$attr->getName()}." . PHP_EOL);
                }
            }
            $table->integer("weight")->default(1);

            $table->index('__ID_piton__');
        });

        /**
         * Prints a representation of a value of the attribute;
         * if the value is NULL, it prints the string "NULL";
         * if the value contains a comma or a quote, it is escaped.
         */
        $getRepresentation = function ($val, Attribute $attr) {
            return $val === NULL ? "NULL" : "'" . addcslashes($attr->reprVal($val), ",'") . "'";
        };

        /**
         * Data
         */
        $data = [];
        foreach ($this->iterateRows() as $instance_id => $row) {
            $rowPermutation = array_map($getRepresentation, $this->getInstance($instance_id), $this->getAttributes());
            $classVal = array_shift($rowPermutation);
            array_push($rowPermutation, $classVal);
            $data[$instance_id]['__ID_piton__'] = $instance_id;
            foreach ($attributes as $attribute_id => $attribute) {
                $rowPermutation[$attribute_id] === "NULL" ?
                    $data[$instance_id][$attribute->getName()] = null :
                    $data[$instance_id][$attribute->getName()] = trim($rowPermutation[$attribute_id], "'");
            }
            $data[$instance_id]['weight'] = $this->inst_weight($instance_id);
        }
        // DB::connection('piton_connection')->table($tableName)->insert($data);
        /**
         * Note: the previous line could throw the following
         *  MySQL General error: 1390 Prepared statement contains too many placeholders
         * With this turnaround, I don't insert all the records at once, but divide it into more inserts.
         * Source: https://stackoverflow.com/questions/18100782/import-of-50k-records-in-mysql-gives-general-error-1390-prepared-statement-con
         */
        foreach (array_chunk($data,100) as $t)
        {
            DB::connection('piton_connection')->table($tableName)->insert($t);
        }
    }
}
