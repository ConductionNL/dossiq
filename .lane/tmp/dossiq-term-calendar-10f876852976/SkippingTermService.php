<?php

declare(strict_types=1);

namespace OCA\Dossiq\Fixture;

use DateTimeImmutable;

/**
 * A statutory path that computes a beslistermijn and consults nothing.
 */
class SkippingTermService {
	public function computeBeslistermijn(DateTimeImmutable $start, int $days): string {
		return $start->modify('+' . $days . ' days')->format('Y-m-d');
	}
}
