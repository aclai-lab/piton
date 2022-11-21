<?php

namespace aclai\piton\Rules;

use aclai\piton\Instances\Instances;
use aclai\piton\Attributes\Attribute;
use aclai\piton\Facades\Utils;

/**
 * A single rule that predicts a specified value.
 *
 * A rule consists of antecedents "AND"-ed together and the consequent value
 */
abstract class Rule
{
    /** The internal representation of the value to be predicted */
    protected $consequent;

    /** The vector of antecedents of this rule */
    protected $antecedents;

    /** The measures for the rule. Can be empty if not computed. */
    protected $ruleMeasures;

    /** Constructor */
    function __construct(int $consequent = -1, array $antecedents = [])
    {
        $this->consequent = $consequent;
        $this->antecedents = $antecedents;
        $this->ruleMeasures = [];
    }

    public function getConsequent()
    {
        return $this->consequent;
    }

    public function setConsequent($consequent)
    {
        $this->consequent = $consequent;
        return $this;
    }

    public function getAntecedents(): array
    {
        return $this->antecedents;
    }

    public function setAntecedents(array $antecedents): self
    {
        $this->antecedents = $antecedents;
        return $this;
    }


    /**
     * Whether the instance is covered by this rule. Note that an empty rule covers everything.
     * @param Instances $data the set of instances.
     * @param int $instance_id the index of the instance in question.
     * @return bool The boolean value indicating whether the instance is covered by this rule.
     */
    function covers(Instances &$data, int $instance_id): bool
    {
        foreach ($this->antecedents as $antd) {
            if (!$antd->covers($data, $instance_id)) {
                return false;
            }
        }
        return true;
    }

    function coverage(Instances &$data, int $instance_id): array
    {
        $covers = [];
        foreach ($this->antecedents as $antd) {
            $covers[] = $antd->covers($data, $instance_id);
        }
        return $covers;
    }

    function coversAll(Instances &$data): bool
    {
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            if (!$this->covers($data, $instance_id)) {
                return false;
            }
        }
        return true;
    }

    function getNonCoveringSubRule(Instances &$data, int $instance_id, array $coverage): Rule
    {
        $rule_classname = get_class($this);
        $new_rule = new ClassificationRule($this->consequent);
        $nc_antecedents = [];
        foreach (Utils::zip($coverage, $this->antecedents) as $i => $z) {
            $covers = $z[0];
            $antd = $z[1];
            if (!$covers) {
                $nc_antecedents[$i] = $antd;
            }
        }
        $new_rule->setAntecedents($nc_antecedents);
        // return new $rule_classname($this->consequent, $nc_antecedents);
        return $new_rule;
    }

    /**
     * Whether this rule has antecedents, i.e. whether it is a "default rule"
     *
     * @return the boolean value indicating whether the rule has antecedents
     */
    function hasAntecedents(): bool
    {
        return ($this->antecedents !== NULL && $this->getSize() > 0);
    }

    /**
     * the number of antecedents of the rule
     */
    function getSize(): int
    {
        return count($this->antecedents);
    }

    function __clone()
    {
        $this->antecedents = array_map("clone_object", $this->antecedents);
    }

    /* Print a textual representation of the rule */
    function __toString(): string
    {
        return $this->toString();
    }

    public function setRuleMeasures(array $ruleMeasures)
    {
        $this->ruleMeasures = $ruleMeasures;
    }

    public function getRuleMeasures() : array
    {
        return $this->ruleMeasures;
    }

    abstract function toString(Attribute $classAttr = NULL): string;

    abstract static function fromString(string $str); // : _Rule;
}






