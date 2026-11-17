<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Entity;

enum ConversionStatus: string
{
    /** Stored, not delivered yet. */
    case Pending = 'pending';
    /** AffiWave booked it (HTTP 201). */
    case Reported = 'reported';
    /** AffiWave already had it (HTTP 200, `duplicate: true`). */
    case Duplicate = 'duplicate';
    /** Permanent refusal (400/422...) - retrying will not help. */
    case Rejected = 'rejected';
    /** Transient failure (outage, timeout, 429, 401/403 until the key/plan is fixed) - retried by the command. */
    case Failed = 'failed';

    public function isDelivered(): bool
    {
        return self::Reported === $this || self::Duplicate === $this;
    }
}
