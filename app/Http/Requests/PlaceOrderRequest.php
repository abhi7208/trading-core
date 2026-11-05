<?php

namespace App\Http\Requests;

use App\Data\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\User;
use App\Rules\DecimalString;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/v1/orders before the controller even runs.
 * If any rule fails, Laravel returns 422 automatically (formatted by bootstrap/app.php).
 */
class PlaceOrderRequest extends FormRequest
{
    private ?Asset $resolvedAsset = null;

    private bool $assetLoaded = false;

    public function authorize(): bool
    {
        return true; // auth:sanctum already guarantees a logged-in trader
    }

    /** Copy the Idempotency-Key HEADER into the data so it is validated like any field. */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $asset = $this->asset();

        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'asset' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($asset) {
                if ($asset === null) {
                    $fail('The selected asset does not exist.');
                } elseif (! $asset->is_tradable) {
                    $fail('The selected asset is not tradable.');
                }
            }],
            'side' => ['required', Rule::enum(OrderSide::class)],
            'type' => ['required', Rule::enum(OrderType::class)],
            'quantity' => ['required', new DecimalString($asset->quantity_precision ?? 8)],
            'limit_price' => [
                'nullable', 'required_if:type,LIMIT', 'prohibited_if:type,MARKET',
                new DecimalString($asset->price_precision ?? 8),
            ],
            'expires_at' => ['nullable', 'date', 'after:now', 'prohibited_if:type,MARKET'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['idempotency_key' => 'Idempotency-Key header'];
    }

    /** Find the asset by symbol once, and remember it. */
    public function asset(): ?Asset
    {
        if (! $this->assetLoaded) {
            $symbol = $this->input('asset');
            $this->resolvedAsset = is_string($symbol)
                ? Asset::query()->where('symbol', strtoupper($symbol))->first()
                : null;
            $this->assetLoaded = true;
        }

        return $this->resolvedAsset;
    }

    /** Turn the validated input into a typed DTO for the PlaceOrder action. */
    public function toData(): PlaceOrderData
    {
        /** @var Asset $asset */
        $asset = $this->asset();
        /** @var User $user */
        $user = $this->user();

        $quantity = Decimal::of($this->string('quantity')->toString());
        $limitPrice = $this->filled('limit_price') ? Decimal::of($this->string('limit_price')->toString()) : null;
        $expiresAt = $this->filled('expires_at') ? CarbonImmutable::parse($this->string('expires_at')->toString()) : null;
        $side = OrderSide::from($this->string('side')->toString());
        $type = OrderType::from($this->string('type')->toString());

        // Fingerprint of WHAT was asked for. Normalized, so "1.50" and "1.5" match.
        $requestHash = hash('sha256', (string) json_encode([
            'asset' => $asset->symbol,
            'side' => $side->value,
            'type' => $type->value,
            'quantity' => $quantity->normalized(),
            'limit_price' => $limitPrice?->normalized(),
            'expires_at' => $expiresAt?->utc()->toIso8601String(),
        ]));

        return new PlaceOrderData(
            userId: $user->id,
            assetId: $asset->id,
            side: $side,
            type: $type,
            quantity: $quantity,
            limitPrice: $limitPrice,
            expiresAt: $expiresAt,
            idempotencyKey: $this->string('idempotency_key')->toString(),
            requestHash: $requestHash,
            ipAddress: $this->ip(),
        );
    }
}
