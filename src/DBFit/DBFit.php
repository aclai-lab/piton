<?php

namespace aclai\piton\DBFit;

/*
 * This class can be used to learn intelligent classifier models from a MySQL database.
 *
 * The class requires information on:
 * - how to perform SQL queries;
 * - how to transform SQL columns to tabular attributes;
 * - how to split the data into training/testing subsets;
 * - how to decide whether a given dataset is valid (e.g. not worth if dataset is unbalanced);
 * - what kind of classifier models are to be trained (for now, rule-based models only);
 *
 * Only binary classification is contemplated here: a multi-label multi-class classification problem
 *  with N classes is split into N binary classification problem.
 *
 * One key feature of this class is the ability to handle an hierarchy of problems,
 *  as opposed to single problems: multi-label multi-class classification problems
 *  are first converted to binary classification problems, and whenever the portion of dataset
 *  associated with the *positive* label is good (e.g. big and balanced enough),
 *  this new data can be used to train a classification model for a secondary attribute.
 * This process can be repeated ad libitum, so that in general, a hierarchy of problems
 *  and classifiers can be considered. In order to do this, the indications for the SQL queries
 *  must be organized by levels. In general, it is assumed that the dataset for a sub-problem i
 *  of a problem j is very similar to that of problem j, and it only differ by additional
 *  join operations.
 *
 * A few features worthy of note:
 * - Possibility to set an identifier column, and to perform the prediction on an existing SQL row;
 * - The datasets and trained models are saved into different formats (as files and/or SQL tables)
 * - Handles different types of attributes:
 *   - numerical
 *   - categorical (finite domain)
 *   - dates
 *   - strings
 *
 */

use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use aclai\piton\Attributes\ContinuousAttribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\ClassModel;
use aclai\piton\DiscriminativeModels\DiscriminativeModel;
use aclai\piton\DiscriminativeModels\RuleBasedModel;
use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;
use aclai\piton\Learners\Learner;
use aclai\piton\ModelVersion;
use aclai\piton\Problem;
use aclai\piton\RuleStats\RuleStats;
use aclai\piton\Attributes\Attribute;

class DBFit
{
    /*
      The database tables where the input columns are (array of table-terms, one for each table)

      *

      For each table, the name must be specified. The name alone is sufficient for
      the first specified table, so the first term can be the name in the form of a string (e.g. "patient"). For the remaining tables, join criteria can be specified, by means of 'joinClauses' and 'joinType'.
      If one wants to specify these parameters, then the table-term should be an array [tableName, joinClauses=[], joinType="INNER JOIN"].
      joinClauses is a list of 'MySQL constraint strings' such as "patent.ID = report.patientID", used in the JOIN operation. If a single constraint is desired, then joinClauses can also simply be the string represeting the constraint (as compared to the array containing the single constraint).
      The join type, defaulted to "INNER JOIN", is the MySQL type of join.
    */
    private $inputTables;

    /*
      Input columns. (array of inputColumn-terms, one for each column)

      *

      For each input column, the name must be specified, and it makes up sufficient information. As such, a term can simply be the name of the input column (e.g. "Age").
      When dealing with more than one MySQL table, it is mandatory that each column name references the table it belongs using the dot notation, as in "patient.Age".
      Additional parameters can be supplied for managing the column pre-processing.
      The generic form for a column-term is [columnName, treatment=NULL, attrName=columnName].
      - A "treatment" for a column determines how to derive an attribute from the
         column data. For example, "YearsSince" translates each value of
         a date/datetime column into an attribute value representing the number of
         years since the date. "DaysSince", "MonthsSince" are also available.
        "DaysSince" is the default treatment for dates/datetimes
        "ForceCategorical" forces the corresponding attribute to be nominal. If the column is an enum fields, the enum domain will be inherited, otherwise a domain will be built using the unique values found in the column.
        "ForceCategoricalBinary" takes one step further and translates the nominal attribute to become a set of k binary attributes, with k the original number of classes.
        (TODO generalize: "ForceBinary" generates k binary attributes from a generic nominal attribute of domain size k.)
        For text fields, "BinaryBagOfWords" can be used to generate k binary attributes, each representing the presence of one of the most frequent words.
        When a treatment is desired, the column-term must be an array
         [columnName, treatment=NULL] (e.g. ["BirthDate", "ForceCategorical"])
        Treatments may require/allow arguments, and these can be supplied through
         an array instead of a simple string. For example, "BinaryBagOfWords"
         requires a parameter k, representing the size of the dictionary.
         As an example, the following term requires BinaryBagOfWords with k=10:
         ["Description", ["BinaryBagOfWords", 10]].
        The treatment for input column is defaulted to NULL, which implies no such pre-processing step. Note that the module complains whenever it encounter
          text fields with no treatment specified. When dealing with many text fields, consider setting the default option "textTreatment" via ->setDefaultOption(). For example, ->setDefaultOption("textTreatment", ["BinaryBagOfWords", 10]).
      - The name of the attribute derived from the column can also be specified:
         for instance, ["BirthDate", "YearsSince", "Age"] creates an "Age" attribute
         by processing a "BirthDate" sql column.
    */
    private $inputColumns;

    /*
      Columns that are to be treated as output.
        (array of outputColumn-terms, one for each column)

      *

      This module supports hierarchical models. This means that a unique DBFit object can be used to train different models at predicting different output columns that are inter-related, with different sets of data.
      In the simplest case, the user specifies a unique output column, from which M attributes are generated. Then, M models are generated, each predicting an attribute value, which is then used for deriving a value for the output column.
      One can then take this a step further and, for each of the M models, independently train K models, where K is the number of output classes of the attribute, using data that is only relevant to that given output class and model. Generally, this hierarchical training and prediction structur takes the form of a tree with depth O (number of "nested" outputColumns).
      Having said this, the outputColumns array specifies one column per each depth of the recursion tree.

      outputColumn-terms are very similar to inputColumn-terms (see documentation for $this->inputColumns a few lines above), with a few major differences:
      - The default treatment is "ForceCategorical": note, in fact, that output columns must generate categorical attributes (this module only supports classification and not regression). Also consider using "ForceCategoricalBinary", which breaks a nominal class attribute into k disjoint binary attributes.
      - Each output column can be derived from join operations (thus it can also belong to inputTables that are not in $this->inputTables).
      Additional join criteria can be specified using table-terms format (see documentation for $this->inputTables a few lines above).
      The format for an outputColumn is thus [columnName, tables=[], treatment="ForceCategorical", TODO attrName=columnName], where tables is an array of table-terms.

      As such, the following is a valid outputColumns array:
      [
        // first outputColumn
        ["report.Status",
          [
            ["RaccomandazioniTerapeuticheUnitarie", ["RaccomandazioniTerapeuticheUnitarie.ID_RACCOMANDAZIONE_TERAPEUTICA = RaccomandazioniTerapeutiche.ID"]]
          ],
          "ForceCategoricalBinary"
        ],
        // second outputColumn
        ["PrincipiAttivi.NOME",
          [
            ["ElementiTerapici", ["report.ID = Recommandations.reportID"]],
            ["PrincipiAttivi", "ElementiTerapici.PrAttID = PrincipiAttivi.ID"]
          ]
        ]
      ]

    */
    private $outputColumns;

    /* SQL WHERE clauses for the concerning inputTables (array of {array of strings, or single string})

      *

      The input array provides, for each recursion level, the set of where clauses (to be joined with AND's).
      For example:
      - [["patient.Age > 30"]]
      - ["patient.Age > 30"]
        -> at the first level: "...WHERE patient.Age > 30..."
        -> at the second level: (no WHERE clause)
      - [["patient.Age > 30", "patient.Name IS NOT NULL"], []]
        -> at the first level: "...WHERE patient.Age > 30 AND patient.Name IS NOT NULL..."
        -> at the second level: (no WHERE clause)
    */
    private $whereClauses;

    /* SQL ORDER BY clauses (array of strings, or single string)

      *

      Differently from whereClauses, the ORDER BY clauses are fixed at all levels.
      For example:
      - [["patient.Age", "DESC"]]
        -> "...ORDER BY patient.ID DESC..."
      - ["patient.Age", ["patient.ID", "DESC"]]
        -> "...ORDER BY patient.Age, patient.ID DESC..."
    */
    private $OrderByClauses;

    /* SQL LIMIT term in the SELECT query (integer) */
    // This is perhaps just for debug.
    // TODO remove this parameter? note that right now we use the same value at every recursion level. Maybe we want to specify a different value for every recursion level instead?
    private $limit;

    /* An identifier column, used for
      - sql-based prediction
      - a correct retrieval step of prediction results
      Furthermore, a value for the identifier column identifies a set of data rows that are to be
        compressed into a single data instance before use. TODO explain better this important point.
    */
    private $identifierColumnName;

    /* Optimizer in use for training the models.
      This can be set via ->setLearningMethod(string) (only "PRip" available atm),
      or ->setLearner($learner)
    */
    private $learner;

    /*
      Array storing all the hierarchy of discriminative models trained (or loaded)
    */
    private $models;

    /*
      Array storing the prediction results for each hierarchy node
      TODO merge with $models
    */
    private $predictionResults;

    /*
      Training mode.
      Available values:
      - "FullTraining" (trains and test onto the same 100% of data)
      - [train_w, test_w] (train/test split according to these two weights)
    */
    private $trainingMode;

    /*
      The cut off value is the value between 0 and 1 representing the minimum percentage
      of any of the two classes (in the binary classification case) that is needed
      for telling whether a dataset is too unbalanced to be good, or not.
    */
    private $cutOffValue;

    /*
      The ID of the current run
    */
    private $experimentID;

    /*
      This array is used to tweak the (generally random) order in which the problems are discovered and solved
    */
    private $globalNodeOrder;

    /**
     * Information about the hierarchy of problems. It is creative recursively during UpdateModels,
     * stored into the database as a model_version's field, and fetched ad prediction time, to
     * enhance performance.
     * It is structured as follow:
     * - name of the problem (e.g. the class we are classifying by)
     * - recursion path
     * - model ID
     * - ids of training instances
     * - ids of testing instances
     * - attributes used at training time
     * 
     * It is formed by two parts:
     *  - ['outputAttributes'] containing the output attributes associated with the hierarchy
     *  - ['hierarchyNodes'] contatining the nodes of the hierarchy
     */
    private $hierarchy;

    /* Default options, to be set via ->setDefaultOption() */
    private $defaultOptions = [
        /* Default training mode in use */
        "trainingMode" => [80, 20],
        /* Default treatment for date/datetime columns. NULL treatment will raise error as soon as a date/datetime column is encountered. */
        "dateTreatment" => NULL,
        /* Default treatment for text columns. NULL treatment will raise error as soon as a text column is encountered. */
        "textTreatment" => NULL,
        /* Default language for text pre-processing */
        "textLanguage" => "en"
    ];

    /* Utility Map: Mysql column type -> attr type */
    static $col2attr_type = [
        "datetime" => [
            "" => "datetime"
            , "DaysSince" => "int"
            , "MonthsSince" => "int"
            , "YearsSince" => "int"
        ]
        , "date" => [
            "" => "date"
            , "DaysSince" => "int"
            , "MonthsSince" => "int"
            , "YearsSince" => "int"
        ]
        , "int" => ["" => "int"]
        , "bigint" => ["" => "int"]
        , "float" => ["" => "float"]
        , "real" => ["" => "float"]
        , "double" => ["" => "double"]
        , "enum" => ["" => "enum"]
        , "tinyint(1)" => ["" => "bool"]
        , "boolean" => ["" => "bool"]
    ];

    function __construct()
    {
        $this->setInputTables([]);
        $this->setInputColumns([]);
        $this->setOutputColumns([]);
        $this->setIdentifierColumnName(NULL);
        $this->whereClauses = NULL;
        $this->setOrderByClauses([]);
        $this->limit = NULL;

        $this->models = [
            "name" => "root",
            "subtree" => []
        ];
        $this->learner = NULL;
        $this->trainingMode = NULL;
        $this->cutOffValue = NULL;
        $this->globalNodeOrder = [];
        $this->predictionResults = [];
    }

    /**
     * Given the path to a recursion node, read data from database, pre-process it,
     * build instance objects. At each node, there is an output column which might generate different attributes,
     * so there are k different problems, and this function computes k sets of instances, with same input
     * attributes/values and different output ones.
     */
    private function readData($idVal = NULL, array $recursionPath = [],
                                ?int &$numDataframes = null, bool $silentSQL = false, bool $predicting = false, bool $timing = false): ?array
    {
        //$idVal = null; # debug
        if ($timing) echo "Into readData\n";
        if ($timing) $tic = microtime(TRUE);
        if ($timing) $bigStart = $tic;
        $recursionLevel = count($recursionPath);
        if (!$silentSQL) {
            echo "DBFit->readData(ID: " . Utils::toString($idVal) . ", LEVEL $recursionLevel (path "
                . Utils::toString($recursionPath) . "))" . PHP_EOL;
        }

        /* Checks */
        if (!count($this->inputColumns)) {
            Utils::die_error("Must specify the concerning input columns, through ->setInputColumns() or ->addInputColumn().");
        }
        if (!count($this->outputColumns)) {
            Utils::die_error("Must specify at least an output column, through ->setOutputColumns() or ->addOutputColumn().");
        }
        if (!count($this->inputTables)) {
            Utils::die_error("Must specify the concerning input tables, through ->setInputTables() or ->addInputTable().");
        }
        
        $outputColumnName = $this->getOutputColumnNames()[$recursionLevel];
        if($timing) $tac = microtime(TRUE);
        if($timing) echo "after getOutputColumnNames: " .  abs($tic - $tac) . "seconds.\n";


        /* Select redundant columns by examining the SQL constraints, to be ignored when creating the dataframe */
        $columnsToIgnore = [];
        $constraints = $this->getSQLConstraints($idVal, $recursionPath);
        if($timing) $tic = microtime(TRUE);
        if($timing) echo "after getSQLConstraints: " .  abs($tic - $tac) . "seconds.\n";
        foreach ($constraints as $constraint) {
            /* If any WHERE/JOIN-ON constraint forces the equality between two columns,
              drop one of the resulting attributes. */
            if (preg_match("/^\s*([a-z\d_\.]+)\s*=\s*([a-z\d_\.]+)\s*$/i", $constraint, $matches)) {
                $fst = $matches[1];
                $snd = $matches[2];

                if (!in_array($fst, [$this->identifierColumnName, $outputColumnName])
                    && !in_array($fst, $columnsToIgnore)) {
                    $columnsToIgnore[] = $fst;
                } else if (!in_array($snd, [$this->identifierColumnName, $outputColumnName])
                    && !in_array($snd, $columnsToIgnore)) {
                    $columnsToIgnore[] = $snd;
                } else {
                    Utils::die_error("Unexpected case encountered when removing redundant columns."); // What to do here?
                }
            }
            // Drop attribute when forcing equality to a constant (because then the attributes is not informative)
            if (preg_match("/^\s*([a-z\d_\.]+)\s*=\s*('[a-z\d_\.]*')\s*$/i", $constraint, $matches)) {
                $col = $matches[1];
                if (!in_array($col, [$this->identifierColumnName, $outputColumnName])
                    && !in_array($col, $columnsToIgnore)) {
                    $columnsToIgnore[] = $col;
                } else {
                    Utils::die_error("Unexpected case encountered when removing redundant columns.");
                }
            }
            if (preg_match("/^\s*('[a-z\d_\.]*')\s*=\s*([a-z\d_\.]+)\s*$/i", $constraint, $matches)) {
                $col = $matches[2];
                if (!in_array($col, [$this->identifierColumnName, $outputColumnName])
                    && !in_array($col, $columnsToIgnore)) {
                    $columnsToIgnore[] = $col;
                } else {
                    Utils::die_error("Unexpected case encountered when removing redundant columns.");
                }
            }
        }

        /* Recompute and obtain output attributes in order to profit from attributes that are more specific to the current recursionPath. */
        $outputColumn = &$this->outputColumns[$recursionLevel];
        
        /* New: only one read from database */
        /* We want to call this with just with $idVal  */
        //$tac = microtime(TRUE);
        if ($idVal === null)
            $raw_data = $this->SQLSelectColumns($this->inputColumns, null, $recursionPath, $outputColumn,
                        $silentSQL);
        else
            $raw_data = $this->SQLSelectColumns($this->inputColumns, $idVal, $recursionPath, $outputColumn,
                        $silentSQL);

        /* Check */
        if ($raw_data === []) {
            return [];
        }

        $this->assignColumnAttributes($outputColumn, null, $recursionPath, true, false);

        /* Obtaining attributes and assigning columns to attributes */
        if ($idVal === NULL) {
            $outputAttributes = $this->getColumnAttributes($outputColumn, $recursionPath);
            if ($outputAttributes === null) {
                if ($recursionLevel === 0) {
                    // echo $outputAttributes;
                    foreach ($outputAttributes as $oa)
                        $this->hierarchy['outputAttributes'][$recursionLevel][] = $oa->serializeToArray();
                }
                else {
                    foreach ($outputAttributes as $oa)
                        $this->hierarchy['outputAttributes'][$recursionLevel][$recursionPath[0][1]][] = $oa->serializeToArray();
                }
            }
        }
        else {
            //$this->assignColumnAttributes($outputColumn, $raw_data, $recursionPath, true, false);

            $outputAttributes = [];
            if ($recursionLevel === 0) {
                foreach ($this->hierarchy['outputAttributes'][$recursionLevel] as $oa) {
                    $outputAttributes[] = Attribute::createFromArray($oa);
                }
            }
            else {
                foreach ($this->hierarchy['outputAttributes'][$recursionLevel][$recursionPath[0][1]] as $oa) {
                    $outputAttributes[] = Attribute::createFromArray($oa);
                }
            }
        }

        $rawDataframe = NULL;
        $numDataframes = 0;

        /* Check that some data is found and the output attributes were correctly computed */
        if (!is_array($outputAttributes)) {
            // warn("Couldn't derive output attributes for output column {$this->getColumnName($outputColumn)}!");
            echo "Couldn't derive output attributes for output column {$this->getColumnName($outputColumn)}!" . PHP_EOL;
        } else {
            $rawDataframe = [];

            /* Check that the output attributes are discrete (i.e nominal) */
            foreach ($outputAttributes as $i_prob => $outputAttribute) {
                if (!($outputAttribute instanceof DiscreteAttribute)) {
                    Utils::die_error("All output attributes must be categorical! '"
                        . $outputAttribute->getName() . "' ($i_prob-th of output column {$this->getColumnName($outputColumn)}) is not.");
                }
            }

            if ($idVal !== null) {
                /* In this case, the last element is equal to $idVal */
                $v = (array)$raw_data[0];
                array_pop($v);
                $raw_data[0] = $v;
            }

            /* Recompute and obtain input attributes in order to profit from attributes that are more specific to the current recursionPath. */
            foreach ($this->inputColumns as &$column) {
                if ($idVal === NULL) {
                    $this->assignColumnAttributes($column, null, $recursionPath, false, $timing);
                    $inputAttributes = [];
                    foreach ($this->inputColumns as &$column) {
                        if (in_array($this->getColumnName($column), $columnsToIgnore)) {
                            $attribute = NULL;
                        } else {
                            $attribute = $this->getColumnAttributes($column, $recursionPath);
                        }
                        $inputAttributes[] = $attribute;
                    }
                }
                else {
                    if ($recursionLevel === 0) {
                        $k = array_key_first($this->hierarchy['hierarchyNodes'][0]);
                        foreach ($this->hierarchy['hierarchyNodes'][0][$k]['attributes'] as $a) {
                            $inputAttributes[] = Attribute::createFromArray($a);
                        }
                    }
                    else {
                        $k = array_key_first($this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]]);
                        foreach ($this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]][$k]['attributes'] as $a) {
                            $inputAttributes[] = Attribute::createFromArray($a);
                        }
                    }
                    $raw_data = $this->assignColumnAttributes($column, $raw_data, $recursionPath, false, $timing);
                }
            }

            if ($idVal !== null) {
                if ($recursionLevel === 0)
                    $attributes = $this->hierarchy['hierarchyNodes'][0][$k]['attributes'];
                else
                    $attributes = $this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]][$k]['attributes'];
                array_shift($attributes);
                foreach ($attributes as $i => $a)
                    $attributes[$i] = Attribute::CreateFromArray($a);

                $inst = array_values((array)$raw_data[0]);
                array_shift($inst);

                /* Get representation fonr Discrete Attributes */
                foreach ($attributes as $i => &$a) {
                    if ($a instanceof DiscreteAttribute) {
                        $domain = array_flip($a->getDomain());
                        if (isset($domain[$inst[$i]]))
                            $inst[$i] = $domain[$inst[$i]];
                        else {
                            /* In this case, I add an attribute possible value to domain, that will never appear in rules */
                            $domain = $a->getDomain();
                            $domain[] = 'null';
                            $a->setDomain($domain);

                            $domain = array_flip($a->getDomain());
                            $inst[$i] = $domain['null'];
                        }
                    }
                }

                /* Add output attributes */
                $attributes = array_merge($outputAttributes, $attributes);

                foreach ($outputAttributes as $oa) {
                    array_unshift($inst, 0);
                }


                
                $data[0] = $attributes;
                $data[1][$idVal] = $inst;
                $data[2] = $outputAttributes;

                $numDataframes = count($outputAttributes);
                
                return $data;
            }
            else {
                $attributes = array_merge([$outputAttributes], $inputAttributes);
            }
            $columns = array_merge([$outputColumn], $this->inputColumns);

            if ($idVal === NULL && !count($recursionPath)) {
                echo "LEVEL 0 attributes list:" . PHP_EOL;
                foreach ($attributes as $i_col => $attrs) {
                    if ($attrs === NULL) {
                        echo "[$i_col]: " . Utils::toString($attrs) . PHP_EOL;
                    } else if (count($attrs) > 1) {
                        foreach ($attrs as $i => $attr)
                            echo "[$i_col], $i/" . count($attrs) . ": " . $attr->toString() . PHP_EOL;
                    } else if (count($attrs) == 1) {
                        echo "[$i_col]: " . $attrs[0]->toString() . PHP_EOL;
                    } else {
                        echo "[$i_col]: " . Utils::toString($attrs) . PHP_EOL;
                    }
                }
            }
            if (!$silentSQL) {
                echo "Example query for LEVEL " . $recursionLevel . ", " . Utils::toString($recursionPath) . PHP_EOL;
            }

            $data = $this->readRawData($raw_data, $attributes, $columns);

            /* Deflate attribute and data arrays (breaking the symmetry with columns) */
            $final_data = [];
            foreach ($data as $instance_id => $attr_vals) {
                $row = [];
                foreach ($attr_vals as $i_col => $attr_val) {
                    if ($attributes[$i_col] === NULL) {
                        // Ignore column
                        continue;
                    } else if (is_array($attr_val)) {
                        // Unpack values
                        foreach ($attr_val as $v) {
                            $row[] = $v;
                        }
                    } else {
                        Utils::die_error("Something's off. Invalid attr_val = " . Utils::get_var_dump($attr_val) . Utils::get_var_dump($attributes[$i_col]));
                    }
                }
                $final_data[$instance_id] = $row;
            }

            $final_attributes = [];
            foreach ($attributes as $attribute) {
                if ($attribute === NULL) {
                    // Ignore column
                    continue;
                } else if (is_array($attribute)) {
                    // Unpack attributes
                    foreach ($attribute as $attr) {
                        $final_attributes[] = $attr;
                    }
                } else {
                    Utils::die_error("Unknown attribute encountered. Must debug code. "
                        . Utils::get_var_dump($attribute));
                }
            }

            $rawDataframe = [$final_attributes, $final_data, $outputAttributes];
            $numDataframes = count($outputAttributes);
        }

        if($timing) $end = microtime(TRUE);
        if($timing) echo "readData took: " .  abs($bigStart - $end) . "seconds.\n\n";

        return $rawDataframe;
    }

    private function generateDataframes(array $rawDataframe, ?int $idModelVersion)
    {
        /* Generate many dataframes, each with a single output attribute (one per each of the output attributes fore this column) */
        list($final_attributes, $final_data, $outputAttributes) = $rawDataframe;
        $numOutputAttributes = count($outputAttributes);
        // echo "Output attributes: "; var_dump($outputAttributes);
        foreach ($outputAttributes as $i_prob => $outputAttribute) {
            // echo "Problem $i_prob/" . $numOutputAttributes . PHP_EOL;

            /* Build instances for this output attribute */
            $outputAttr = clone $final_attributes[$i_prob];
            $inputAttrs = array_map("aclai\\piton\\Utils::clone_object", array_slice($final_attributes, $numOutputAttributes));

            /*if($idModelVersion !== null) {
                $modelVersion = ModelVersion::where('id', $idModelVersion)->first();
                if ($modelVersion->attributes === null) {$serializedAttributes = [];
                    foreach ($inputAttrs as $attribute) {
                        $serializedAttributes[] = $attribute->serializeToArray();
                    }*/
                    /* Update model version with the attributes of the root node. */
                    /*ModelVersion::where('id', $idModelVersion)
                      ->update(['attributes' => json_encode($serializedAttributes)]);
                }
            }*/

            $outputVals = Utils::array_column_assoc($final_data, $i_prob);
            $attrs = array_merge([$outputAttr], $inputAttrs);
            $data = [];
            foreach ($final_data as $instance_id => $row) {
                $data[$instance_id] = array_merge([$outputVals[$instance_id]], array_slice($row, $numOutputAttributes));
            }

            $dataframe = new Instances($attrs, $data);

            // if (DEBUGMODE || !$silentExcelOutput) {
            // if (DEBUGMODE) {
            // $dataframe->save_ARFF("datasets/" . $this->getModelName($recursionPath, $i_prob) . ".arff");
            // if ($idVal === NULL) {
            //   $path = "datasets/" . toString($recursionPath) . ".csv";
            // } else {
            //   $path = "datasets/" . toString($recursionPath) . "-$idVal.csv";
            // }
            // $dataframe->save_CSV($path);
            // }

            // if (DEBUGMODE && $idVal === NULL) {
            //   // $dataframe->save_ARFF("arff/" . $this->getModelName($recursionPath, $i_prob) . ".arff");
            //   // echo $dataframe->toString(false);
            // }

            // echo $dataframe->toString(false, [0,1,2,3,4,5,6,7,8,9,10,11,12]);

            yield $dataframe;
        }
        // echo count($dataframes) . " dataframes computed " . PHP_EOL;
    }

    /** Helper function for ->readData() that performs the pre-processing steps.
     * For each mysql data row, derive a new data row.
     * The identifier column is used to determine which rows to merge.
     */
    function &readRawData(array &$raw_data, array &$attributes, array &$columns): array
    {
        // var_dump($attributes);
        // var_dump("attributes");
        // var_dump($attributes[1][0]);
        // var_dump($columns);

        $data = [];

        /** For each data row... */
        foreach ($raw_data as $raw_row) {
            $raw_row = get_object_vars($raw_row);
            // echo Utils::get_var_dump($raw_row) . PHP_EOL;

            /* Pre-process row values according to the corresponding attribute */
            $attr_vals = [];
            foreach ($columns as $i_col => &$column) {
                $attribute = $attributes[$i_col];

                if ($attribute === NULL) {
                    // Ignore column
                    $attr_val = NULL;
                } else {
                    /* At this point, a value for a column is an array of values for the column's attributes */
                    $attr_val = [];
                    // var_dump($i_col);
                    // var_dump($raw_row);
                    // var_dump($this->getColumnTreatmentType($column));
                    $raw_val = $raw_row[$this->getColumnNickname($column)];

                    if ($raw_val === NULL) {
                        // Avoid NULL values for the output column.
                        // For example, a NULL value may also be due to a LEFT JOIN
                        if ($i_col === 0) {
                            // TODO bring back this error notice. Only valid when some join is not an inner join
                            // if ... die_error("About to push NULL values for output attribute. " . PHP_EOL . join(PHP_EOL . ",", array_map("toString", $attribute)));

                            foreach ($attribute as $attr) {
                                if ($attr->getType() == "bool") {
                                    $attr_val[] = intval(0);
                                } else {
                                    Utils::die_error("Found a NULL value for the output column " . $this->getColumnName($column)
                                        . " ($i_col) , but failed to translate it into a value for attribute of type '{$attr->getType()}': $attr. "
                                        . "Is this value given by OUTER JOIN operations?"
                                    );
                                }
                            }
                        } else {
                            // Empty column -> empty vals for all the column's attributes
                            foreach ($attribute as $attr) {
                                $attr_val[] = NULL;
                            }
                        }
                    } else {
                        /* Apply treatment */
                        switch (true) {
                            /* ForceSet (multiple attributes & values) */
                            case $this->getColumnTreatmentType($column) == "ForceSet":
                                /* TODO change explanation Append k values, one for each of the classes */
                                $transformer = $this->getColumnTreatmentArg($column, 1);
                                if ($transformer === NULL) {
                                    foreach ($attribute as $attr) {
                                        $classSet = $attr->getMetadata();
                                        // $val = intval($class == $raw_val);
                                        $val = intval(in_array($raw_val, $classSet));
                                        $attr_val[] = $val;
                                    }
                                } else {
                                    // $transformer = function ($x) { return [$x]; };
                                    $values = $transformer($raw_val);
                                    foreach ($attribute as $attr) {
                                        $classSet = $attr->getMetadata();
                                        // var_dump("classSet, values");
                                        // var_dump($classSet, $values);
                                        if ($values !== NULL) {
                                            // TODO check that this does the right thing. Maybe we want STRICT SETS?
                                            $val = intval(empty(array_diff($classSet, $values)));
                                        } else {
                                            $val = NULL;
                                        }
                                        // var_dump("val");
                                        // var_dump($val);
                                        $attr_val[] = $val;
                                    }
                                }
                                break;

                            /* Text column (multiple attributes & values) */
                            case $this->getColumnTreatmentType($column) == "BinaryBagOfWords":

                                /* Append k values, one for each word in the dictionary */
                                $lang = $this->defaultOptions["textLanguage"];
                                foreach ($attribute as $attr) {
                                    $word = $attr->getMetadata();
                                    $val = intval(in_array($word, $this->text2words($raw_val, $lang)));
                                    $attr_val[] = $val;
                                }
                                break;

                            default:
                                /* Single attribute & value */
                                if (count($attribute) != 1) {
                                    Utils::die_error("Something's off. Found multiple attributes for column "
                                        . $this->getColumnName($column)
                                        . " ($i_col)" . Utils::get_var_dump($attribute));
                                }
                                $attribute = $attribute[0];

                                /* For categorical attributes, use the class index as value */
                                if ($attribute instanceof DiscreteAttribute) {
                                    if (is_bool($raw_val)) {
                                        // does this ever happen?
                                        $raw_val = intval($raw_val);
                                    }
                                    $val = $attribute->getKey($raw_val);
                                    /* When forcing categorical attributes, push the missing values to the domain; otherwise, any missing domain class will raise error */
                                    if ($val === false) {
                                        // TODO for ForceCategorical, do the select distinct query instead of doing this
                                        // if (in_array($this->getColumnTreatmentType($column), ["ForceCategorical"])) {
                                        //   $attribute->pushDomainVal($raw_val);
                                        //   $val = $attribute->getKey($raw_val);
                                        // }
                                        // else {
                                        Utils::die_error("Something's off. Couldn't find element in domain of attribute {$attribute->getName()}: " . Utils::get_var_dump($raw_val));
                                        // }
                                    }
                                } /* Dates & Datetime values */
                                else if (in_array($this->getColumnMySQLType($column), ["date", "datetime"])) {
                                    $type_to_format = [
                                        "date" => "Y-m-d"
                                        , "datetime" => "Y-m-d H:i:s"
                                    ];
                                    $format = $type_to_format[$this->getColumnMySQLType($column)];
                                    $date = DateTime::createFromFormat($format, $raw_val);
                                    if ($date === false) {
                                        // Utils::warn("Incorrect date string \"$raw_val\" (expected format: \"$format\")");
                                        $val = NULL;
                                    } else {
                                        switch ($this->getColumnTreatmentType($column)) {
                                            case "DaysSince":
                                                $today = new DateTime("now");
                                                $val = intval($date->diff($today)->format("%R%a"));
                                                break;
                                            case "MonthsSince":
                                                $today = new DateTime("now");
                                                $val = intval($date->diff($today)->format("%R%m"));
                                                break;
                                            case "YearsSince":
                                                $today = new DateTime("now");
                                                $val = intval($date->diff($today)->format("%R%y"));
                                                break;
                                            default:
                                                Utils::die_error("Unknown treatment for {$this->getColumnMySQLType($column)} column \"" .
                                                    $this->getColumnTreatmentType($column) . "\"");
                                                break;
                                        };
                                    }
                                } else {
                                    $val = $raw_val;
                                }
                                $attr_val = [$val];
                                break;
                        }
                    }
                }
                $attr_vals[] = $attr_val;
            } // foreach ($columns as $i_col => $column)
            unset($column);

            /* Append row */
            if ($this->identifierColumnName === NULL) {
                $data[] = $attr_vals;
            } else {
                /* Check that the identifier column actually identifies single rows,
                    and merge rows when needed. */
                $idVal = $raw_row[$this->getColNickname($this->identifierColumnName)];
                if (!isset($data[$idVal])) {
                    $data[$idVal] = $attr_vals;
                } else {
                    $attr_vals_orig = &$data[$idVal];

                    /* Check differences between rows */
                    foreach (Utils::zip($attr_vals_orig, $attr_vals, $columns) as $i_col => $z) {
                        $column = $columns[$i_col];
                        if ($z[0] === $z[1]) {
                            continue;
                        }
                        /* Only merging output values is allowed */
                        if ($i_col !== 0) {
                            Utils::die_error("Found more than one row with same identifier ({$this->identifierColumnName} = " . Utils::toString($idVal)
                                . ") but merging on column $i_col-th (" . $this->getColumnName($column)
                                . " failed (it's not an output column). " . PHP_EOL
                                . "First value: " . Utils::get_var_dump($z[0]) . PHP_EOL
                                . "Second value: " . Utils::get_var_dump($z[1]) . PHP_EOL
                                . "Column mysql type: " . Utils::get_var_dump($this->getColumnMySQLType($column)) . PHP_EOL
                                . "Column treatment: " . Utils::get_var_dump($this->getColumnTreatment($column)) . PHP_EOL
                                . "Column attr type: " . Utils::get_var_dump($this->getColumnAttrType($column)) . PHP_EOL
                                // . "Column name: " . get_var_dump($this->getColumnName($column)) . PHP_EOL
                                // . get_var_dump($attr_vals_orig) . get_var_dump($attr_vals)
                                . "Suggestion: explicitly ask to ignore this column."
                            );
                        }
                        $attribute = $attributes[$i_col];
                        if (is_array($attr_vals_orig[$i_col])) {
                            foreach (Utils::zip($z[0], $z[1]) as $a => $val) {
                                /* Only merging bool values by means of boolean-ORs is allowed */
                                if ($attribute[$a]->getType() == "bool") {
                                    $attr_vals_orig[$i_col][$a] = intval($attr_vals_orig[$i_col][$a] || $z[1][$a]);
                                } else {
                                    Utils::die_error("Found more than one row with same identifier value: '{$this->identifierColumnName}' = " . Utils::toString($idVal)
                                        . ", but I don't know how to merge values for column " . $this->getColumnName($column)
                                        . " ($i_col) of type '{$attribute[$a]->getType()}'. "
                                        . "Suggestion: specify ForceBinary/ForceCategoricalBinary treatment for this column (this will break categorical attributes into k binary attributes, easily mergeable via OR operation)."
                                    // . get_var_dump($z[0]) . get_var_dump($z[1])
                                    // . get_var_dump($attr_vals_orig) . get_var_dump($attr_vals)
                                    );
                                }
                            }
                        } else if ($data[$idVal][$i_col] !== NULL) {
                            Utils::die_error("Something's off. Invalid attr_val = " . Utils::get_var_dump($data[$idVal][$i_col]));
                        }
                    };
                    //
                }
            }
        } // foreach ($raw_data as $raw_row)

        return $data;
    }

    /* Generates SQL SELECT queries and interrogates the database. */
    private function SQLSelectColumns(
        array $columns
        , $idVal = NULL
        , array $recursionPath = []
        , array $outputColumn = NULL
        , bool $silent = !DEBUGMODE
        , bool $distinct = false
        , ?int $forceRecursionLevel = NULL
        , ?array $forceWhereClausesArr = NULL
        , bool $forceOrderByClause = true
        , bool $returnQuery = false
        , array $otherClauses = [])
    {

        // forceRecursionLevel
        $recursionLevel = $forceRecursionLevel;
        if ($forceRecursionLevel === NULL) {
            $recursionLevel = count($recursionPath);
        }

        // forceWhereClausesArr
        $whereClauses = $forceWhereClausesArr;
        if ($forceWhereClausesArr === NULL) {
            $whereClauses = $this->getSQLWhereClauses($idVal, $recursionPath);
        }

        /* Build SQL query string */
        $sql = "";

        /* SELECT ... FROM */
        $cols_str = [];

        if ($outputColumn !== NULL && !$distinct) {
            if ($idVal !== NULL) {
                $cols_str[] = "NULL AS " . $this->getColumnNickname($outputColumn);
            } else {
                $cols_str[] = $this->getColumnName($outputColumn) . " AS " . $this->getColumnNickname($outputColumn);
            }
        }

        foreach ($columns as $col) {
            $cols_str[] = $this->getColumnName($col) . " AS " . $this->getColumnNickname($col);
        }

        /* Add identifier column */
        if ($this->identifierColumnName !== NULL && !$distinct) {
            $cols_str[] = $this->identifierColumnName . " AS " . $this->getColNickname($this->identifierColumnName);
        }

        $sql .= "SELECT " . ($distinct ? "DISTINCT " : "") . Utils::mysql_list($cols_str, "aclai\\piton\\Utils::noop");

        /* Join all input tables AND the output tables needed, depending on the recursion depth */
        $tables = $this->inputTables;
        if ($idVal === NULL) {
            // echo "recursionLevel" . $recursionLevel;
            $tables = array_merge($tables, $this->getColumnTables($this->outputColumns[$recursionLevel]));
        }

        $sql .= " FROM";
        foreach ($tables as $k => $table) {
            $sql .= " ";
            if ($k == 0) {
                $sql .= $this->getTableName($table);
            } else {
                $sql .= $this->getTableJoinType($table) . " " . $this->getTableName($table);
                $clauses = $this->getTableJoinClauses($table);
                if (count($clauses)) {
                    $sql .= " ON " . join(" AND ", $clauses);
                }
            }
        }

        /* WHERE */

        if ($distinct) {
            if (count($cols_str) > 1) {
                Utils::die_error("Unexpected case: are you sure you want to ask for distinct rows with more than one field?"
                    . PHP_EOL . $sql . PHP_EOL . Utils::get_arr_dump($cols_str));
            }
            $whereClauses[] = "!ISNULL(" . $this->getColumnName($columns[0]) . ")";
        }

        if (count($whereClauses)) {
            $arr_whereClauses = [];
            foreach ($whereClauses as $whereClause) {
                if (is_string($whereClause)) {
                    $str_whereClause = $whereClause;
                } else if (is_array($whereClause)) {
                    $str_whereClause = $whereClause[0] . " " . $whereClause[1] . " ";
                    if ($whereClause[2][0] == "reuse_current_query") {
                        // Regenerate query until the previous constraint (ignoring the order clause)
                        $str_whereClause .= "(\n" . $this->SQLSelectColumns(
                                [$this->readColumn($whereClause[0])]
                                , $idVal // Note
                                , $recursionPath
                                , $outputColumn
                                , $silent
                                , true
                                , $whereClause[2][1]
                                , array_merge($arr_whereClauses, $whereClause[2][2])
                                , false
                                , true
                                , (isset($whereClause[2][3]) ? $whereClause[2][3] : [])
                            ) . ")\n";
                    }
                }
                $arr_whereClauses[] = $str_whereClause;
            }
            $sql .= " WHERE " . join(" AND ", $arr_whereClauses);
        }

        /* GROUP BY */
        if (isset($otherClauses["GROUP BY"])) {
            $sql .= " GROUP BY " . join(", ", Utils::toList($otherClauses["GROUP BY"]));
        }

        /* HAVING */
        if (isset($otherClauses["HAVING"])) {
            $sql .= " HAVING " . join(", ", Utils::toList($otherClauses["HAVING"]));
        }

        /* ORDER BY */
        if ($forceOrderByClause && !$distinct && count($this->orderByClauses)) {
            $sql .= " ORDER BY "
                . join(", ", array_map(function ($clause) {
                    return (is_string($clause) ? $clause : $clause[0] . " " . $clause[1]);
                }, $this->orderByClauses));
        }

        /* LIMIT */
        if ($idVal === NULL && $this->limit !== NULL) {
            $sql .= " LIMIT {$this->limit}";
        }
        if ($returnQuery) {
            return $sql;
        } else {
            /* Query database */
            // echo $sql . PHP_EOL;
            // die();
            // if (!$silent) {
            //     echo "SQL:" . PHP_EOL . $sql . PHP_EOL;
            // }
            //$raw_data = mysql_select($this->inputDB, $sql, $silent);
            $raw_data = DB::select(DB::raw($sql));
            return $raw_data;
        }
    }

    /* Need a nickname for every column when using table.column format,
        since PHP MySQL connections do not allow to access result fields
        using this notation. Therefore, each column is aliased to table_DOT_column */
    function getColNickname($colName)
    {
        if (strstr($colName, "(") === false) {
            // return str_replace(".", "_", $colName);
            return "X" . md5(str_replace(".", "_", $colName));
        } else {
            return "X" . md5($colName);
        }
    }

    /* Need a nickname for every table when saving Instances in the database,
        since MySQL doesn't like table names longer that 64 characters.
        It also tracks the name of the table in the DB.
    */
    function getTableNickname(string $tableName)
    {
        // TODO migrate to Laravel Query Builder
        $tableNickname = "X" . md5($tableName);
        /*$sql = "CREATE TABLE IF NOT EXISTS `Table reference` (`ID` INT AUTO_INCREMENT PRIMARY KEY, `Table nickname` VARCHAR(256) NULL, `Table name` VARCHAR(256) NOT NULL)";
        mysql_prepare_and_executes($this->inputDB, $sql);
        $sql = "INSERT INTO `Table reference` (`Table nickname`, `Table name`) VALUES ('"
            . mysqli_real_escape_string($this->inputDB, $tableName) . "', '" . mysqli_real_escape_string($this->inputDB,$tableNickname) . "')";
        mysql_prepare_and_executes($this->inputDB, $sql);*/
        return $tableNickname;
    }

    /* Helper */
    private function getSQLConstraints($idVal, array $recursionPath): array
    {
        $constraints = $this->getSQLWhereClauses($idVal, $recursionPath);
        foreach ($this->inputTables as $table) {
            $constraints = array_merge($constraints, $this->getTableJoinClauses($table));
        }
        // Filter any constraint that is not a string TODO maybe this function is
        // only used in readData and it doesn't need to be a separate part of it
        $constraints = array_values(array_filter($constraints, "is_string"));
        // TODO add those for the outputColumns, same as in getSQL... ?
        return $constraints;
    }

    /* Helper */
    private function getSQLWhereClauses($idVal, array $recursionPath): array
    {
        $whereClauses = [];
        if ($this->whereClauses !== NULL && count($this->whereClauses)) {
            $whereClauses = array_merge($whereClauses, $this->whereClauses[0]);
            // foreach ($this->whereClauses as $recursionLevel => $whereClausesSet) {
            //   $whereClauses = array_merge($whereClauses, $whereClausesSet);
            //   if ($recursionLevel >= count($recursionPath) + ($idVal !== NULL ? 0 : 1)) {
            //     break;
            //   }
            // }
        }
        if ($idVal !== NULL) {
            if ($this->identifierColumnName === NULL) {
                Utils::die_error("An identifier column name must be set. Please, use ->setIdentifierColumnName()");
            }
            $whereClauses[] = $this->identifierColumnName . " = $idVal";
        } else {
            // Append where clauses for the current hierarchy level
            // TODO is this useful?
            // if (isset($this->whereClauses[1+count($recursionPath)])) {
            //   $whereClauses = array_merge($whereClauses, $this->whereClauses[1+count($recursionPath)]);
            //   // var_dump($whereClauses);
            // }
            $outAttrs = $this->getOutputColumnNames();
            foreach ($recursionPath as $recursionLevel => $node) {
                // $this->getOutputColumnAttributes()[$recursionLevel][$node[0]]->getName();
                // var_dump([$node[0], $node[1]]);
                // var_dump($this->getOutputColumnAttributes()[$recursionLevel]);
                // var_dump($this->getOutputColumnAttributes()[$recursionLevel][$node[0]]);
                $whereClauses[] = $outAttrs[$recursionLevel]
                    . " = '" . $node[1] . "'";
            }
        }
        return $whereClauses;
    }

    

    /* Create and assign the corresponding attribute(s) to a given column */
    function assignColumnAttributes(array &$column, ?array $raw_data = null, array $recursionPath = [], bool $isOutputAttribute = false, bool $timing = false)
    {
        /* Attribute base-name */
        $attrName = $this->getColumnAttrName($column);

        /* At prediction time, I make the attributes the same as the models' attributes */
        if ($raw_data !== null) {
            $recursionLevel = count($recursionPath);

            if ($recursionLevel === 0) {
                $k = array_key_first($this->hierarchy['hierarchyNodes'][0]);
                $attributes = $this->hierarchy['hierarchyNodes'][0][$k]['attributes'];
            }
            else {
                $k = array_key_first($this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]]);
                $attributes = $this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]][$k]['attributes'];
            }
            if (array_search($attrName, array_column($attributes, 'name')) === false) {

                $value = array_column($raw_data, $this->getColumnNickname($column))[0];

                $input = preg_quote($attrName, '~'); // don't forget to quote input string!
                $result = preg_grep('~' . $input . '~', array_column($attributes, 'name'));

                /* Use value and result to create new attributes for instance and set only
                    result_value to value, the other to NO_value; then remove result from instance */
                $raw_data_array = (array)$raw_data[0];
                unset($raw_data_array[$this->getColumnNickname($column)]);
                foreach ($result as $i => $newAttribute) {
                    if ($newAttribute === $attrName . '_' . $value)
                        $raw_data_array[$this->getColNickname($newAttribute)] = $value;
                    else
                        $raw_data_array[$this->getColNickname($newAttribute)] = "NO_". DBFit::CleanClassName($newAttribute);
                    /* Also move correspondent attribute to the end of array: doing so, they are in the same order */
                    $v = $attributes[$i];
                    unset($attributes[$i]);
                    $attributes[] = $v;
                }
                if ($recursionLevel === 0)
                    $this->hierarchy['hierarchyNodes'][0][$k]['attributes'] = array_values($attributes);
                else
                    $this->hierarchy['hierarchyNodes'][$recursionLevel][$recursionPath[0][1]][$k]['attributes'] = array_values($attributes);
                $raw_data[0] = (object)$raw_data_array;
            }
        }
        
        switch (true) {
            /* Forcing a set of binary categorical attributes */
            case $this->getColumnTreatmentType($column) == "ForceSet":
                $depth = $this->getColumnTreatmentArg($column, 0);

                /* Find unique values */
                $domain = null;
                if ($raw_data === null) {
                  $raw_data = $this->SQLSelectColumns([$column], NULL, $recursionPath,
                      NULL, true, true);
                  $domain = array_column($raw_data, $this->getColumnNickname($column));
                }
                else {
                  /* I need to extrapolate the column (array indexes don't come in handy) */
                  $domain = array_column($raw_data, $this->getColumnNickname($column));
                  $domain = array_unique($domain);
                  $domain = array_filter($domain, "aclai\\piton\\Utils::notNull");
                  $domain = array_values($domain);
                }

                /* Also null appears as a value, I remove it for outputColumns */
                if ($isOutputAttribute) {
                  foreach ($domain as $i => $val) {
                    if ($val === null)
                      unset($domain[$i]);
                  }
                }

                /* Apply transform to $domain */
                $transformer = $this->getColumnTreatmentArg($column, 1);
                if ($transformer !== NULL) {
                    // $transformer = function ($x) { return [$x]; };
                    foreach ($val as $domain) {
                        $values = $transformer($val);
                        if ($values !== NULL) {
                            $domain = array_merge($domain, $values);
                        }
                    }
                    $domain = array_unique($domain);
                    $domain = array_values($domain);
                }

                /* Apply transform to $domain */
                $transformer = $this->getColumnTreatmentArg($column, 1);
                if ($transformer !== NULL) {
                    // $transformer = function ($x) { return [$x]; };
                    foreach ($val as $domain) {
                        $values = $transformer($val);
                        if ($values !== NULL) {
                            $domain = array_merge($domain, $values);
                        }
                    }
                    $domain = array_unique($domain);
                }

                if ($isOutputAttribute) {
                    usort($domain, [$this, "cmp_nodes"]);
                }
                // var_dump($domain);

                if (!count($domain)) {
                    // warn("Couldn't apply ForceSet (depth: " . toString($depth) . ") to column " . $this->getColumnName($column) . ". No data instance found.");
                    $attributes = NULL;
                } else {
                    $attributes = $this->forceCategoricalBinary($depth, $domain, $attrName);
                }
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case ForceSet took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
            /* Enum column */
            case $this->getColumnAttrType($column) == "enum":
                $domain_arr_str = (preg_replace("/enum\((.*)\)/i", "[$1]", $this->getColumnMySQLType($column)));
                $domain_arr = NULL;
                eval("\$domain_arr = " . $domain_arr_str . ";");
                $attributes = [new DiscreteAttribute($attrName, "enum", $domain_arr)];
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case enum took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
            /* Forcing a categorical attribute */
            /**
             * In this moment, ForceCategorical is forced to be as ForceCategoricalBinary.
             * TODO this could change in the future, so the commented code must not me removed!
             */
            case $this->getColumnTreatmentType($column) == "ForceCategorical":
                $depth = $this->getColumnTreatmentArg($column, 0);

                /* Find unique values */
                $domain = null;
                if ($raw_data === null) {
                  $raw_data = $this->SQLSelectColumns([$column], NULL, $recursionPath,
                      NULL, true, true);
                  $domain = array_column($raw_data, $this->getColumnNickname($column));
                }
                else {
                  /* I need to extrapolate the column (array indexes don't come in handy) */
                  $domain = array_column($raw_data, $this->getColumnNickname($column));
                  $domain = array_unique($domain);
                  $domain = array_filter($domain, "aclai\\piton\\Utils::notNull");
                  $domain = array_values($domain);
                }

                /* Also null appears as a value, I remove it for outputColumns */
                if ($isOutputAttribute) {
                  foreach ($domain as $i => $val) {
                    if ($val === null)
                      unset($domain[$i]);
                  }
                }

                /* Apply transform to $domain */
                $transformer = $this->getColumnTreatmentArg($column, 1);
                if ($transformer !== NULL) {
                    // $transformer = function ($x) { return [$x]; };
                    foreach ($val as $domain) {
                        $values = $transformer($val);
                        if ($values !== NULL) {
                            $domain = array_merge($domain, $values);
                        }
                    }
                    $domain = array_unique($domain);
                    $domain = array_values($domain);
                }

                if ($isOutputAttribute) {
                    usort($domain, [$this, "cmp_nodes"]);
                }
                // var_dump($domain);

                if (!count($domain)) {
                    // warn("Couldn't apply ForceSet (depth: " . toString($depth) . ") to column " . $this->getColumnName($column) . ". No data instance found.");
                    $attributes = NULL;
                } else if (count($domain) > 2) {
                    $this->setColumnTreatment($column, "ForceSet");
                    $attributes = $this->forceCategoricalBinary($depth, $domain, $attrName);
                } else {
                    $attributes = [new DiscreteAttribute($attrName, "enum", $domain)];
                }
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case forceCategorical took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;

            /* Numeric column */
            case in_array($this->getColumnAttrType($column), ["int", "float", "double"]):
                $attributes = [new ContinuousAttribute($attrName, $this->getColumnAttrType($column))];
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case in_array [int, float, double] took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
            /* Boolean column */
            case in_array($this->getColumnAttrType($column), ["bool", "boolean"]):
                $attributes = [new DiscreteAttribute($attrName, "bool", ["0", "1"])];
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case in_array [bool, boolean] took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
            /* Text column */
            case $this->getColumnAttrType($column) == "text":
                switch ($this->getColumnTreatmentType($column)) {
                    case "BinaryBagOfWords":

                        /* Generate binary attributes indicating the presence of each word */
                        $generateDictAttrs = function ($dict) use ($attrName, &$column) {
                            $attributes = [];
                            foreach ($dict as $word) {
                                $a = new DiscreteAttribute("'$word' in $attrName",
                                    "word_presence", ["N", "Y"], $word);
                                $a->setMetadata($word);
                                $attributes[] = $a;
                            }
                            return $attributes;
                        };

                        /* The argument can be the dictionary size (k), or more directly the dictionary as an array of strings */
                        $arg = $this->getColumnTreatmentArg($column, 0);
                        if (is_array($arg)) {
                            $dict = $arg;
                            $attributes = $generateDictAttrs($dict);
                        } else if (is_integer($arg)) {
                            $k = $arg;

                            /* Find $k most frequent words */
                            $word_counts = [];
                            $raw_data = $this->SQLSelectColumns([$column], NULL, $recursionPath, NULL, true);

                            $lang = $this->defaultOptions["textLanguage"];
                            if (!isset($this->stop_words)) {
                                $this->stop_words = [];
                            }
                            if (!isset($this->stop_words[$lang])) {
                                $this->stop_words[$lang] = explode("\n", file_get_contents("assets/" . $lang . "-stopwords.txt"));
                            }
                            foreach ($raw_data as $raw_row) {
                                $text = $raw_row[$this->getColumnNickname($column)];

                                if ($text !== NULL) {
                                    $words = $this->text2words($text, $lang);

                                    foreach ($words as $word) {
                                        if (!isset($word_counts[$word]))
                                            $word_counts[$word] = 0;
                                        $word_counts[$word] += 1;
                                    }
                                }
                            }
                            // var_dump($word_counts);

                            if (!count($word_counts)) {
                                // Utils::warn("Couldn't derive a BinaryBagOfWords dictionary for column \"" . $this->getColumnName($column) . "\". This column will be ignored.");

                                $attributes = NULL;
                            } else {
                                $dict = [];
                                foreach (range(0, $k - 1) as $i) {
                                    $max_count = max($word_counts);
                                    $max_word = array_search($max_count, $word_counts);
                                    $dict[] = $max_word;
                                    unset($word_counts[$max_word]);
                                    if (!count($word_counts)) {
                                        break;
                                    }
                                }
                                // var_dump($dict);

                                // if (count($dict) < $k) {
                                //     Utils::warn("Couldn't derive a BinaryBagOfWords dictionary of size $k for column \"" . $this->getColumnName($column) . "\". Dictionary of size " . count($dict) . " will be used.");
                                // }
                                $attributes = $generateDictAttrs($dict);
                            }
                        } else if ($arg === NULL) {
                            Utils::die_error("Please specify a parameter (dictionary or dictionary size)"
                                . " for bag-of-words"
                                . " processing column '" . $this->getColumnName($column) . "'.");
                        } else {
                            Utils::die_error("Unknown type of parameter for bag-of-words"
                                . " (column '" . $this->getColumnName($column) . "'): "
                                . Utils::get_var_dump($arg) . ".");
                        }
                        break;
                    default:
                        Utils::die_error("Unknown treatment for text column \""
                            . $this->getColumnName($column) . "\" : "
                            . Utils::get_var_dump($this->getColumnTreatmentType($column)));
                        break;
                }
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case text took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
            default:
                Utils::die_error("Unknown column type: " . $this->getColumnMySQLType($column));
                if($timing) $tac = microtime(TRUE);
                if($timing) echo "case default took : " .  abs($tic - $tac) . "seconds.\n\n";
                break;
        }

        /* Sanity check */
        if (is_array($attributes) and !count($attributes)) {
            Utils::die_error("Something's off. Attributes set for a column (here '"
                . $this->getColumnName($column) . "') can't be empty: " . Utils::get_var_dump($attributes) . PHP_EOL . Utils::get_var_dump($column) . PHP_EOL);
        }

        /* Each column has a tree of attributes, because the set of attributes for the column depends on the recursion path. This is done in order to leverage predicates that are the most specific.  */
        $this->setColumnAttributes($column, $recursionPath, $attributes);

        if ($raw_data !== null)
            return $raw_data;
    }

    /**
     * Set node of hierarchy structure. It can be used at training time to set information about
     * the hierarchy to be stored in the database.
     * Each node is in the form:
     *  - name, id_model, ... (properties of the node)
     *  - id of the father-problem node
     *  - ids of the sub-problems nodes
     * 
     * Nodes are indexed by problem name (TODO is this safe? This is done to simplify research)
     * Note that this structure is similar to a double-linked list
     */
    protected function setHierarchyNode(object $model, ?string $fatherNode, int $modelId, int $recursionLevel)
    {
        $hierarchyNode = [];

        /* The name of the class of the model/problem solved by the problem */
        $modelName = DBFit::cleanClassName($model->getClassAttribute()->getName());
        $hierarchyNode['name'] = $modelName;
        /* The id of the class_model instance associated to the molde into the database */
        $hierarchyNode['model_id'] = $modelId;
        foreach ($model->getAttributes() as $a)
            $hierarchyNode['attributes'][] =  $a->serializeToArray();
        
        if ($fatherNode === null) {
            /* Case Level 0 */
            /* The name of the father-problem node, in this case it is empty (sons of root node) */
            $hierarchyNode['father_node'] = [];

            $this->hierarchy['hierarchyNodes'][0][$modelName] = $hierarchyNode;
        } else {
            /* Case sub-problem */
            /* The name of the father-problem node */
            $fatherName = DBFit::CleanClassName($fatherNode);
            $hierarchyNode['father_node'] = $fatherName;
            /* Each node has an array of sub-problems names. */
            /* These are stored when the sub-problem is being set, and not when the node is being set */
            $this->hierarchy['hierarchyNodes'][$recursionLevel-1][$fatherName]['sub_problems'][] = $modelName;

            $this->hierarchy['hierarchyNodes'][$recursionLevel][$fatherName][$modelName] = $hierarchyNode;
        }
    }

    /* Train and test all the model tree on the available data, and save to database */
    public function updateModel(int $idModelVersion, array $recursionPath = [])
    {
        echo "DBFit->updateModel(" . Utils::toString($recursionPath) . ")" . PHP_EOL;

        $recursionLevel = count($recursionPath);

        if ($recursionLevel === 0) {
            $this->experimentID = date('Y-m-d H:i:s');
        }

        if (!($this->learner instanceof Learner)) {
            Utils::die_error("Learner is not initialized. Please, use ->setLearner() or ->setLearningMethod()");
        }

        /* Read the dataframes specific to this recursion path */
        $rawDataframe = $this->readData(NULL, $recursionPath, $numDataframes);

        // if($recursionLevel === 0) {
        //   $this->models["rawDataframe"] = $rawDataframe;
        // }

        /* Check: if no data available stop recursion */
        if ($rawDataframe === NULL || !$numDataframes) {
            echo "Train-time recursion stops here due to lack of data (recursionPath = " . Utils::toString($recursionPath)
                . "). " . PHP_EOL;
            if ($recursionLevel == 0) {
                Utils::die_error("Training failed! Couldn't find data.");
            }
            return;
        }

        /* Obtain output attributes */
        // $outputAttributes = $this->getColumnAttributes($this->outputColumns[$recursionLevel], $recursionPath);

        /* Prepare child recursion paths, in order to train the models in a breadth-first fashion */
        $childPaths = [];

        /* For each attribute, train subtree */
        foreach ($this->generateDataframes($rawDataframe, $idModelVersion) as $i_prob => $dataframe) {
            echo "Problem $i_prob/" . $numDataframes . PHP_EOL;
            // $outputAttribute = $outputAttributes[$i_prob];
            $outputAttribute = $dataframe->getClassAttribute();

            /* If no data available, skip training */
            if (!$dataframe->numInstances()) {
                echo "Skipping node due to lack of data." . PHP_EOL;
                if ($recursionLevel == 0) {
                    Utils::die_error("Training failed! No data instance found.");
                }
                continue;
            }

            /* If data is too unbalanced, skip training */
            /* TODO inside if, skip node if number of instance is too little */
            if ($this->getCutOffValue() !== NULL &&
                !$dataframe->checkCutOff($this->getCutOffValue())) {
                echo "Skipping node due to unbalanced dataset found"
                    // . "("
                    // . $dataframe->checkCutOff($this->getCutOffValue())
                    // . " > "
                    // . $this->getCutOffValue()
                    // . ")";
                    . "." . PHP_EOL;
                /* TODO not just continue, create empty node without recursion */
                continue;
            }

            //$dataframe->saveToCSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . ".csv");
            //$dataframe->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . ".arff");
            //$dataframe->saveToDB($this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob)));

            /* Obtain and train, test set */
            list($trainData, $testData) = $this->getDataSplit($dataframe);

            // echo "TRAIN" . PHP_EOL . $trainData->toString(DEBUGMODE <= 0) . PHP_EOL;
            // echo "TEST" . PHP_EOL . $testData->toString(DEBUGMODE <= 0) . PHP_EOL;

            echo "TRAIN: " . $trainData->numInstances() . " instances" . PHP_EOL;
            echo "TEST: " . $testData->numInstances() . " instances" . PHP_EOL;

            // $trainData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.csv");
            // $testData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.csv");

            /*if ($i_prob == 0) {
                $trainData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.csv"); // , false);
                $testData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.csv"); // , false);
                $trainData->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.arff");
                $testData->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.arff");
                $trainData->saveToDB($this->outputDB, $this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN")); // trainData
                $testData->saveToDB($this->outputDB, $this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST"));  // testData
            }*/

            /* Train */
            $model_name = $this->getModelName($recursionPath, $i_prob);
            //$model_id = $this->getModelName($recursionPath, $i_prob, true);
            $model = $this->learner->initModel();

            $model->fit($trainData, $this->learner);
            // "python", "sklearn/CART"
            // "python", "wittengstein/RIPPER"

            echo "Trained model '$model_name'." . PHP_EOL;

            // die_error(strval(DEBUGMODE) . strval(DEBUGMODE_DATA) . strval(DEBUGMODE & DEBUGMODE_DATA));

            echo $model . PHP_EOL;

            /* Save model */

            //$model->save(Utils::join_paths(MODELS_FOLDER, $model_name));
            // $model->save(join_paths(MODELS_FOLDER, date("Y-m-d_H:i:s") . $model_name));

            $fatherNode = null;
            if (!empty($recursionPath)) {
                $fatherNode = $recursionPath[array_key_last($recursionPath)][2];
            }
            $modelId = $model->saveToDB($idModelVersion, $recursionLevel, $fatherNode,
                                        $this->learner->getName(), $testData, $trainData);
            
            $this->setHierarchyNode($model, $fatherNode, $modelId, $recursionLevel);
            //$model->dumpToDB($this->outputDB, $model_id);
            // . "_" . join("", array_map([$this, "getColumnName"], ...).);

            $this->setHierarchyModel($recursionPath, $i_prob, clone $model);
            $prob_name = $this->getHierarchyName($recursionPath, $i_prob);
            $subRecursionPath = array_merge($recursionPath, [[$i_prob, $prob_name]]);

            /* Test */
            /** TODO Not working with sklearn :( */
            /* $testResults = $model->test($testData);

            if ($this->identifierColumnName !== NULL) {
                $testResTuplesGtprrt = Utils::zip_assoc($testResults["ground_truths"], $testResults["predictions"], $testResults["rule_types"]);
                // var_dump($testResults["rule_types"]);
                // var_dump($testResTuplesGtprrt);
                foreach ($testResTuplesGtprrt as $instance_id => $gtprrt) {
                    $res = [$testData->reprClassVal($gtprrt[0]), $testData->reprClassVal($gtprrt[1]), $gtprrt[2]];

                    $classRecursionPath = array_column($subRecursionPath, 1);
                    $classRecursionPath = array_merge($classRecursionPath, ["res"]);
                    Utils::arr_set_value($this->predictionResults, array_merge($classRecursionPath, [$instance_id]), $res, true);
                    // echo "changed" . PHP_EOL . toString($this->predictionResults) . PHP_EOL . toString(array_merge($classRecursionPath, [$instance_id])) . PHP_EOL;
                }
            }
            */

            /* Prepare recursion */
            if ($recursionLevel + 1 == $this->getHierarchyDepth()) {
                /* Recursion base case */
                echo "Train-time recursion stops here (recursionPath = " . Utils::toString($recursionPath)
                    . ", problem $i_prob/" . $numDataframes . ") : '$model_name'. " . PHP_EOL;
            } else {
                /* Recursive step: for each output class value, recurse and train the subtree */
                echo "Branching at depth $recursionLevel on attribute \""
                    . $outputAttribute->getName() . "\" ($i_prob/"
                    . $numDataframes . ")) "
                    . " with domain " . Utils::toString($outputAttribute->getDomain())
                    . ". " . PHP_EOL;

                // echo get_var_dump($outputAttribute->getDomain()) . PHP_EOL;

                foreach ($outputAttribute->getDomain() as $className) {
                    // TODO right now I'm not recurring when a "NO_" outcome happens. This is not supersafe, there must be a nice generalization.
                    if (!Utils::startsWith($className, "NO_")) {
                        echo "Recursion on class '$className' for attribute \""
                            . $outputAttribute->getName() . "\". " . PHP_EOL;
                        // TODO generalize this. For each level we have a problem A, subproblems B,C,D. E dunque A/B, A/C, etc. For each subproblem we have a certain number of classes, like A/B/B, A/B/NO_B for the binary case.
                        $childPaths[] = array_merge($recursionPath, [[$i_prob, $className, $outputAttribute->getName()]]);
                    }
                }
            }
        } // END foreach

        /* Recurse */
        foreach ($childPaths as $childPath) {
            /** childPath = [i-prob, className, fatherNodeClassName] */
            $this->updateModel($idModelVersion, $childPath);
        }

        if ($recursionPath === []) {
            //print_r($this->hierarchy);
            ModelVersion::where('id', $idModelVersion)->update(['hierarchy' => json_encode($this->hierarchy)]);
        }
    }

    /**
     * TODO
     * Load an existing set of models.
     * Defaulted to the models trained the most recently
     */
    // function loadModel(?string $path = NULL) {
    //   echo "DBFit->loadModel($path)" . PHP_EOL;

    //   die_error("TODO loadModel, load the full hierarchy");
    //   /* Default path to that of the latest model */
    //   if ($path === NULL) {
    //     $models = filesin(MODELS_FOLDER);
    //     if (count($models) == 0) {
    //       die_error("loadModel: No model to load in folder: \"". MODELS_FOLDER . "\"");
    //     }
    //     sort($models, true);
    //     $path = $models[0];
    //     echo "$path";
    //   }

    //   $this->models = [DiscriminativeModel::loadFromFile($path)];
    // }

    /**
     * Use the models for predicting the values of the output columns for a new instance,
     * identified by the identifier column.
     * 
     * Notice that, if the models used for the prediction are not normalized, the last
     * group of antecedents belong to the activated rule, while the previous groups
     * are an information about the rules that haven't been activated.
     * Therefore, the full rule should be the activated rule interjected with the negation
     * of the previous rules, or interjected with the union of their negated antecedents.
     * 
     * For example, considering the following not normalized model:
     *  R0 = [A00, A01]
     *  R1 = [A10, A11, A12, A13]
     *  R2 = [A20, A21, A22]
     *  R3 = [A30, A31, A32]
     *  R4 = []
     * If the third rule R2 is activated and the model isn't normalized, the function will retrieve
     * information about the antecedents of R0, R1, R2 in the form of an array:
     *  [
     *      0 => [A00, A01],
     *      1 => [A10, A11, A12, A13],
     *      2 => [A20, A21, A22]
     *  ]
     * and only the antecedent of the last group [A20, A21, A22] are to be considered antecedents of R2.
     * The full rule would be:
     *  R2 AND NOT R0 AND NOT R1
     * therefore:
     *  (A20 AND A21 AND A22) AND NOT (A00 AND A01) AND NOT (A10 AND A11 AND A12 AND A13)
     * or, eventually:
     *  A20 AND A21 AND A22 AND (NOT A00 OR NOT A01) AND (NOT A10 OR NOT A11 OR NOT 12 OR NOT A13)     *  
     * 
     * @param string $idVal The instance identifier value.
     * @param array $recursionPath The recursion path which brought us here.
     * @param int $idModelVersion The id of the model version on which we base the actual predict.
     * @param bool $log If true, print a log of the execution. Useful for debugging.
     * @return array The classes which contains the instance, and information about the rule which covered it. 
     */
    function predictByIdentifier(string $idVal, array $recursionPath = [], ?int $idModelVersion = null, bool $log = false, bool $timing = false) : array
    {
        /* The prediction is based on the problem associated with the model version. */
        $q = ModelVersion::where('id', $idModelVersion);
        // Utils::die_error(Utils::get_var_dump($q->count()));
        if ($q->count() == 0) {
            return [];
        }

        $modelVersion = $q->first();
        $idProblem = $modelVersion->id_problem;
        $problem = Problem::where('id', $idProblem)->first();

        /* To enhance performance: re-store hierarchy object */
        if ($modelVersion->hierarchy === NULL) {
            Utils::die_error("modelVersion's hierarchy is NULL. This is likely due to an unfinished learning process.");
        }

        $this->hierarchy = $modelVersion->hierarchy;

        if ($recursionPath == []) {
            /* Predict by identifier is called in a second moment, thus DBFit is just been created. */
            /* TODO use the data stored in the database for the problem. */
            $problemName = $problem->name;
            /* Set DBFit options */
            $this->setTrainingMode(config($problemName . '.trainingMode'));
            $this->setCutOffValue(config($problemName . '.cutOffValue'));
            foreach (config($problemName . '.defaultOptions') as $defaultOption) {
              $this->setDefaultOption($defaultOption[0], $defaultOption[1]);
            }
            $this->setInputTables(config($problemName . '.inputTables'));
            $this->setWhereClauses(config($problemName . '.whereClauses'), true);
            $this->setOrderByClauses(config($problemName . '.orderByClauses'));
            $this->setIdentifierColumnName(config($problemName . '.identifierColumnName'));
            $this->setInputColumns(config($problemName . '.inputColumns'));
            $this->setOutputColumns(config($problemName . '.outputColumns'));
            $this->setGlobalNodeOrder(config($problemName . '.globalNodeOrder'));
        }

        if ($log == true) {
            echo "DBFit->predictByIdentifier($idVal, " . Utils::toString($recursionPath) . ")" . PHP_EOL;
        }

        /* Check. */
        if ($this->identifierColumnName === NULL) {
            Utils::die_error("In order to use ->predictByIdentifier(), an identifier column must be set. Please,
                              use ->setIdentifierColumnName()");
        }

        $recursionLevel = count($recursionPath);

        /* Recursion base case. */
        if ($recursionLevel == $this->getHierarchyDepth()) {
            if ($log == true) {
                echo "Prediction-time recursion stops here due to reached bottom (recursionPath = "
                    . Utils::toString($recursionPath) . ")" . PHP_EOL;
            }
            return [];
        }

        $predictions = [];

        /* Read the dataframes specific to this recursion path. */
        $rawDataframe = $this->readData($idVal, $recursionPath, $numDataframes, true, true, $timing);
        if($timing) $tac = microtime(TRUE);
        if($timing) echo "after readData: " .  abs($tic - $tac) . "seconds.\n";

        /* If no model was trained for the current node, stop the recursion. */
        if ($rawDataframe === NULL) {
            if ($log == true) {
                echo "Prediction-time recursion stops here due to lack of a model (recursionPath = "
                    . Utils::toString($recursionPath) . ":" . PHP_EOL;
            }
            return [];
        }

        /* Check: if no data available stop recursion. */
        if ($rawDataframe === NULL || !$numDataframes) {
            if ($log == true) {
                echo "Prediction-time recursion stops here due to lack of data (recursionPath = "
                    . Utils::toString($recursionPath) . "). " . PHP_EOL;
                if ($recursionLevel == 0) {
                    Utils::die_error("Couldn't compute output attribute (at root level prediction-time).");
                }
            }
            return [];
        }

        /* For each attribute, predict subtree */
        foreach ($this->generateDataframes($rawDataframe, null) as $i_prob => $dataframe) {
            if ($log == true) {
                echo "Problem $i_prob/" . $numDataframes . PHP_EOL;
            }

            /* If no data available, skip training */
            if (!$dataframe->numInstances()) {
                if ($log == true) {
                    Utils::die_error("No data instance found at prediction time. "
                        . "Path: " . Utils::toString($recursionPath));
                }
                continue;
            }

            /* Check that a unique data instance is retrieved */
            if ($dataframe->numInstances() !== 1 && $log == true) {
                Utils::die_error("Found more than one instance at predict time. Is this wanted? ID:
                                 {$this->identifierColumnName} = $idVal");
            }

            /* Retrieve model */
            $model_name = $this->getModelName($recursionPath, $i_prob);
            if (Utils::startsWith($model_name, "_")) {
              $model_name = mb_substr($model_name, 1);
            }
            $classModel = ClassModel::where('id_model_version', $idModelVersion)
              ->where('class', 'like', '%' . $model_name . '%')->first();
            if ($classModel === null) {
              continue;
            }
            $model = RuleBasedModel::createFromDB($classModel->id);
            if($timing) $tic = microtime(TRUE);
            if($timing) echo "after createFromDB: " .  abs($tic - $tac) . "seconds.\n";
            /* If the model came from SKLearnLearner, i need to specify it's normalized. */
            if (Utils::startsWith($modelVersion->learner, "SKLearnLearner")) {
                $model->setIsNormalized(true);
            }

            if ($model === NULL) {
                continue;
            }
            if (!($model instanceof DiscriminativeModel) && $log == true) {
                Utils::die_error("Something's off. Model '$model_name' is not a DiscriminativeModel. "
                    . Utils::get_var_dump($model));
            }

            /* Perform local prediction */
            $predictionOutput = $model->predict($dataframe, true, false, true, null);
            if($timing) $tac = microtime(TRUE);
            if($timing) echo "after predict: " .  abs($tic - $tac) . "seconds.\n";
            /* If prediction failes (it could happen for suclasses), return */
            if (!isset($predictionOutput['predictions'][$idVal])) {
                if ($log) echo "Couldn't perform predictions on $className for $idVal\n";
                return [];
            }
            $predictedVal     = $predictionOutput['predictions'][$idVal];
            $storedRules      = $predictionOutput['storedRules'][$idVal];
            if ($predictionOutput['rules_measures'][$idVal] != "") {
                $ruleMeasures     = $predictionOutput['rules_measures'][$idVal];
            } else {
                if ($log) {
                    echo "No measures found for activated rule when predicting if $idVal" . PHP_EOL;
                }
            }
            $className        = $dataframe->reprClassVal($predictedVal);
            if ($log) {
                echo "Prediction: [$predictedVal] '$className' (using model '$model_name')" . PHP_EOL;
            }

            /**
             * I want the antecedents to have a more similar format to the json one in the database.
             * Remember: just the last array of antecedents, in fact, contains the activated rule.
             * But for un-normalized models, we want to keep track of the rules encountered before
             * its activation which haven't been activated.
             */
            $rulesAntecedents = [];
            foreach ($storedRules as $sr => $storedRule) {
                $rulesAntecedents[$sr] = [];
                /* I actually just need to store the antecedents. */
                $ruleAntecedents = $storedRule->getAntecedents();
                // error_log(Utils::get_var_dump($ruleAntecedents));
                foreach ($ruleAntecedents as $ra => $ruleAntecedent) {
                    $rulesAntecedents[$sr][$ra] =  $ruleAntecedent->serializeToArray();
                }
                // error_log(Utils::get_var_dump($rulesAntecedents));
            }

            /*
             * Clean rules aggregation
             */
            $storedActivatedAntecedents = end($rulesAntecedents);
            $storedUnactivatedAntecedentsGrouped = array_slice($rulesAntecedents, 0, -1);
            // error_log(Utils::get_var_dump($storedUnactivatedAntecedentsGrouped));
            $storedUnactivatedAntecedents = [];
            foreach($storedUnactivatedAntecedentsGrouped as $g) {
                foreach($g as $antecedent) {
                    // Invert antecedente (remember it didn't activate)
                    if ($antecedent["operator"] == " >= ") {
                        $antecedent["operator"] = " < ";
                    } else if ($antecedent["operator"] == " > ") {
                        $antecedent["operator"] = " <= ";
                    }else if ($antecedent["operator"] == " <= ") {
                        $antecedent["operator"] = " > ";
                    }else if ($antecedent["operator"] == " < ") {
                        $antecedent["operator"] = " >= ";
                    }else if ($antecedent["operator"] == " == ") {
                        $antecedent["operator"] = " != ";
                    }else if ($antecedent["operator"] == " != ") {
                        $antecedent["operator"] = " == ";
                    }
                    $storedUnactivatedAntecedents[] = $antecedent;
                }
            }

            $rulesAntecedents = array_merge($storedUnactivatedAntecedents, $storedActivatedAntecedents);
            $rulesAntecedents = array_unique($rulesAntecedents, SORT_REGULAR);
            $by_feature = [];

            foreach($rulesAntecedents as $feature => $antecedent) {
                $by_feature[$antecedent["feature"]][] = $antecedent;
            }

            // error_log(Utils::get_var_dump($storedUnactivatedAntecedents));
            $new_rulesAntecedents = [];
            foreach($by_feature as $feature => $antecedents) {
                $by_operator = [];
                foreach($antecedents as $antecedent) {
                    $by_operator[$antecedent["operator"]][] = $antecedent;
                }
                error_log(Utils::get_var_dump($by_operator));

                $new_antecedents = [];
                foreach($by_operator as $operator => $antecedents) {
                    $new_antecedents = array_merge($new_antecedents,
                            array_reduce($antecedents, function ($carry, $item) use ($operator) {
                            if (count($carry) == 0) {
                                return [$item];
                            } else if (in_array($operator, [" >= ", " > "])) {
                                $new_item = $item;
                                // error_log(Utils::get_var_dump($carry[0]));
                                $new_item["value"] = max($carry[0]["value"], $item["value"]);
                                return [$new_item];
                            } else if (in_array($operator, [" <= ", " < "])) {
                                $new_item = $item;
                                // error_log(Utils::get_var_dump($carry[0]));
                                $new_item["value"] = min($carry[0]["value"], $item["value"]);
                                return [$new_item];
                            } else if (in_array($operator, [" == "])) { # Shouldn't happen
                                // $new_item = $item;
                                // $new_item["operator"] = " in ";
                                // $new_item["value"] = "[" . $carry[0]["value"] . ", " . $item["value"] . "]";
                                // $new_item["value"] = "[" . $carry[0]["value"] . ", " . $item["value"] . "]";
                                return [$new_item];
                            } else if (in_array($operator, [" != "])) { # Shouldn't happen
                                // $new_item = $item;
                                // $new_item["operator"] = " not in ";
                                // $new_item["value"] = "[" . $carry[0]["value"] . ", " . $item["value"] . "]";
                                // $array_push = $carry[0]["value"];
                                // $new_item["value"] = [, $item["value"]];
                                array_push($carry, $item);
                                return $carry;
                            } else {
                                Utils::die_error("Uncaught operator value: " . Utils::get_var_dump($operator));
                                return [];
                            }
                        }, [])
                    );
                }
                // foreach($new_antecedents as $operator => $antecedents) {
                // }
                $new_rulesAntecedents = array_merge($new_rulesAntecedents, $new_antecedents);
            }
            $rulesAntecedents = $new_rulesAntecedents;
            $rulesAntecedents = [$rulesAntecedents];

            /* String associated with predicted value */
            $predictedStringVal = $model->getClassAttribute()->getDomain()[$predictedVal];

            /* Recursive step: recurse and predict the subtree of this predicted value */
            // TODO right now I'm not recurring when a "NO_" outcome happens. This is not supersafe, there must be a nice generalization.
            
            // error_log($dataframe->numInstances());
            // error_log($dataframe->toString());
            // error_log($model);

            //Update: not if class name, but if value starts with no_
            if (!Utils::startsWith($predictedStringVal, "NO_")) {
            
            //if (!Utils::startsWith($className, "NO_")) {
                /*$predictions[] = [[$dataframe->getClassAttribute()->getName(), $predictedVal],
                    $this->predictByIdentifier($idVal, array_merge($recursionPath, [[$i_prob, $className]]), $idModelVersion)];*/
                // $predictions[] = [
                //     [
                //         $dataframe->getClassAttribute()->getName(),
                //         $predictedVal,
                //         $rulesAntecedents,
                //         $ruleMeasures
                //     ],
                //     $this->predictByIdentifier($idVal, array_merge($recursionPath, [[$i_prob, $className]]),
                //     $idModelVersion)
                // ];
                $prediction['class'] = DBFit::cleanClassName($dataframe->getClassAttribute()->getName());
                $prediction['predictedValue'] = $predictedStringVal;
                /**
                 * Only the last sub-array contains the activated rules, while the previous rules
                 * are the rules which haven't been activated.
                 * The full rule would be the activated rule plus the conjunction of the negated precious rules.
                 */
                if (empty($rulesAntecedents)) {
                    $prediction['rules'] = [[]];
                }
                else {
                    $prediction['rules'] = $rulesAntecedents;
                }
                $prediction['rule_stats'] = $ruleMeasures;
                $prediction['subclasses'] = $this->predictByIdentifier(
                    $idVal,
                    array_merge($recursionPath, [[$i_prob, $predictedStringVal]]),
                    $idModelVersion,
                    $log,
                    $timing
                );

                $predictions[] = $prediction;
            }
        }

        /* At root level, finally prints the whole prediction tree */
        if ($recursionLevel == 0 && $log == true) {
            echo "Predictions: " . PHP_EOL;
            foreach ($predictions as $i_prob => $pred) {
                echo "[$i_prob]: " . Utils::toString($pred) . PHP_EOL;
            }
            echo PHP_EOL;
        }

        if ($timing)
            $end = microtime(TRUE);
        if ($timing)
            echo "predictByIdentifier took " . ($end - $start) . " seconds to complete." . PHP_EOL;

        return $predictions;
    }

    /**
     * Usually, the retrieves className is in the form ClassAttr_ClassVal,
     * but we usually want only the class value.
     */
    static protected function cleanClassName(string $className) : string
    {
        preg_match("/(.*)_(.*_.*)/", $className, $matches);
        if (empty($matches)) {
            preg_match("/(.*)_(.*)/", $className, $matches);
            if (empty($matches)) {
                return $className;
            } else {
                return $matches[2];
            }
            return $className;
        } else {
            return $matches[2];
        }
    }


    // TODO document from here
    // TODO use Nlptools
    function text2words(string $text, string $lang): array
    {
        // if ($text === NULL) {
        //   return [];
        // }
        $text = mb_strtolower($text);

        # to keep letters only (remove punctuation and such)
        $text = preg_replace('/[^a-z]+/i', '_', $text);

        # tokenize
        $words = array_filter(explode("_", $text));

        # remove stopwords
        $words = array_diff($words, $this->stop_words[$lang]);

        # lemmatize
        // lemmatize($text)

        # stem
        if ($lang == "en") {
            $words = array_map(["PorterStemmer", "Stem"], $words);
        }

        return $words;
    }

    /* Helpers */
    function getOutputColumnNames()
    {
        return array_map([$this, "getColumnName"], $this->outputColumns);
    }

    function getPathRepr(array $recursionPath): string
    {
        return Utils::array_list($recursionPath, ";");
    }

    static function isEnumType(string $mysql_type)
    {
        return preg_match("/^enum.*$/i", $mysql_type);
    }

    static function isTextType(string $mysql_type)
    {
        return preg_match("/^varchar.*$/i", $mysql_type) ||
            preg_match("/^text.*$/i", $mysql_type);
    }

    function getTableName(array $tab): string
    {
        return $tab["name"];
    }

    function &getTableJoinClauses(array $tab)
    {
        if (is_array($tab["joinClauses"])) {
            return $tab["joinClauses"];
        }
        $jc = array($tab["joinClauses"]);
        return $jc;
    }

    function pushTableJoinClause(array &$tab, string $clause)
    {
        $tab["joinClauses"][] = $clause;
    }

    function &getTableJoinType(array $tab)
    {
        return $tab["joinType"];
    }

    function getColumnName(array &$col, bool $force_no_table_name = false): string
    {
        $n = $col["name"];
        return $force_no_table_name && count(explode(".", $n)) > 1 ? explode(".", $n)[1] : $n;
    }

    function &getColumnTreatment(array &$col)
    {
        if ($col["treatment"] !== NULL)
            Utils::listify($col["treatment"]);
        $tr = &$col["treatment"];
        if ($tr === NULL) {
            if ($this->getColumnAttrType($col, $tr) === "text") {
                if ($this->defaultOptions["textTreatment"] !== NULL) {
                    $this->setColumnTreatment($col, $this->defaultOptions["textTreatment"]);
                } else {
                    Utils::die_error("A treatment for text fields is required. Please, specify one for column {$this->getColumnName($col)}, or set a default treatment for text fields using ->setDefaultOption(\"textTreatment\", ...). For example, ->setDefaultOption(\"textTreatment\", [\"BinaryBagOfWords\", 10])");
                }
                return $this->getColumnTreatment($col);
            } else if (in_array($this->getColumnAttrType($col, $tr), ["date", "datetime"])) {
                if ($this->defaultOptions["dateTreatment"] !== NULL) {
                    $this->setColumnTreatment($col, $this->defaultOptions["dateTreatment"]);
                } else {
                    Utils::die_error("A treatment for date fields is required. Please, specify one for column {$this->getColumnName($col)}, or set a default treatment for date fields using ->setDefaultOption(\"dateTreatment\", ...). For example, ->setDefaultOption(\"dateTreatment\", \"DaysSince\")");
                }
                return $this->getColumnTreatment($col);
            }
        }

        return $tr;
    }

    function getColumnTreatmentType(array &$col)
    {
        $tr = $this->getColumnTreatment($col);
        $t = !is_array($tr) ? $tr : $tr[0];
        return $t;
    }

    function getColumnTreatmentArg(array &$col, int $j)
    {
        $j = ($j < 0 ? $j : 1 + $j);
        $tr = $this->getColumnTreatment($col);
        return !is_array($tr) || !isset($tr[$j]) ? NULL : $tr[$j];
    }

    function getColumnNickname($col)
    {
        return $this->getColNickname($this->getColumnName($col));
    }

    function setColumnTreatment(array &$col, $val)
    {
        if ($val !== NULL) {
            if (is_array($val)) {
                Utils::listify($val);
                /** TODO in this moment, ForceCategorical are transformed in binary attributes too */
                /**
                 * The check on the number of elements in the domain prevents to change the attribute if it is
                 * already binary.
                 */
                /*if (($val[0] == "ForceCategorical" && (count($this->getColumnAttributes($col)) > 2))
                    || $val[0] == "ForceCategoricalBinary") {*/
                if ($val[0] == "ForceCategoricalBinary") {
                    $val = array_merge(["ForceSet", 0], array_slice($val, 1));
                }
            } else {
                /** TODO in this moment, ForceCategorical are transformed in binary attributes too */
                /*if (($val == "ForceCategorical" && (count($this->getColumnAttributes($col)) > 2))
                    || $val == "ForceCategoricalBinary") {*/
                if ($val == "ForceCategoricalBinary") {
                    $val = array_merge(["ForceSet", 0], array_slice([$val], 1));
                }
            }
        }

        $col["treatment"] = $val;
    }

    function getColumnAttrName(array &$col)
    {
        return $col["attrName"];
    }

    function getColumnMySQLType(array &$col)
    {
        return $col["mysql_type"];
    }

    function getColumnAttributes(array &$col, array $recursionPath): ?array
    {
        return isset($col["attributes"][$this->getPathRepr($recursionPath)]) ?
            $col["attributes"][$this->getPathRepr($recursionPath)] : NULL;
    }

    function setColumnAttributes(array &$col, array $recursionPath, ?array $attrs)
    {
        $col["attributes"][$this->getPathRepr($recursionPath)] = $attrs;
    }

    function getColumnTables(array &$col)
    {
        return $col["tables"];
    }

    function getColumnAttrType(array &$col, $tr = -1)
    {
        $mysql_type = $this->getColumnMySQLType($col);
        if (self::isEnumType($mysql_type)) {
            return "enum";
        } else if (self::isTextType($mysql_type)) {
            return "text";
        } else {
            if ($tr === -1) {
                $tr = $this->getColumnTreatmentType($col);
            }
            if (isset(self::$col2attr_type[$mysql_type])) {
                if (!isset(self::$col2attr_type[$mysql_type][strval($tr)])) {
                    Utils::die_error("Can't apply treatment " . Utils::toString($tr)
                        . " on column of type \"$mysql_type\" ({$this->getColumnName($col)})!");
                }
                return self::$col2attr_type[$mysql_type][strval($tr)];
            } else {
                Utils::die_error("Unknown column type: \"$mysql_type\"! Code must be expanded in order to cover this one!");
            }
        }
    }

    function setInputTables($inputTables): self
    {
        Utils::listify($inputTables);
        $this->inputTables = [];
        foreach ($inputTables as $table) {
            $this->addInputTable($table);
        }

        return $this;
    }

    function addInputTable($tab): self
    {
        if (!is_array($this->inputTables)) {
            Utils::die_error("Can't addInputTable at this time! Use ->setInputTables() instead.");
        }

        $this->inputTables[] = $this->readTable($tab);

        return $this;
    }

    function readTable($tab): array
    {
        $new_tab = [];
        $new_tab["name"] = NULL;
        $new_tab["joinClauses"] = [];
        $new_tab["joinType"] = count($this->inputTables) ? "INNER JOIN" : "";

        if (is_string($tab)) {
            $new_tab["name"] = $tab;
        } else if (is_array($tab)) {
            $new_tab["name"] = $tab[0];
            if (isset($tab[1])) {
                if (!count($this->inputTables)) {
                    Utils::die_error("Join criteria can't be specified for the first specified inputTable: "
                        . "\"{$tab[0]}\": ");
                }

                Utils::listify($tab[1]);
                $new_tab["joinClauses"] = $tab[1];
            }
            if (isset($tab[2])) {
                $new_tab["joinType"] = $tab[2];
            }
        } else {
            Utils::die_error("Malformed inputTable: " . Utils::toString($tab));
        }

        return $new_tab;
    }

    function setInputColumns($columns): self
    {
        if ($columns === "*") {
            /* Use all available columns */
            // Utils::warn("Warning! Using all available columns. Please double check that this is the desired choice.");
            return $this->setInputColumns($this->getAvailableColumns());
        } else {
            Utils::listify($columns);
            $this->inputColumns = [];
            foreach ($columns as $col) {
                $this->addInputColumn($col);
            }
        }
        return $this;
    }

    function addInputColumn($col): self
    {
        // TODO put this check everywhere?
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__ . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL . toString(func_get_args()), E_USER_WARNING);

        if (!count($this->inputTables)) {
            Utils::die_error("Must specify the concerning inputTables before the columns, through ->setInputTables() or ->addInputTable().");
        }

        if (!is_array($this->inputColumns)) {
            Utils::die_error("Can't addInputColumn at this time! Use ->setInputColumns() instead.");
        }

        $new_col = $this->readColumn($col);

        $this->check_columnName($new_col["name"]);

        $this->assignColumnMySQLType($new_col);
        // $this->assignColumnAttributes($new_col);

        $this->inputColumns[] = &$new_col;

        return $this;
    }

    function readColumn($col): array
    {
        $new_col = [];
        $new_col["name"] = NULL;
        $this->setColumnTreatment($new_col, NULL);
        $new_col["tables"] = [];
        $new_col["attrName"] = NULL;
        $new_col["mysql_type"] = NULL;

        if (is_string($col)) {
            $new_col["name"] = $col;
        } else if (is_array($col)) {
            if (!is_string($col[0])) {
                Utils::die_error("Malformed column name: " . Utils::toString($col[0])
                    . ". The name must be a string.");
            }
            $new_col["name"] = $col[0];
            if (isset($col[1])) {
                $this->setColumnTreatment($new_col, $col[1]);
            }
            if (isset($col[2])) {
                if (!is_string($col[2])) {
                    Utils::die_error("Malformed target attribute name for column: " . Utils::toString($col[2])
                        . ". The target name must be a string.");
                }
                $new_col["attrName"] = $col[2];
            }
        } else {
            Utils::die_error("Malformed column term: " . Utils::toString($col));
        }

        if ($new_col["attrName"] === NULL) {
            $new_col["attrName"] = $new_col["name"];
        }

        return $new_col;
    }

    function setOutputColumns($outputColumns): self
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        if ($outputColumns === NULL) {
            $this->outputColumns = [];
        } else {
            Utils::listify($outputColumns);
            $this->outputColumns = [];
            foreach ($outputColumns as $col) {
                $this->addOutputColumn($col);
            }
        }
        return $this;
    }

    function addOutputColumn($col): self
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        if (!count($this->inputColumns)) {
            Utils::die_error("You must set the input columns in use before the output columns.");
        }

        if (!is_array($this->outputColumns)) {
            Utils::die_error("Can't addOutputColumn at this time! Use ->setOutputColumns() instead.");
        }

        $new_col = [];
        $new_col["name"] = NULL;
        $this->setColumnTreatment($new_col, "ForceCategorical");
        $new_col["attributes"] = [];
        $new_col["tables"] = [];
        $new_col["attrName"] = NULL;
        $new_col["mysql_type"] = NULL;

        if (is_string($col)) {
            $new_col["name"] = $col;
        } else if (is_array($col)) {
            if (!is_string($col[0])) {
                Utils::die_error("Malformed output column name: " . Utils::get_var_dump($col[0])
                    . ". The name must be a string.");
            }
            $new_col["name"] = $col[0];

            if (isset($col[1])) {
                $these_tables = array_map([$this, "readTable"], $col[1]);
                // Avoid NULL values for the output columns. TODO note: assuming the last table is the one where the column comes from
                $this->pushTableJoinClause($these_tables[array_key_last($these_tables)], "!ISNULL(" . $new_col["name"] . ")");

                // tables also include all of the tables of the previous output layers? Can't think of a use-case, though
                $prev_tables = [];
                foreach ($this->outputColumns as $outputCol) {
                    $prev_tables = array_merge($prev_tables, $this->getColumnTables($outputCol));
                }
                $new_col["tables"] = array_merge($prev_tables, $these_tables);
            }
            if (isset($col[2])) {
                $this->setColumnTreatment($new_col, $col[2]);
            }
            if (isset($col[3])) {
                if (!is_string($col[3])) {
                    Utils::die_error("Malformed target attribute name for column: " . Utils::toString($col[3])
                        . ". The target name must be a string.");
                }
                $new_col["attrName"] = $col[3];
            }
        } else {
            Utils::die_error("Malformed output column term: " . Utils::toString($col));
        }

        if ($new_col["attrName"] === NULL) {
            $new_col["attrName"] = $new_col["name"];
        }

        $this->check_columnName($new_col["name"]);

        if ($this->identifierColumnName !== NULL
            && $new_col["name"] == $this->identifierColumnName) {
            Utils::die_error("Output column ('" . $new_col["name"]
                . "') cannot be used as identifier.");
        }

        for ($i_col = count($this->inputColumns) - 1; $i_col >= 0; $i_col--) {
            $col = $this->inputColumns[$i_col];
            if ($new_col["name"] == $this->getColumnName($col)) {
                // Utils::warn("Found output column '" . $new_col["name"] . "' in input columns. Removing...");
                array_splice($this->inputColumns, $i_col, 1);
                // die_error("Output column '" . $new_col["name"] .
                //   "' cannot also belong to inputColumns."
                //   // . get_var_dump($this->getInputColumnNames(true))
                //   );
            }
        }

        $this->assignColumnMySQLType($new_col);
        // $this->assignColumnAttributes($new_col);

        $this->outputColumns[] = &$new_col;

        return $this;
    }

    function getHierarchyDepth(): int
    {
        return count($this->outputColumns);
    }

    function check_columnName(string $colName): self
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        if (count($this->inputTables) > 1) {
            if (!preg_match("/.*\..*/i", $colName)) {
                Utils::die_error("Invalid column name: '"
                    . $colName . "'. When reading more than one table, "
                    . "please specify column names in their 'table_name.column_name' format.");
            }
        }

        return $this;
    }

    function assignColumnMySQLType(array &$column)
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        /* Obtain column type */
        $tables = array_merge($this->inputTables, $this->getColumnTables($column));

        /* Find column */
        $columnName = $column["name"];
        if (preg_match("/^\s*IF\s*\([^,]*,\s*(.*)\s*,\s*NULL\s*\)\s*$/i", $column["name"], $matches)) {
            $columnName = $matches[1];
        }

        $mysql_type = NULL;
        if (Utils::startsWith($columnName, "CONCAT", false)) {
            $mysql_type = "varchar";
        } else if (Utils::startsWith($columnName, "0+", false) || Utils::startsWith($columnName, "DATEDIFF", false)) {
            $mysql_type = "float";
        } else {
            // TODO use prepare statement here and then mysql_select
            //   https://www.php.net/manual/en/mysqli.prepare.php
            $sql = "SELECT * FROM `information_schema`.`columns` WHERE `table_name` IN "
                . Utils::mysql_set(array_map([$this, "aclai\\piton\\DBFit\\DBFit::getTableName"], $tables))
                . " AND (COLUMN_NAME = '" . $this->getColumnName($column) . "'"
                . " OR CONCAT(TABLE_NAME,'.',COLUMN_NAME) = '" . $this->getColumnName($column)
                . "')";
            $raw_data = DB::select(DB::raw($sql));
            //$raw_data = mysql_select($this->inputDB, $sql, true);
            foreach ($raw_data as $col) {
                if (in_array($columnName,
                    [$col->TABLE_NAME . "." . $col->COLUMN_NAME, $col->COLUMN_NAME])) {
                    $mysql_type = $col->COLUMN_TYPE;
                    break;
                }
            }
        }

        if ($mysql_type === NULL) {
            Utils::die_error("Couldn't retrieve information about column \""
                . $this->getColumnName($column) . "\"" . ($this->getColumnName($column) != $columnName ? " (-> \"$columnName\")" : "") . "." . PHP_EOL . "If it is an expression, please let me know the type. If it's a string, use CONCAT('', ...); if it's an integer, use 0+... .");
        }
        $column["mysql_type"] = $mysql_type;
    }

    /* TODO explain */
    function getModelName(array $recursionPath, ?int $i_prob, $short = false): string
    {
        $recursionLevel = count($recursionPath);
        if (!$short) {
            if ($i_prob !== NULL) {
                $currentLevelStr = str_replace(".", ".",
                    $this->getColumnAttributes($this->outputColumns[$recursionLevel], $recursionPath)[$i_prob]->getName());
                $out = str_replace("/", ".", $currentLevelStr);
            } else {
                $out = "";
            }
        } else {
            if ($i_prob !== NULL) {
              $out = strval($i_prob);
            } else {
                $out = "";
            }
        }
        return $out;
    }


    /*
     * Use the model for predicting the output attribute on a set of instances.
     * With multiple models, this requires a complex hierarchy of predictions and
     *  the computation of confusion matrices at different levels
     */
    // function predict(array $recursionPath = []) : array {
    //   echo "DBFit->predict(" . $testData->toString(true) . ", " . toString($recursionPath) . ")" . PHP_EOL;

    //   $recursionLevel = count($recursionPath);

    //   /* Recursion base case */
    //   if ($recursionLevel == $this->getHierarchyDepth()) {
    //     echo "Prediction-time recursion stops here due to reached bottom (recursionPath = " . toString($recursionPath) . ":" . PHP_EOL;
    //     return [];
    //   }

    //   &$this->outputColumns[$recursionLevel];
    //   $this->getColumnAttributes($outputColumn, $recursionPath)

    //   foreach ($this->generateDataframes($rawDataframe) as $i_prob => $dataframe) {
    //     echo "Problem $i_prob/" . $numDataframes . PHP_EOL;
    //     // $outputAttribute = $outputAttributes[$i_prob];
    //     $outputAttribute = $dataframe->getClassAttribute();

    //     /* If no data available, skip training */
    //     if (!$dataframe->numInstances()) {
    //       echo "Skipping node due to lack of data." . PHP_EOL;
    //       if ($recursionLevel == 0) {
    //         die_error("Training failed! No data instance found.");
    //       }
    //       continue;
    //     }
    //   }

    //   $model = $this->models...[array_key_last($this->models)];
    //   if (!($model instanceof DiscriminativeModel)) {
    //     die_error("Model is not initialized");
    //   }

    //   die_error("TODO check if predict still works");
    //   return $model->predict($inputData)["predictions"];
    // }

    /* DEBUG-ONLY - TODO remove */
    function test_all_capabilities()
    {
        echo "DBFit->test_all_capabilities()" . PHP_EOL;

        $start = microtime(TRUE);
        $this->updateModel();
        $end = microtime(TRUE);
        echo "updateModel took " . ($end - $start) . " seconds to complete." . PHP_EOL;

        echo "AVAILABLE MODELS:" . PHP_EOL;
        $this->listAvailableModels();
        // TODO
        // $start = microtime(TRUE);
        // $this->model->LoadFromDB($this->outputDB, str_replace(".", ":", $this->getOutputColumnAttributes()[0]))->getName();
        // $end = microtime(TRUE);
        // echo "LoadFromDB took " . ($end - $start) . " seconds to complete." . PHP_EOL;

        if ($this->identifierColumnName !== NULL) {
            $start = microtime(TRUE);
            $this->predictByIdentifier(1);
            $end = microtime(TRUE);
            echo "predictByIdentifier took " . ($end - $start) . " seconds to complete." . PHP_EOL;
        }
    }

    function listAvailableModels($model_tree = NULL, $indentation = 0)
    {
        if ($model_tree === NULL) {
            $model_tree = $this->models["subtree"];
        }
        // var_dump(count($model_tree));
        // echo "listAvailableModels(" . get_var_dump($model_tree) . ", $indentation)";
        foreach ($model_tree as $i_prob => $node) {
            // $model    = $node["model"];
            $name = $node["name"];
            $subtree = $node["subtree"];
            echo str_repeat("  ", $indentation) . "[$i_prob] \"$name\"" . PHP_EOL;
            $this->listAvailableModels($subtree, $indentation + 1);
        }
    }

    function showAvailableColumns()
    {
        echo "Available columns:" . array_reduce($this->getAvailableColumns(),
                function ($carry, $a) {
                    return $carry . PHP_EOL . "- " . $a;
                });
    }

    function getAvailableColumns()
    {
        // TODO: migrate to Laravel Query Builder
        /* Obtain column names from database */
        $sql = "SELECT * FROM `information_schema`.`columns` WHERE `table_name` IN "
            . mysql_set(array_map([$this, "getTableName"], $this->inputTables)) . " ";
        $raw_data = mysql_select($this->inputDB, $sql, true);

        $colsNames = [];
        foreach ($raw_data as $raw_col) {
            $colsNames[] = $raw_col["TABLE_NAME"] . "." . $raw_col["COLUMN_NAME"];
        }
        return $colsNames;
    }

    function setOutputColumnName(?string $outputColumnName, $treatment = "ForceCategorical"): self
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . toString(func_get_args()), E_USER_WARNING);

        if ($outputColumnName !== NULL) {
            return $this->setOutputColumns([[$outputColumnName, $treatment]]);
        } else {
            return $this->setOutputColumns([]);
        }
    }

    public function getIdentifierColumnName() : string
    {
        return $this->identifierColumnName;
    }

    function setIdentifierColumnName(?string $identifierColumnName): self
    {
        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        if ($identifierColumnName !== NULL) {
            if (in_array($identifierColumnName, $this->getOutputColumnNames())) {
                Utils::die_error("Identifier column ('{$identifierColumnName}')
                                 cannot be considered as the output column.");
            }
            $this->check_columnName($identifierColumnName);
        }
        $this->identifierColumnName = $identifierColumnName;
        return $this;
    }

    public function setWhereClauses(array $whereClauses, bool $predicting = false) : self
    {
        /* The retro-compatible case is hiddenly provided */
        if (!$predicting && array_keys($whereClauses)[0] === "default" && array_keys($whereClauses)[1] === "onlyTraining") {
            /* Training with both 'default' and 'onlyTraining' indexes*/    
            $whereClauses = [array_merge($whereClauses['default'], $whereClauses['onlyTraining'])];
        }
        else if (array_keys($whereClauses)[0] === "default") {
            /* Predicting, or training if only 'default' exists as an index */
            $whereClauses = [$whereClauses['default']];
        }

        if (func_num_args() > count(get_defined_vars())) trigger_error(__FUNCTION__
            . " was supplied more arguments than it needed. Got the following arguments:" . PHP_EOL
            . Utils::toString(func_get_args()), E_USER_WARNING);

        // TODO explain new hierachical structure, and make this more elastic
        Utils::listify($whereClauses);
        if (Utils::is_array_of_strings($whereClauses)) {
            $whereClauses = [$whereClauses];
        }

        foreach ($whereClauses as $whereClausesSet) {
            foreach ($whereClausesSet as $i => $jc) {
                if (!is_string($jc)) {
                    if (!(is_array($jc) && count($jc) > 2 && is_string($jc[0]) && is_string($jc[1])
                        && in_array(strtoupper($jc[1]), ["NOT IN", "IN"]))) {
                        Utils::die_error("Invalid whereClause at $i-th level: "
                            . Utils::get_var_dump($jc));
                    }
                }
            }
        }
        $this->whereClauses = $whereClauses;
        return $this;
    }

    function setOrderByClauses($_orderByClauses): self
    {
        $orderByClauses = [];
        foreach ($_orderByClauses as $_clause) {
            $clause = $_clause;
            if (!is_string($_clause)) {
                if (!is_array($_clause) || !is_string($_clause[0]) || !is_string($_clause[1])) {
                    Utils::die_error("An orderByClause has to be a string (e.g. a columnName) or an array
                                      [columnName, 'DESC']" . Utils::get_var_dump($_clause));
                }
            }
            $orderByClauses[] = $clause;
        }
        $this->orderByClauses = $orderByClauses;
        return $this;
    }

    function setLimit(?int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    function setLearner(Learner $learner): self
    {
        $this->learner = $learner;

        return $this;
    }

    function getLearner(): string
    {
        return $this->learner;
    }

    // Maybe it isn't used no more? TODO: check
    /*function setLearningMethod(string $learningMethod) : self
    {
        if (!($learningMethod == "PRip"))
            Utils::die_error("Only \"PRip\" is available as a learning method");

        $learner = new PRip();
        // TODO $learner->setNumOptimizations(20);
        $this->setLearner($learner);

        return $this;
    }*/

    function getTrainingMode()
    {
        if ($this->trainingMode === NULL) {
            $this->trainingMode = $this->defaultOptions["trainingMode"];
            echo "Training mode defaulted to " . Utils::toString($this->trainingMode);
        }
        return $this->trainingMode;
    }

    function setTrainingMode($trainingMode): self
    {
        $this->trainingMode = $trainingMode;
        return $this;
    }

    function getCutOffValue(): ?float
    {
        return $this->cutOffValue;
    }

    function setCutOffValue(float $cutOffValue): self
    {
        $this->cutOffValue = $cutOffValue;
        return $this;
    }

    function getExperimentID(): ?string
    {
        return $this->experimentID;
    }

    function setExperimentID(string $experimentID): self
    {
        $this->experimentID = $experimentID;
        return $this;
    }

    function setTrainingSplit(array $trainingMode): self
    {
        $this->setTrainingMode($trainingMode);
        return $this;
    }

    function setDefaultOption($opt_name, $opt): self
    {
        $this->defaultOptions[$opt_name] = $opt;
        return $this;
    }

    function setGlobalNodeOrder(array $globalNodeOrder): self
    {
        $this->globalNodeOrder = $globalNodeOrder;
        return $this;
    }

    function cmp_nodes($a, $b): int
    {
        $x = array_search($a, $this->globalNodeOrder);
        $y = array_search($b, $this->globalNodeOrder);
        if ($x === false && $y === false) {
            // Utils::warn("Nodes not found in globalNodeOrder array: " . PHP_EOL . Utils::get_var_dump($a) . PHP_EOL . "Please add the value to the globalNodeOrder parameter!" . PHP_EOL . Utils::get_var_dump($b) . PHP_EOL . Utils::get_var_dump($this->globalNodeOrder));
            return 0;
        } else if ($x === false) {
            // Utils::warn("Node not found in globalNodeOrder array: " . PHP_EOL . Utils::get_var_dump($a) . PHP_EOL . "Please add the value to the globalNodeOrder parameter!" . PHP_EOL . Utils::get_var_dump($this->globalNodeOrder));
            return 1;
        } else if ($y === false) {
            // Utils::warn("Node not found in globalNodeOrder array: " . PHP_EOL . Utils::get_var_dump($b) . PHP_EOL . "Please add the value to the globalNodeOrder parameter!" . PHP_EOL . Utils::get_var_dump($this->globalNodeOrder));
            return -1;
        }
        return $x - $y;
    }

    function getPredictionResults(): array
    {
        return $this->predictionResults;
    }

    // function setPredictionResults(array $predictionResults) : self
    // {
    //   $this->predictionResults = $predictionResults;
    //   return $this;
    // }


    // function &getRawDataSplit(array $rawDataframe) : array {
    //   list($final_attributes, $final_data, $outputAttributes) = $rawDataframe;

    //   list($trainData, $testData) = $this->getDataSplit(...Instances($final_data));

    //   $train_final_data = toarray($trainData);
    //   $test_final_data = toarray($testData);

    //   return [
    //           [$final_attributes, $train_final_data, $outputAttributes],
    //           [$final_attributes, $test_final_data, $outputAttributes]
    //         ];
    // }
    function &getDataSplit(Instances &$data): array
    {
        $trainingMode = $this->getTrainingMode();
        $rt = NULL;
        /* training modes */
        switch (true) {
            /* Full training: use data for both training and testing */
            case $trainingMode == "FullTraining":
                $rt = [$data, $data];
                break;

            /* Train+test split */
            case is_array($trainingMode):
                $trRat = $trainingMode[0] / ($trainingMode[0] + $trainingMode[1]);
                // $rt = Instances::partition($data, $trRat);
                $numFolds = 1 / (1 - $trRat);
                // echo $numFolds;
                $rt = RuleStats::stratifiedBinPartition($data, $numFolds);

                break;

            default:
                Utils::die_error("Unknown training mode: " . Utils::toString($trainingMode));
                break;
        }

        // TODO RANDOMIZE
        // echo "Randomizing!" . PHP_EOL;
        // srand(make_seed());
        // $rt[0]->randomize();

        return $rt;
    }

    /* TODO explain */
    function getInputColumns($IncludeIdCol = false)
    {
        $cols = [];
        foreach ($this->inputColumns as &$col) {
            $cols[] = &$col;
        }
        if ($IncludeIdCol && $this->identifierColumnName !== NULL) {
            if (!in_array($this->identifierColumnName, $this->getInputColumnNames(false))) {
                $cols[] = $this->readColumn($this->identifierColumnName);
            }
        }
        return $cols;
    }

    /* TODO explain */
    function getInputColumnNames($IncludeIdCol = false)
    {
        $cols = array_map([$this, "getColumnName"], $this->inputColumns);
        if ($IncludeIdCol && $this->identifierColumnName !== NULL) {
            if (!in_array($this->identifierColumnName, $cols)) {
                $cols[] = $this->identifierColumnName;
            }
        }
        return $cols;
    }

    private function setHierarchyModel(array $recursionPath, int $i_prob, DiscriminativeModel $model)
    {
        $name = "";
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
            // $className = $node[1];
            // $this->getColumnAttributes($this->outputColumns[$recursionLevel], array_slice($recursionPath, 0,
            //                            $recursionLevel))[$node[0]]->getName()
            // $node[1]
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;

        $recursionLevel = count($recursionPath);
        $name .= $this->getColumnAttributes($this->outputColumns[$recursionLevel],
            $recursionPath)[$i_prob]->getName();

        $subRecursionPath = array_merge($recursionPath, [[$i_prob, $name]]);

        $node = [
            "name" => $name,
            "model" => $model,
            "recursionPath" => $subRecursionPath,
            "subtree" => []
        ];
        Utils::arr_set_value($this->models, $modelKeyPath, $node);

        echo "setHierarchyModel(" . Utils::toString($recursionPath) . ")";
        // echo get_var_dump($this->models);
    }

    private function getHierarchyModel(array $recursionPath, int $i_prob): ?DiscriminativeModel
    {
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;
        $modelKeyPath[] = "model";

        return Utils::arr_get_value($this->models, $modelKeyPath, true);
    }

    //private function getHierarchyName(array $recursionPath, int $i_prob) : string {
    private function getHierarchyName(array $recursionPath, int $i_prob)
    {
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;
        $modelKeyPath[] = "name";

        return Utils::arr_get_value($this->models, $modelKeyPath, true);
    }

    function listHierarchyNodes($node = NULL, $maxdepth = -1)
    {
        // echo "listHierarchyNodes(" . toString($node) . ", $maxdepth)" . PHP_EOL;
        if ($maxdepth == 0) {
            return [];
        }
        $arr = [];
        if ($node === NULL) {
            $node = $this->models;
            $arr[$node["name"]] = [$node, []];
        }
        $childarr = [];

        foreach ($node["subtree"] as $i_prob => $childnode) {
            // $model    = $childnode["model"];
            $name = $childnode["name"];
            $subtree = $childnode["subtree"];
            $subRecursionPath = $childnode["recursionPath"];

            $arr[$childnode["name"]] = [$childnode, $subRecursionPath];

            $childarr = array_merge($childarr, $this->listHierarchyNodes($childnode, $maxdepth - 1));
        }

        return array_merge($arr, $childarr);
    }

    /**
     * Divides a categorical attribute with N different values in the domain into N binary categorical attributes.
     * @param $depth - The depth in the recursion tree.
     * @param $classes - The domain of the starting attribute.
     * @param $attrName - The name of the categorical attribute which is being divided.
     * @return array The resulting attributes.
     */
    protected function forceCategoricalBinary($depth, $classes, $attrName) : array
    {
        if ($depth === NULL) {
            $depth = 0;
        } else if ($depth == -1) {
            $depth = count($classes) - 1;
        }

        // echo "classes" . Utils::get_var_dump($classes);
        $powerClasses = Utils::powerSet($classes, false, $depth + 1);
        // echo "powerClasses" . Utils::get_var_dump($powerClasses);
        $attributes = [];
        //echo "Creating attributes for power domain: \n" . Utils::get_var_dump($powerClasses) . PHP_EOL;

        /* Create one attribute per set */
        foreach ($powerClasses as $classSet) {
            $cn = join(",", $classSet);
            if ($depth != 0) {
                $className = "{" . $cn . "}";
            } else {
                $className = $cn;
            }
            $a = new DiscreteAttribute(Utils::replaceDotsAndSpaces($attrName . '_' . $className), "bool",
                ["NO_" . Utils::replaceDotsAndSpaces($className), Utils::replaceDotsAndSpaces($className)]);
            $a->setMetadata($classSet);
            $attributes[] = $a;
        }
        return $attributes;
    }

  /** Functions to get information about the problem. */
  public function getInputTables() : array
  {
    return $this->inputTables;
  }
  public function getOutputColumns() : array
  {
    return $this->outputColumns;
  }
  public function getWhereClauses() : array
  {
    return $this->whereClauses;
  }
  public function getOrderByClauses() : array
  {
    return $this->orderByClauses;
  }
  public function getLimit() : ?int
  {
    return $this->limit;
  }
}
