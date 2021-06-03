<?php

namespace aclai\piton\Facades;

use Illuminate\Support\Facades\Facade;

class Piton extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'Piton';
    }
}