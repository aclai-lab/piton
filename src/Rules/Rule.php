<?php

namespace aclai-lab\piton\Rules;

use aclai-lab\piton\Instances\Instances;
use aclai-lab\piton\Attributes\Attribute;

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

    /** Constructor */
    function __construct(int $consequent = -1, array $antecedents = [])
    {
        $this->consequent = $consequent;
        $this->antecedents = $antecedents;
    }

    public function getConsequent()
    {
        return $this->consequent;
    }

    public function setConsequent($consequent): self
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

    function coversAll(Instances &$data): bool
    {
        foreach ($data->iterateInsts() as $instance_id => $inst) {
            if (!$this->covers($data, $instance_id)) {
                return false;
            }
        }
        return true;
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

    abstract function toString(Attribute $classAttr = NULL): string;

    abstract static function fromString(string $str); // : _Rule;
}






