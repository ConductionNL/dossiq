<?php

/**
 * Contract of TermResolution
 *
 * The public surface of TermResolution that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Service\TermKind;

/**
 * Contract of TermResolution.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermResolutionInterface {

	/**
	 * The definition a case gets, with the rule that produced it.
	 *
	 * @param string               $caseType The zaaktype slug.
	 * @param array<string, mixed> $context  The case's organisation, service and priority.
	 * @param string               $kind     Which clock is being resolved.
	 *
	 * @return array{definition: array<string, mixed>, resolvedFrom: string, snapshot: array<string, mixed>}|null
	 *         The resolution, or null when the case type declares no term at all.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-term-resolves-from-the-organisation-the-service-and-the-priority-req-tcf-02
	 */
	public function resolve(string $caseType, array $context, string $kind = TermKind::STATUTORY): ?array;

	/**
	 * The service norm a case gets, resolved by the same order as its term.
	 *
	 * A case type with no statutory term (a melding, a demo type) still has a
	 * clock the team works to. It is bound beside the statutory term, never as
	 * it, which is why it is asked for by its own kind.
	 *
	 * @param string               $caseType The zaaktype slug.
	 * @param array<string, mixed> $context  The case's organisation, service and priority.
	 *
	 * @return array<string, mixed>|null The `planned` definition, or null when none resolves.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function plannedDefinitionFor(string $caseType, array $context): ?array;
}//end interface
