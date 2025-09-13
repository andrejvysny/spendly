<?php

namespace App\Contracts;

interface OwnedByUserContract
{

    public function getUserId(): int;
}
