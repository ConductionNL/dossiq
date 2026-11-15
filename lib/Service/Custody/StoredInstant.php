<?php

/**
 * A moment as a case record stored it.
 *
 * The custody chain, the takeover requests and the incident log all store
 * moments as strings; this reads one back, and answers null rather than a
 * moment nobody wrote when the string is empty or unreadable.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use DateTimeImmutable;
use Throwable;

/**
 * Reads a stored moment, or null.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class StoredInstant {
	/**
	 * A moment, or null when the value is empty or unreadable.
	 *
	 * @param string $value The candidate.
	 *
	 * @return DateTimeImmutable|null The moment.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function parse(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parse()
}//end class
