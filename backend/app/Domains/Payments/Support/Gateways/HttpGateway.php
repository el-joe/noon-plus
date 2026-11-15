<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Models\CountryGatewayConfig;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for the real gateway adapters: credentials of the
 * country config, sandbox/live mode, the Laravel HTTP client with a timeout,
 * provider errors turned into {@see GatewayError}, and exact money
 * formatting (never floats).
 */
abstract class HttpGateway implements PaymentGateway
{
    public function __construct(protected CountryGatewayConfig $config) {}

    protected function isLive(): bool
    {
        return $this->config->mode === GatewayMode::Live;
    }

    /**
     * A required credential; a gateway without it cannot be used.
     */
    protected function credential(string $field): string
    {
        return $this->config->credential($field)
            ?? throw new GatewayError("The {$this->config->gateway->value} gateway is missing its {$field} credential.");
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('nawy.payments.http_timeout_seconds'))->acceptJson();
    }

    /**
     * Run a provider call and return its JSON; network and 4xx/5xx errors
     * become a GatewayError with the provider message.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    protected function call(callable $call): array
    {
        try {
            $response = $call();
            $response->throw();
        } catch (ConnectionException $exception) {
            throw new GatewayError("The {$this->config->gateway->value} gateway could not be reached.", previous: $exception);
        } catch (RequestException $exception) {
            $body = $exception->response->json();
            $message = is_array($body) ? (string) (data_get($body, 'error.message') ?? data_get($body, 'message') ?? data_get($body, 'description') ?? 'request refused') : 'request refused';

            throw new GatewayError("The {$this->config->gateway->value} gateway refused the request: {$message}", previous: $exception);
        }

        return (array) $response->json();
    }

    /**
     * Amount in major units with the currency's decimals, e.g. "45.500" OMR.
     */
    protected function decimal(Money $money): string
    {
        return (string) $money->getAmount();
    }

    /**
     * Amount in the currency's minor unit (fils, halalas, cents, baisa).
     */
    protected function minorUnits(Money $money): int
    {
        return $money->getMinorAmount()->toInt();
    }

    /**
     * Back from minor units to a major-unit decimal string.
     */
    protected function fromMinorUnits(int|string $minor, string $currency): string
    {
        return (string) Money::ofMinor($minor, $currency)->getAmount();
    }

    /**
     * JSON body in which the given placeholders become exact number literals
     * (some providers want `"cart_amount": 45.500` as a number; PHP floats
     * would not keep the decimals exact).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $numbers  placeholder => decimal string
     */
    protected function jsonWithNumbers(array $body, array $numbers): string
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($numbers as $placeholder => $decimal) {
            $json = str_replace('"'.$placeholder.'"', (string) BigDecimal::of($decimal), $json);
        }

        return $json;
    }
}
