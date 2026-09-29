<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** HTTP only: the stores the platform hosts, which the BFF turns into the platform home. */
final readonly class StoreController
{
    private const int JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private StoreService $stores) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['stores' => $this->stores->all()], Response::HTTP_OK, [], self::JSON_OPTIONS);
    }

    public function show(string $store): JsonResponse
    {
        return new JsonResponse($this->stores->show($store), Response::HTTP_OK, [], self::JSON_OPTIONS);
    }
}
