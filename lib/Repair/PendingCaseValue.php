<?php

/**
 * Dossiq referenced case value backfill: one case waiting for its value.
 *
 * The case a backfill re-saves, and the value the save should materialise.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * One case a referenced-value backfill re-saves, and the value it should end up with.
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */
final class PendingCaseValue {

	/**
	 * Constructor.
	 *
	 * @param string $uuid  The case.
	 * @param string $value The value the save should materialise.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
