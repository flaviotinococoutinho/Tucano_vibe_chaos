<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Application\RequestedItem;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\Division;
use Tucano\SharedKernel\Address\DivisionKind;
use Tucano\SharedKernel\Address\Thoroughfare;

/**
 * Checks the shape of the JSON. The domain value objects check the meaning
 * (a real e-mail, a postal code with eight digits, divisions in the order of
 * the hierarchy) when the command is built.
 */
final class PlaceOrderRequest extends FormRequest
{
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private const string STORE_SLUG = '/^[a-z][a-z0-9-]{1,30}$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The store the order is placed in (ADR 0031); whether each item is one of its products is for the use case to say.
            'store' => ['required', 'string', 'regex:' . self::STORE_SLUG],
            'customer' => ['required', 'array'],
            'customer.id' => ['required', 'string', 'regex:' . self::UUID_V7],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.email' => ['required', 'string', 'max:254'],
            'shippingAddress' => ['required', 'array'],
            'shippingAddress.thoroughfare' => ['required', 'array'],
            'shippingAddress.thoroughfare.type' => ['required', 'string', 'max:' . Thoroughfare::MAX_TYPE],
            'shippingAddress.thoroughfare.name' => ['required', 'string', 'max:' . Thoroughfare::MAX_NAME],
            // Text on purpose: KM 500 on a highway, S/N, 120-A. A JSON number is refused.
            'shippingAddress.number' => ['required', 'string', 'max:' . Address::MAX_NUMBER],
            'shippingAddress.complement' => ['nullable', 'string', 'max:' . Address::MAX_COMPLEMENT],
            'shippingAddress.divisions' => ['required', 'list', 'min:2', 'max:' . count(DivisionKind::cases())],
            'shippingAddress.divisions.*' => ['required', 'array'],
            'shippingAddress.divisions.*.kind' => ['required', 'string', Rule::enum(DivisionKind::class)],
            'shippingAddress.divisions.*.name' => ['required', 'string', 'max:' . Division::MAX_NAME],
            'shippingAddress.divisions.*.code' => ['nullable', 'string', 'max:20'],
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
        /** @var array{store: string, customer: array{id: string, name: string, email: string}, shippingAddress: array<mixed>, items: non-empty-list<array{sku: string, quantity: int|string}>} $data */
        $data = $this->validated();

        return new PlaceOrderCommand(
            IdempotencyKey::of((string) $this->header('Idempotency-Key')),
            StoreSlug::of($data['store']),
            Customer::of(
                CustomerId::fromString($data['customer']['id']),
                PersonName::of($data['customer']['name']),
                EmailAddress::of($data['customer']['email']),
            ),
            // The API takes the address in the shape the events carry it.
            Address::fromArray($data['shippingAddress']),
            array_map(
                static fn(array $item): RequestedItem => new RequestedItem(Sku::of($item['sku']), Quantity::of((int) $item['quantity'])),
                $data['items'],
            ),
        );
    }
}
