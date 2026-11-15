<?php

/**
 * Contract of WOODeadlineService
 *
 * The public surface of WOODeadlineService that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of WOODeadlineService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WOODeadlineServiceInterface {

	/**
	 * Extend a Woo request's term once, by the case type's extension period.
	 *
	 * Woo art. 4.4 lid 2 allows one extension of at most two weeks; the length
	 * is the definition's `extensionCapacity`. The extension goes through the
	 * term engine on the case's statutory term:
	 * the new end is rolled there, the one-extension ceiling is the term
	 * definition's and is read from the term instance (so it holds whatever
	 * the case schema keeps), and the case's deadline follows the term. The
	 * reason is the `verleng` event's rationale. Nothing is written onto the
	 * case by this service.
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array<string, mixed> The new deadline, with `expectedResolution` kept as an alias
	 *
	 * @throws RefusedException When there is no term to extend, or the extension is refused
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function extendDeadline(string $caseId, string $reason): array;
}//end interface
