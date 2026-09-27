<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as LumenHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Tucano\SharedKernel\Domain\DomainError;

final class Handler extends LumenHandler
{
    /**
     * Business outcomes (out of stock, invalid transition) are answers, not
     * incidents. The same goes for bad requests and unknown routes.
     *
     * @var list<class-string<Throwable>>
     */
    protected $dontReport = [
        DomainError::class,
        HttpException::class,
        ValidationException::class,
    ];

    /** @param Request $request */
    public function render($request, Throwable $error): Response
    {
        return ProblemDetails::from($error, $request);
    }
}
