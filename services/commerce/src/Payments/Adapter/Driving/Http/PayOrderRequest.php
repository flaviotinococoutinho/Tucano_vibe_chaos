<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

use Commerce\Payments\Application\PayOrderCommand;
use Commerce\Payments\Domain\CardToken;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

final class PayOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cardToken' => ['required', 'string', 'max:64']];
    }

    public function toCommand(string $orderId): PayOrderCommand
    {
        return new PayOrderCommand(
            IdempotencyKey::of((string) $this->header('Idempotency-Key')),
            $orderId,
            CardToken::of((string) $this->validated('cardToken')),
        );
    }
}
