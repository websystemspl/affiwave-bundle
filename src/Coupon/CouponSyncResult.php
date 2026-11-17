<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Coupon;

enum CouponSyncResult: string
{
    case Created = 'created';
    /** Conditions changed: the old promotion code was deactivated and a new one created. */
    case Replaced = 'replaced';
    /** Only the status changed: the promotion code was toggled. */
    case Status = 'status';
    case Unchanged = 'unchanged';
    /** An older event than the one already applied. */
    case Stale = 'stale';
    /** A refusal a retry will not fix - see `affiwave_coupon.sync_error`. */
    case Error = 'error';
    /** Built-in synchronisation is off (`coupons.enabled: false`). */
    case Disabled = 'disabled';
}
