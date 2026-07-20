<?php

declare(strict_types=1);

namespace Isapp\CashierRevolut\Http\Responses;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A subscription billing cycle (OpenAPI Subscription-Cycle): the active
 * cycle's end_date is the customer's paid-through timestamp.
 */
#[MapInputName(SnakeCaseMapper::class)]
/**
 * Revolut response payload: CycleResponse.
 *
 * @internal The shape of a Revolut response, which is Revolut's to change and not ours to freeze — a new API version may add, rename or drop fields within a minor release. Reached only through RevolutGateway. Not public surface: outside the backward-compatibility promise in README.
 */
class CycleResponse extends Data
{
    public function __construct(
        public string $id,
        public ?string $state = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $startDate = null,
        #[WithCast(DateTimeInterfaceCast::class, format: RevolutDateFormats::FORMATS)]
        public ?CarbonImmutable $endDate = null,
    ) {}
}
