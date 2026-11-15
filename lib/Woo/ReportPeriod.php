<?php

/**
 * The days a Woo report counts: from a first to a last day, either open.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * A period of calendar days, `Y-m-d`, bounds included.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
final class ReportPeriod {
	/**
	 * Constructor.
	 *
	 * @param string|null $from The first day, or null for no lower bound.
	 * @param string|null $to   The last day, or null for no upper bound.
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}//end __construct()

	/**
	 * Whether a day lies in the period, its first and last day included.
	 *
	 * @param string $day The day, `Y-m-d`.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function contains(string $day): bool {
		if ($this->from !== null && $day < $this->from) {
			return false;
		}

		return ($this->to === null || $day <= $this->to);
	}//end contains()
}//end class
