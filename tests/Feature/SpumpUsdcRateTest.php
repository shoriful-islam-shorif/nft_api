<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Services\SolanaService;

class SpumpUsdcRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('spump_price_data'); // rate is cached 60s — clear between tests
    }

    public function test_it_reads_live_rate_from_jupiter(): void
    {
        $spumpMint = config('services.tokens.spump_mint');
        $usdcMint  = config('services.tokens.usdc_mint');

        // Fake exactly what Jupiter's /price/v3 endpoint returns.
        Http::fake([
            'lite-api.jup.ag/price/v3*' => Http::response([
                $spumpMint => ['usdPrice' => 0.0012, 'decimals' => 6],
                $usdcMint  => ['usdPrice' => 1.0,    'decimals' => 6],
            ], 200),
        ]);

        $rate = app(SolanaService::class)->getSpumpUsdcRate();

        $this->assertNotNull($rate);
        $this->assertEquals('jupiter', $rate['source']);
        // 1 USDC ($1.00) / 1 SPUMP ($0.0012) = ~833.33 SPUMP per USDC
        $this->assertEqualsWithDelta(833.333333, $rate['spump_per_usdc'], 0.01);
    }

    public function test_it_returns_null_when_jupiter_has_no_price_for_these_mints(): void
    {
        // This is exactly what happens with the current devnet mints —
        // Jupiter returns 200 OK but an empty object, since it has
        // never seen these tokens trade.
        Http::fake([
            'lite-api.jup.ag/price/v3*' => Http::response([], 200),
        ]);

        $rate = app(SolanaService::class)->getSpumpUsdcRate();

        $this->assertNull($rate);
    }

    public function test_buy_endpoint_correctly_converts_across_currencies(): void
    {
        $spumpMint = config('services.tokens.spump_mint');
        $usdcMint  = config('services.tokens.usdc_mint');

        Http::fake([
            'lite-api.jup.ag/price/v3*' => Http::response([
                $spumpMint => ['usdPrice' => 0.0012, 'decimals' => 6],
                $usdcMint  => ['usdPrice' => 1.0,    'decimals' => 6],
            ], 200),
        ]);

        $response = $this->getJson('/api/buy/spump-price');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'source'  => 'jupiter',
            ]);
    }
}
