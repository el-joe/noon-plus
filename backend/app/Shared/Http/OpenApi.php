<?php

namespace App\Shared\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\In;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The OpenAPI 3.1 description of the public API (`/api/v1`, QC-044 /
 * MASTER_PLAN ADR 13), built from the routes themselves so it never drifts:
 * every path and method, path parameters (with their `where` patterns), the
 * `{market}` segment, bearer auth on signed-in routes, the
 * `Idempotency-Key` header where it is honoured, request bodies from Form
 * Requests (types, required, enums, limits) and the shared response
 * envelopes. `php artisan nawy:openapi` writes it to docs/api/openapi.json.
 */
class OpenApi
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $paths = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.Str::after($route->uri(), 'api/v1/');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Nawy marketplace API', 'version' => 'v1', 'description' => 'Generated from the Laravel routes (`php artisan nawy:openapi`). Every answer uses the envelope {data, meta}; every error {error: {code, message, fields}}.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum token from a sign-in endpoint (customer, delivery or ops scope).']],
                'parameters' => [
                    'market' => ['name' => 'market', 'in' => 'path', 'required' => true, 'description' => 'Market slug: {country}-{language}, e.g. uae-en, omn-ar.', 'schema' => ['type' => 'string', 'pattern' => '^[a-z]{3}-(en|ar)$']],
                    'idempotencyKey' => ['name' => Middleware\IdempotentRequest::HEADER, 'in' => 'header', 'required' => false, 'description' => 'Retry-safe key: the same key returns the first answer (header Idempotent-Replayed: true).', 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_:.\-]{8,100}$']],
                ],
                'schemas' => [
                    'Envelope' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => ['data' => [], 'meta' => ['type' => 'object']]],
                    'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]]]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        $middleware = $route->gatherMiddleware();
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = $name === 'market'
                ? ['$ref' => '#/components/parameters/market']
                : ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => array_filter(['type' => 'string', 'pattern' => isset($route->wheres[$name]) ? '^'.$route->wheres[$name].'$' : null])];
        }

        if (in_array(Middleware\IdempotentRequest::class, $middleware, true)) {
            $parameters[] = ['$ref' => '#/components/parameters/idempotencyKey'];
        }

        $controller = $route->getActionName();
        $operation = array_filter([
            'operationId' => $route->getName() ?? strtolower($method).' '.$route->uri(),
            'tags' => [$this->tag($controller)],
            'summary' => $route->getName() === null ? null : Str::of($route->getName())->after('api.v1.')->replace(['.', '-'], ' ')->ucfirst()->toString(),
            'parameters' => $parameters === [] ? null : $parameters,
            'security' => in_array('auth:sanctum', $middleware, true) ? [['bearer' => []]] : null,
            'requestBody' => in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $this->body($controller) : null,
            'responses' => [
                '2XX' => ['description' => 'Success', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                'default' => ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ]);

        return $operation;
    }

    private function tag(string $controller): string
    {
        return preg_match('/Domains\\\\([A-Za-z]+)\\\\/', $controller, $match) === 1 ? $match[1] : 'Platform';
    }

    /**
     * The request body from the action's Form Request, when it has one.
     *
     * @return array<string, mixed>|null
     */
    private function body(string $controller): ?array
    {
        if (! str_contains($controller, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controller);

        try {
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                    $request = (new \ReflectionClass($type->getName()))->newInstanceWithoutConstructor();

                    return ['required' => true, 'content' => ['application/json' => ['schema' => $this->schema((array) $request->rules())]]];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function schema(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            if (str_contains((string) $field, '.')) {
                continue;
            }

            $list = is_string($fieldRules) ? explode('|', $fieldRules) : (array) $fieldRules;
            $strings = array_values(array_filter($list, 'is_string'));
            $property = ['type' => match (true) {
                in_array('integer', $strings, true) => 'integer',
                in_array('numeric', $strings, true) => 'number',
                in_array('boolean', $strings, true), in_array('accepted', $strings, true) => 'boolean',
                in_array('array', $strings, true) => 'array',
                default => 'string',
            }];

            foreach ($strings as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

                match ($name) {
                    'email' => $property['format'] = 'email',
                    'date' => $property['format'] = 'date-time',
                    'max' => $property[$property['type'] === 'string' ? 'maxLength' : ($property['type'] === 'array' ? 'maxItems' : 'maximum')] = (int) $argument,
                    'min' => $property[$property['type'] === 'string' ? 'minLength' : ($property['type'] === 'array' ? 'minItems' : 'minimum')] = (int) $argument,
                    'in' => $property['enum'] = explode(',', (string) $argument),
                    'nullable' => $property['type'] = [$property['type'], 'null'],
                    default => null,
                };
            }

            foreach ($list as $rule) {
                if ($rule instanceof In) {
                    $property['enum'] = array_map(fn (string $value): string => trim($value, '"'), explode(',', Str::after((string) $rule, 'in:')));
                }
            }

            if (in_array('required', $strings, true)) {
                $required[] = $field;
            }

            $properties[$field] = $property;
        }

        return array_filter(['type' => 'object', 'properties' => $properties === [] ? null : $properties, 'required' => $required === [] ? null : $required]);
    }
}
