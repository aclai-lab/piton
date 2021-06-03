<?php

namespace aclai-lab\piton\DiscriminativeModels;

use aclai-lab\piton\Learners\Learner;
use aclai-lab\piton\Instances\Instances;

/*
 * Interface for a generic discriminative model
 */

abstract class DiscriminativeModel
{

    static $prefix = "models__m";
    static $indexTableName = "models__index";

    abstract function fit(Instances &$data, Learner &$learner);

    abstract function predict(Instances $testData);

    abstract function save(string $path);

    abstract function load(string $path);

    static function loadFromFile(string $path): DiscriminativeModel
    {
        if (DEBUGMODE > 2) echo "DiscriminativeModel::loadFromFile($path)" . PHP_EOL;
        postfixisify($path, ".mod");

        $str = file_get_contents($path);
        $obj_str = strtok($str, "\n");
        switch ($obj_str) {
            case "RuleBasedModel":
                $model = new RuleBasedModel();
                $model->load($path);
                break;

            default:
                die_error("Unknown model type in DiscriminativeModel::loadFromFile(\"$path\")" . $obj_str);
                break;
        }
        return $model;
    }

    /* Save model to database */
    function dumpToDB(object &$db, string $tableName)
    {
        if (DEBUGMODE)
            echo "DiscriminativeModel->dumpToDB('$tableName')" . PHP_EOL;

        $tableName = self::$prefix . $tableName;

        $sql = "DROP TABLE IF EXISTS `{$tableName}_dump`";

        $stmt = $db->prepare($sql);
        if (!$stmt)
            die_error("Incorrect SQL query: $sql");
        if (!$stmt->execute())
            die_error("Query failed: $sql");
        $stmt->close();

        $sql = "CREATE TABLE `{$tableName}_dump` (dump LONGTEXT)";

        $stmt = $db->prepare($sql);
        if (!$stmt)
            die_error("Incorrect SQL query: $sql");
        if (!$stmt->execute())
            die_error("Query failed: $sql");
        $stmt->close();

        $sql = "INSERT INTO `{$tableName}_dump` VALUES (?)";

        $stmt = $db->prepare($sql);
        $dump = serialize($this);
        // echo $dump;
        $stmt->bind_param("s", $dump);
        if (!$stmt)
            die_error("Incorrect SQL query: $sql");
        if (!$stmt->execute())
            die_error("Query failed: $sql");
        $stmt->close();

    }

    function &LoadFromDB(object &$db, string $tableName)
    {
        if (DEBUGMODE > 2) echo "DiscriminativeModel->LoadFromDB($tableName)" . PHP_EOL;

        $tableName = self::$prefix . $tableName;

        $sql = "SELECT dump FROM " . $tableName . "_dump";
        echo "SQL: $sql" . PHP_EOL;
        $stmt = $db->prepare($sql);
        if (!$stmt)
            die_error("Incorrect SQL query: $sql");
        if (!$stmt->execute())
            die_error("Query failed: $sql");
        $res = $stmt->get_result();
        $stmt->close();

        if (!($res !== false))
            die_error("SQL query failed: $sql");
        if ($res->num_rows !== 1) {
            die_error("Error reading RuleBasedModel table dump.");
        }
        $obj = unserialize($res->fetch_assoc()["dump"]);
        return $obj;
    }

    /* Print a textual representation of the rule */
    abstract function __toString(): string;
}

/*
 * This class represents a propositional rule-based model.
 */





