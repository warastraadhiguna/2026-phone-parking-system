<?php

namespace App\Support\Http;

use App\Support\Errors\ApiException;
use App\Support\Errors\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps any exception thrown while handling an API request to the standard error envelope.
 *
 * Unexpected exceptions become INTERNAL_ERROR with a generic message; details stay in the logs
 * (which carry the same request_id), never in the response.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->status),
            $e instanceof ValidationException => ApiResponse::error(
                ErrorCode::VALIDATION_FAILED,
                details: ['fields' => $e->errors()],
                status: $e->status,
            ),
            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::UNAUTHENTICATED),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error(ErrorCode::FORBIDDEN),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(ErrorCode::NOT_FOUND),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(ErrorCode::METHOD_NOT_ALLOWED, headers: $e->getHeaders()),
            $e instanceof ThrottleRequestsException => ApiResponse::error(ErrorCode::RATE_LIMITED, headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::fromHttpStatus($e),
            default => ApiResponse::error(ErrorCode::INTERNAL_ERROR),
        };
    }

    private static function fromHttpStatus(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match (true) {
            $status === 401 => ErrorCode::UNAUTHENTICATED,
            $status === 403 => ErrorCode::FORBIDDEN,
            $status === 404 => ErrorCode::NOT_FOUND,
            $status === 409 => ErrorCode::CONFLICT,
            $status === 429 => ErrorCode::RATE_LIMITED,
            $status === 503 => ErrorCode::SERVICE_UNAVAILABLE,
            $status >= 400 && $status < 500 => ErrorCode::BAD_REQUEST,
            default => ErrorCode::INTERNAL_ERROR,
        };

        return ApiResponse::error($code, status: $status, headers: $e->getHeaders());
    }
}
