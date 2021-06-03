<?php


namespace aclai\piton;

class Piton
{
    /**
     * @var array
     */
    protected $inputTables = [];

    /**
     * Check if Piton config file has been published and set.
     *
     * @return bool
     */
    public function configNotPublished(): bool
    {
        return is_null(config('piton'));
    }
}