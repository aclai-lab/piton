<?php

namespace aclai-lab\piton\Facades;

use Illuminate\Support\Facades\Facade;

class Piton extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'Piton';
    }
}