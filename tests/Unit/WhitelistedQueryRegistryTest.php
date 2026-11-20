<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Nlq\WhitelistedQueryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WhitelistedQueryRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected WhitelistedQueryRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(WhitelistedQueryRegistry::class);
    }

    public function test_all_supported_intents_are_registered(): void
    {
        $this->assertCount(11, WhitelistedQueryRegistry::SUPPORTED_INTENTS);

        foreach (WhitelistedQueryRegistry::SUPPORTED_INTENTS as $intent) {
            $res = $this->registry->execute($intent, ['limit' => 5]);
            $this->assertArrayHasKey('columns', $res);
            $this->assertArrayHasKey('rows', $res);
            $this->assertArrayHasKey('count', $res);
            $this->assertArrayHasKey('execution_time_ms', $res);
            $this->assertLessThanOrEqual(5, $res['count']);
        }
    }

    public function test_row_limit_cannot_exceed_hard_max(): void
    {
        $res = $this->registry->execute('top_selling_products', ['limit' => 9999]);
        $this->assertLessThanOrEqual(50, $res['count']);
    }

    public function test_unknown_intent_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->execute('drop_database_users');
    }
}
