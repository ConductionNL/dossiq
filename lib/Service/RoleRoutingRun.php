<?php

/**
 * One resolution of a routing rule against a case
 *
 * What RoleResolverService::resolve() carries between its steps once the
 * cache has missed: the strategy, the rule as the area rewrote it, the case
 * and the roles read for it.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one routing resolution.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md
 */
final class RoleRoutingRun {

	/**
	 * The registered strategy's name.
	 *
	 * @var string
	 */
	public string $strategy = '';

	/**
	 * The rule as the case's area rewrote it.
	 *
	 * @var array<string, mixed>
	 */
	public array $rule = [];

	/**
	 * The case.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * The case id the answer is remembered under.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The roles held on the case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $roles = [];
}//end class
