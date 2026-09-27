<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Application\RequestedItem;
use Commerce\Ordering\Domain\Address\BrazilianState;
use Commerce\Ordering\Domain\Address\Coordinates;
use Commerce\Ordering\Domain\Address\PostalCode;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checks the shape of the JSON. The domain value objects check the meaning
 * (a real e-mail, a postal code with eight digits) when the command is built.
 */
final class PlaceOrderRequest extends FormRequest
{
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer' => ['required', 'array'],
            'customer.id' => ['required', 'string', 'regex:' . self::UUID_V7],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.email' => ['required', 'string', 'max:254'],
            'shippingAddress' => ['required', 'array'],
            'shippingAddress.street' => ['required', 'string', 'max:160'],
            'shippingAddress.number' => ['required', 'string', 'max:16'],
            'shippingAddress.complement' => ['nullable', 'string', 'max:80'],
            'shippingAddress.district' => ['required', 'string', 'max:80'],
            'shippingAddress.city' => ['required', 'string', 'max:80'],
            'shippingAddress.state' => ['required', 'string', Rule::enum(BrazilianState::class)],
            'shippingAddress.postalCode' => ['required', 'string', 'max:9'],
            'shippingAddress.latitude' => ['nullable', 'numeric', 'required_with:shippingAddress.longitude'],
            'shippingAddress.longitude' => ['nullable', 'numeric', 'required_with:shippingAddress.latitude'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.sku' => ['required', 'string', 'max:32'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10'],
        ];
    }

    public function toCommand(): PlaceOrderCommand
    {
        /** @var array{customer: array{id: string, name: string, email: string}, shippingAddress: array<string, mixed>, items: non-empty-list<array{sku: string, quantity: int|string}>} $data */
        $data = $this->validated();
        $address = $data['shippingAddress'];

        return new PlaceOrderCommand(
            IdempotencyKey::of((string) $this->header('Idempotency-Key')),
            new Customer(
                CustomerId::fromString($data['customer']['id']),
                PersonName::of($data['customer']['name']),
                EmailAddress::of($data['customer']['email']),
            ),
            new ShippingAddress(
                (string) $address['street'],
                (string) $address['number'],
                isset($address['complement']) ? (string) $address['complement'] : null,
                (string) $address['district'],
                (string) $address['city'],
                BrazilianState::from((string) $address['state']),
                PostalCode::of((string) $address['postalCode']),
                isset($address['latitude'], $address['longitude'])
                    ? new Coordinates((float) $address['latitude'], (float) $address['longitude'])
                    : null,
            ),
            array_map(
                static fn(array $item): RequestedItem => new RequestedItem(Sku::of($item['sku']), Quantity::of((int) $item['quantity'])),
                $data['items'],
            ),
        );
    }
}
