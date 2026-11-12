<?php

/**
 * Dossiq TermEndAsk
 *
 * A computed term end and the calendar it is rolled on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;

/**
 * The date to roll, the calendar the term names and the subject's organisation.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
final class TermEndAsk {
	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $date         The computed end date.
	 * @param string|null       $calendarSlug The calendar named on the term, when any.
	 * @param string|null       $organisation The subject's organisation, when any.
	 */
	public function __construct(
		public readonly DateTimeImmutable $date,
		public readonly ?string $calendarSlug,
		public readonly ?string $organisation,
	) {
	}//end __construct()
}//end class
