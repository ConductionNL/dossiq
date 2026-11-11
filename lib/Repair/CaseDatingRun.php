<?php

/**
 * One pass of the lastActivityAt backfill.
 *
 * Built by `BackfillCaseLastActivity::run()` and never handed out: the object
 * service, register and case schema it reads and writes, the moment it dates
 * against, the cases still to date and the tally so far.
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTimeInterface;

/**
 * What one backfill pass reads, writes and counts with.
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
final class CaseDatingRun {
	/**
	 * The case schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The moment cases are dated against; a later date is not activity.
	 *
	 * @var DateTimeInterface|null
	 */
	public ?DateTimeInterface $now = null;

	/**
	 * The date each undated case gets, by case uuid.
	 *
	 * @var array<string, string>
	 */
	public array $todo = [];

	/**
	 * Cases dated, failed and already dated (or undatable).
	 *
	 * @var array{dated: int, failed: int, skipped: int}
	 */
	public array $tally = ['dated' => 0, 'failed' => 0, 'skipped' => 0];

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
