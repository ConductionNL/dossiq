<?php

declare(strict_types=1);

namespace OCA\Dossiq\Fixture;

use DateTimeImmutable;
use OCA\Dossiq\Service\TermijnTimerService;

/**
 * The same path, reaching the calendar through the engine bridge.
 */
class RollingTermService {
	public function __construct(private readonly ?TermijnTimerService $timerService = null) {
	}

	public function computeBeslistermijn(DateTimeImmutable $start, int $days): string {
		$end = $start->modify('+' . $days . ' days');

		return ($this->timerService?->rollTermEndFor(date: $end) ?? $end)->format('Y-m-d');
	}
}
