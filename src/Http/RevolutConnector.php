<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Isapp\CashierRevolut\Exceptions\RevolutApiException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Connector for the Revolut Merchant API.
 *
 * Its single job is producing a fully configured PendingRequest: base URL per
 * environment, bearer secret key, the required Revolut-Api-Version header, a
 * fresh Idempotency-Key, transient-only retries with exponential backoff,
 * call logging, and failures raised as RevolutApiException.
 *
 * The application-facing Http::revolut() macro delegates to the container
 * instance of this class, so decorating or re-binding it changes both paths.
 */
class RevolutConnector
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A preconfigured Revolut Merchant API request.
     *
     * $idempotencyKey identifies the OPERATION, not the request. A key minted here
     * per call protects the transport (->retry() re-sends the same PendingRequest,
     * so a transient failure keeps its key) and nothing above it: a queued job that
     * retries, or a caller that catches and retries, arrives with a brand-new key
     * and Revolut treats it as a brand-new charge or refund. Real money, twice.
     *
     * The random default stays for calls that supply nothing — a deterministic
     * default would be worse than none: Revolut explicitly allows several partial
     * refunds of one order, and deduplicating them would silently swallow the
     * second legitimate one.
     */
    public function request(?string $idempotencyKey = null): PendingRequest
    {
        $secretKey = $this->string('cashier-revolut.secret_key');

        if ($secretKey === '') {
            throw InvalidConfigurationException::missingKey('cashier-revolut.secret_key');
        }

        return $this->http
            ->baseUrl($this->baseUrl())
            ->withToken($secretKey)
            ->withHeaders([
                'Revolut-Api-Version' => $this->string('cashier-revolut.api_version', '2026-04-20'),
                'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid7(),
            ])
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config->get('cashier-revolut.http.timeout', 30))
            ->retry($this->backoff(), when: $this->isTransient(...), throw: false)
            ->beforeSending(function (Request $request): void {
                $this->logger->debug('Revolut API request', [
                    'method' => $request->method(),
                    'url' => $request->url(),
                ]);
            })
            ->throw(function (Response $response): void {
                $this->logger->warning('Revolut API request failed', ['status' => $response->status()]);

                throw RevolutApiException::fromResponse($response);
            });
    }

    private function baseUrl(): string
    {
        return (bool) $this->config->get('cashier-revolut.sandbox', false)
            ? 'https://sandbox-merchant.revolut.com/api'
            : 'https://merchant.revolut.com/api';
    }

    /**
     * Exponential backoff sleeps (ms), one entry per retry.
     *
     * @return array<int, int>
     */
    private function backoff(): array
    {
        $retries = max(1, (int) $this->config->get('cashier-revolut.http.retries', 2));

        return array_map(static fn (int $attempt): int => 200 * (2 ** $attempt), range(0, $retries - 1));
    }

    /**
     * Retry only transient failures — connection errors and retryable status
     * codes — never deterministic 4xx client errors.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
