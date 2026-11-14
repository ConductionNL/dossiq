<?php

/**
 * Contract of CaseAssistantService
 *
 * The public surface of CaseAssistantService that other components call, so they depend
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

use Exception;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;

/**
 * Contract of CaseAssistantService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAssistantServiceInterface {

	/**
	 * Run one conversational turn on a case.
	 *
	 * @param string $userId The authenticated caller's user id.
	 * @param string $caseId The case id (must be readable by `$userId`).
	 * @param string $message The user's message text.
	 *
	 * @return array{reply: string, usage: array<string, int|float>}
	 *
	 * @throws Exception On validation failure (400) or an unreadable/unknown case (404).
	 * @throws HermiqAssistantException When Hermiq is unavailable or refuses the turn.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function converse(string $userId, string $caseId, string $message): array;
}//end interface
