<?php

namespace App\Policies;

enum Ability: string
{
    case view = 'view';
    case viewAny = 'viewAny';
    case create = 'create';
    case update = 'update';
    case delete = 'delete';

}
