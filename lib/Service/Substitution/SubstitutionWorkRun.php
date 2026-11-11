<?php

/**
 * One gathering of substituted work
 *
 * What SubstitutedWorkResolver::resolve() carries across the substitutions
 * it walks: where the cases are read, which statuses are final, which cases
 * and tasks were already routed, and the substitution in hand.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Substitution
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Substitution;

/**
 * The state of one gathering of substituted work.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class SubstitutionWorkRun {

	/**
	 * The ObjectService the cases are read through.
	 *
	 * @var object|null
	 */
	public ?object $objectService = null;

	/**
	 * The register id.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The case schema id; '' when cases are not read.
	 *
	 * @var string
	 */
	public string $caseSchema = '';

	/**
	 * The final status type ids, whose cases are never routed.
	 *
	 * @var array<int, string>
	 */
	public array $finalIds = [];

	/**
	 * The case ids already routed.
	 *
	 * @var array<string, bool>
	 */
	public array $seenCases = [];

	/**
	 * The task ids already routed.
	 *
	 * @var array<string, bool>
	 */
	public array $seenTasks = [];

	/**
	 * The substitution in hand.
	 *
	 * @var array<string, mixed>
	 */
	public array $sub = [];
}//end class
