<?php

/**
 * Dossiq CaseTermBinding
 *
 * One case's clocks while CaseTermsService binds them: the case, the moment
 * they start, what its case type declares, and what the case carries for the
 * planned end.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The binding of one case's clocks, built and read by CaseTermsService only.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
final class CaseTermBinding {
	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * When the clocks start.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $start;

	/**
	 * The case type's declarations.
	 *
	 * @var array<string, mixed>
	 */
	public array $declared = [];

	/**
	 * The planned start date, `Y-m-d`, or ''.
	 *
	 * @var string
	 */
	public string $plannedStart = '';

	/**
	 * The `planned` term definition that resolved for the case, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $plannedDefinition = null;

	/**
	 * Start binding one case's clocks.
	 *
	 * @param string            $caseId The case UUID.
	 * @param DateTimeImmutable $start  When the clocks start.
	 */
	public function __construct(string $caseId, DateTimeImmutable $start) {
		$this->caseId = $caseId;
		$this->start = $start;
	}//end __construct()
}//end class
