<?php

namespace App\Policies;

enum Ability: string
{

    CASE view = 'view';
    CASE viewAny = 'viewAny';
    CASE create = 'create';
    CASE update = 'update';
    CASE delete = 'delete';

}
