<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Conversion;

enum ReportOutcome: string
{
    /** The integration is off (no api_url / api_key, or `enabled: false`). Nothing stored. */
    case Disabled = 'disabled';
    /** Nothing to report: no click token and no coupon, or amount <= 0. Nothing stored. */
    case Skipped = 'skipped';
    /** Booked by AffiWave (HTTP 201). */
    case Reported = 'reported';
    /** AffiWave already had it (HTTP 200 duplicate). */
    case Duplicate = 'duplicate';
    /** Delivered earlier - no second request. */
    case AlreadyReported = 'already_reported';
    /** Permanent refusal (400/422...). Not retried. */
    case Rejected = 'rejected';
    /** Transient failure; stored and left for `affiwave:conversions:retry`. */
    case Failed = 'failed';
    /** A refund was accepted by AffiWave. */
    case Refunded = 'refunded';

    public function isSuccess(): bool
    {
        return \in_array($this, [self::Reported, self::Duplicate, self::AlreadyReported, self::Refunded], true);
    }
}
