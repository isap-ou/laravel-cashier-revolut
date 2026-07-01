<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Checkout;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Responsable;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Revolut checkout session backed by an order.
 *
 * The `token` is the order token consumed client-side by the Revolut Checkout
 * Widget. When a hosted `url` is present the session is Responsable and
 * redirects to it.
 */
class RevolutCheckoutSession implements CheckoutSession, Responsable
{
    public function __construct(
        private readonly string $id,
        private readonly ?string $token,
        private readonly CheckoutMode $mode,
        private readonly ?string $url,
        private readonly ?CarbonImmutable $expiresAt = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function mode(): CheckoutMode
    {
        return $this->mode;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    /**
     * The order token for the Revolut Checkout Widget.
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * {@inheritDoc}
     */
    public function toResponse($request): Response
    {
        if ($this->url !== null) {
            return redirect()->away($this->url);
        }

        return response()->json([
            'id' => $this->id,
            'token' => $this->token,
            'mode' => $this->mode->value,
        ]);
    }
}
