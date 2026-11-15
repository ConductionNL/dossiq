<?php

/**
 * The case its attention markers are judged on, with the moment, related rows and standing markers every declaration reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The case its attention markers are judged on, with the moment, related rows and standing markers every declaration reads.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CaseUnderJudgement {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $case     The case being judged.
	 * @param DateTimeImmutable     $now      Today, for the conditions that ask.
	 * @param array<string, mixed>  $context  The related rows, from `contextFor()`.
	 * @param array<string, string> $standing The raisedAt of each standing marker, by id.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		public readonly array $case,
		public readonly DateTimeImmutable $now,
		public readonly array $context,
		public readonly array $standing,
	) {
	}//end __construct()
}//end class
