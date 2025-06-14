<?php

namespace Tests\Unit;

use App\Enums\Currency;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    public function test_currency_symbols(): void
    {
        $this->assertSame('€', Currency::EUR->symbol());
        $this->assertSame('$', Currency::USD->symbol());
        $this->assertSame('£', Currency::GBP->symbol());
        $this->assertSame('Kč', Currency::CZK->symbol());
    }
}
