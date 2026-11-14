<?php

/**
 * Contract of FlowDecisionSubject
 *
 * The public surface of FlowDecisionSubject that other components call, so they depend
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

/**
 * Contract of FlowDecisionSubject.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FlowDecisionSubjectInterface {

	/**
	 * The case id when the event is a flow decision about a dossiq case, else ''.
	 *
	 * The event is Decidiq's and optional at runtime, so it is read by its
	 * getters rather than by type.
	 *
	 * @param object $event The concluded-decision event.
	 *
	 * @return string The case id, or ''.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function caseIdOf(object $event): string;
}//end interface
