<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Runs an API route's controller in-process for the MCP tools, so that
 * they share its validation, search and response with the HTTP API. The
 * route's middleware is skipped: the MCP endpoint has its own rate limit,
 * and the API's would count every tool call against one shared address.
 * A validation error, a missing model or a failed query comes back as
 * the API would render it (422 with the messages, 404, or 503 for a
 * search stopped for taking too long), for the tool to report.
 */
final class InternalApi
{
    public function __construct(
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
    ) {}

    /**
     * @param  array<string, mixed>  $query  As given by the AI client; the API validates it
     * @return array{status: int, body: array<string, mixed>}
     */
    public function get(string $path, array $query = []): array
    {
        $request = Request::create($path, 'GET', array_filter($query, fn (mixed $value): bool => $value !== null && $value !== ''));
        $request->headers->set('Accept', 'application/json');
        $previous = app('request');
        app()->instance('request', $request);

        try {
            $route = $this->router->getRoutes()->match($request);
            $request->setRouteResolver(fn () => $route);
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $response = $this->router->prepareResponse($request, $route->run());
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface|QueryException $exception) {
            $response = $this->exceptions->render($request, $exception);
        } finally {
            app()->instance('request', $previous);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /**
     * The message of a failed call: the validation messages, or the
     * API's message for the status.
     *
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    public static function errorMessage(array $result): string
    {
        $errors = $result['body']['errors'] ?? null;

        return is_array($errors)
            ? implode(' ', array_merge(...array_values(array_map(fn (mixed $messages): array => (array) $messages, $errors))))
            : (string) ($result['body']['message'] ?? "エラー（HTTP {$result['status']}）");
    }
}
