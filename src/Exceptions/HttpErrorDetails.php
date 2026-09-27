<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Illuminate\Http\Client\RequestException;
use Throwable;

final class HttpErrorDetails
{
    /**
     * The HTTP status and response body from the exception chain, each taken
     * from the outermost exception that has it.
     *
     * @return array{status: ?int, body: ?string}
     */
    public static function extract(?Throwable $error): array
    {
        $status = null;
        $body = null;

        for ($e = $error; $e !== null && ($status === null || $body === null); $e = $e->getPrevious()) {
            if ($e instanceof ProviderException) {
                $status ??= $e->status;
                $body ??= $e->responseBody;
            }

            if ($e instanceof RequestException) {
                $status ??= $e->response->status();
                $body ??= $e->response->body();
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    public static function status(?Throwable $error): ?int
    {
        return self::extract($error)['status'];
    }
}
