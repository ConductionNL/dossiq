<?php

/**
 * How far one open record is through its target.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

/**
 * One open record measured against its type's target.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class RiskMeasure {
	/**
	 * The open record.
	 *
	 * @var array<string, mixed>
	 */
	public array $record = [];

	/**
	 * Its case type id.
	 *
	 * @var string
	 */
	public string $typeId = '';

	/**
	 * The type's target, in days, as declared.
	 *
	 * @var mixed
	 */
	public mixed $target = null;

	/**
	 * Days since the start.
	 *
	 * @var integer
	 */
	public int $elapsed = 0;

	/**
	 * Days left on the running term; negative once past it.
	 *
	 * @var integer
	 */
	public int $remaining = 0;

	/**
	 * The share of the running term used; an int when the division is exact, as computed.
	 *
	 * @var integer|float
	 */
	public int|float $percentUsed = 0;
}//end class
