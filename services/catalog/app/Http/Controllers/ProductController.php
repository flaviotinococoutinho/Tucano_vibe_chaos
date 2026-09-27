<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Dimensions;
use App\Models\NewProduct;
use App\Models\Product;
use App\Models\ProductChanges;
use App\Services\ProductService;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/**
 * HTTP only: checks the shape of the input, calls ProductService and writes the
 * response. Lumen's integer rule also takes numeric strings, hence the casts.
 *
 * @phpstan-type PriceInput array{amount: int|string, currency: string}
 * @phpstan-type DimensionsInput array{lengthMm: int|string, widthMm: int|string, heightMm: int|string}
 */
final readonly class ProductController
{
    // The same formats the CHECK constraints enforce in MySQL.
    private const string SKU = '/^[A-Z0-9][A-Z0-9-]{2,31}$/';
    private const string SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const string CURRENCY = '/^[A-Z]{3}$/';
    // Weight and sizes are INT UNSIGNED columns.
    private const int INT_UNSIGNED_MAX = 4_294_967_295;
    private const int JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private ProductService $products,
        private ValidationFactory $validation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var array{category?: string, page?: int|string} $query */
        $query = $this->validate($request->query->all(), [
            'category' => ['sometimes', 'required', 'string', 'regex:' . self::SLUG],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        $page = $this->products->page($query['category'] ?? null, (int) ($query['page'] ?? 1));

        return new JsonResponse($page, Response::HTTP_OK, [], self::JSON_OPTIONS);
    }

    public function show(string $sku): JsonResponse
    {
        return self::product($this->products->show($sku));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{sku: string, name: string, category: string, price: PriceInput, weightGrams: int|string, dimensions: DimensionsInput} $input */
        $input = $this->validate($request->json()->all(), [
            'sku' => ['required', 'string', 'regex:' . self::SKU],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'regex:' . self::SLUG],
            'price' => ['required', 'array:amount,currency'],
            'weightGrams' => ['required', ...self::positiveInteger()],
            'dimensions' => ['required', 'array:lengthMm,widthMm,heightMm'],
            ...self::nestedRules(),
        ]);
        $product = $this->products->create(new NewProduct(
            $input['sku'],
            $input['name'],
            $input['category'],
            self::price($input['price']),
            (int) $input['weightGrams'],
            self::dimensions($input['dimensions']),
        ));

        return self::product($product, Response::HTTP_CREATED, ['Location' => '/v1/products/' . $product->sku]);
    }

    public function update(Request $request, string $sku): JsonResponse
    {
        $expectedVersion = self::expectedVersion($request);
        /** @var array{name?: string, category?: string, price?: PriceInput, weightGrams?: int|string, dimensions?: DimensionsInput} $input */
        $input = $this->validate($request->json()->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'category' => ['sometimes', 'required', 'string', 'regex:' . self::SLUG],
            'price' => ['sometimes', 'required', 'array:amount,currency'],
            'weightGrams' => ['sometimes', 'required', ...self::positiveInteger()],
            'dimensions' => ['sometimes', 'required', 'array:lengthMm,widthMm,heightMm'],
            ...self::nestedRules(),
        ]);
        $changes = new ProductChanges(
            $input['name'] ?? null,
            $input['category'] ?? null,
            isset($input['price']) ? self::price($input['price']) : null,
            isset($input['weightGrams']) ? (int) $input['weightGrams'] : null,
            isset($input['dimensions']) ? self::dimensions($input['dimensions']) : null,
        );

        return self::product($this->products->change($sku, $changes, $expectedVersion));
    }

    public function activate(string $sku): JsonResponse
    {
        return self::product($this->products->activate($sku));
    }

    public function discontinue(string $sku): JsonResponse
    {
        return self::product($this->products->discontinue($sku));
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, list<string>> $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        return $this->validation->make($data, $rules)->validate();
    }

    /** @return array<string, list<string>> */
    private static function nestedRules(): array
    {
        return [
            'price.amount' => ['required_with:price', 'integer', 'min:0'],
            'price.currency' => ['required_with:price', 'string', 'regex:' . self::CURRENCY],
            'dimensions.lengthMm' => ['required_with:dimensions', ...self::positiveInteger()],
            'dimensions.widthMm' => ['required_with:dimensions', ...self::positiveInteger()],
            'dimensions.heightMm' => ['required_with:dimensions', ...self::positiveInteger()],
        ];
    }

    /** @return list<string> */
    private static function positiveInteger(): array
    {
        return ['integer', 'min:1', 'max:' . self::INT_UNSIGNED_MAX];
    }

    /** The version the client read, from If-Match: null when it sent none, or "*" (any version). */
    private static function expectedVersion(Request $request): ?int
    {
        $ifMatch = trim((string) $request->headers->get('If-Match'));
        if ($ifMatch === '' || $ifMatch === '*') {
            return null;
        }
        if (preg_match('/^"(\d{1,18})"$/', $ifMatch, $match) !== 1) {
            throw new BadRequestHttpException('If-Match takes the ETag of the product: its version in quotes, like "3".');
        }

        return (int) $match[1];
    }

    /** @param PriceInput $price */
    private static function price(array $price): Money
    {
        return Money::of((int) $price['amount'], Currency::fromCode($price['currency']));
    }

    /** @param DimensionsInput $dimensions */
    private static function dimensions(array $dimensions): Dimensions
    {
        return new Dimensions((int) $dimensions['lengthMm'], (int) $dimensions['widthMm'], (int) $dimensions['heightMm']);
    }

    /** @param array<string, string> $headers */
    private static function product(Product $product, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            $product,
            $status,
            [...$headers, 'ETag' => sprintf('"%d"', $product->version)],
            self::JSON_OPTIONS,
        );
    }
}
