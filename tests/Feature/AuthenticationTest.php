<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'place order' => ['POST', '/api/v1/orders'],
            'list orders' => ['GET', '/api/v1/orders'],
            'show order' => ['GET', '/api/v1/orders/01a1ac6b-4068-701b-8c85-f68e3d07ef24'],
            'cancel order' => ['DELETE', '/api/v1/orders/01a1ac6b-4068-701b-8c85-f68e3d07ef24'],
            'wallet' => ['GET', '/api/v1/wallet'],
            'market summary' => ['GET', '/api/v1/market/summary'],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function every_endpoint_requires_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertStatus(401)
            ->assertExactJson(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid API token is required.', 'details' => []]]);
    }

    #[Test]
    public function an_invalid_token_is_rejected(): void
    {
        $this->getJson('/api/v1/wallet', ['Authorization' => 'Bearer not-a-real-token'])->assertStatus(401);
    }

    #[Test]
    public function a_real_sanctum_token_works_and_responses_carry_a_correlation_id(): void
    {
        $user = $this->trader('50');
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/wallet', ['Authorization' => "Bearer {$token}", 'X-Correlation-ID' => 'trace-12345678'])
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'trace-12345678')
            ->assertJsonPath('data.available_balance', '50.00000000');
    }

    #[Test]
    public function the_seeded_demo_tokens_work(): void
    {
        $this->seed();

        $alice = User::query()->where('email', 'alice@example.com')->firstOrFail();

        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer alice-demo-token-a1b2c3d4e5f6'])
            ->assertOk()
            ->assertJsonCount($alice->orders()->count(), 'data');
        $this->getJson('/api/v1/wallet', ['Authorization' => 'Bearer bob-demo-token-f6e5d4c3b2a1'])->assertOk();
        $this->getJson('/api/v1/wallet', ['Authorization' => 'Bearer carol-demo-token-0a9b8c7d6e5f'])->assertOk();
    }
}
