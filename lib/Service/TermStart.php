<?php

/**
 * One statutory term being started on a new case
 *
 * The case and its type, the moment the term starts, the resolution that
 * picked its definition (when one did), and the definition itself: the state
 * TermijnService::createTermijnInstance() builds and its steps read.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one term start; it never leaves the service that built it.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */
final class TermStart {

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The case type.
	 *
	 * @var string
	 */
	public string $caseType = '';

	/**
	 * When the term starts.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $startDate;

	/**
	 * The resolution that picked the definition, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $resolution = null;

	/**
	 * The term definition.
	 *
	 * @var array<string, mixed>
	 */
	public array $definitie = [];

	/**
	 * The standard duration in days.
	 *
	 * @var integer
	 */
	public int $durationDays = 0;
}//end class
