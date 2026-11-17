<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Conversion;

use AffiWave\Bundle\Entity\AffiWaveConversion;

final readonly class ReportResult
{
    public function __construct(
        public ReportOutcome $outcome,
        public ?AffiWaveConversion $conversion = null,
        public ?string $message = null,
        public ?int $httpStatus = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }
}
