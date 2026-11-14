<?php

/**
 * Contract of DeadlineExtensionService
 *
 * The public surface of DeadlineExtensionService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of DeadlineExtensionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeadlineExtensionServiceInterface {

	/**
	 * Request an ordinary AWB 4:14 lid 1 verlenging on a TermijnInstance.
	 *
	 * Bound by the TermijnDefinitie's aantalVerlengingen ceiling.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 * @throws RefusedException When the move is longer than the case type declares.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array;

	/**
	 * Request a supervisor-approved AWB 4:14 lid 3 verlenging.
	 *
	 * Bypasses the TermijnDefinitie's aantalVerlengingen ceiling and is
	 * recorded with the supervisor grondslag and actor.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $rationale Non-empty reason.
	 * @param string $newEndDate New deadline (YYYY-MM-DD; must be > einddatumActueel).
	 * @param string $documentLink Optional document link (verlengingsbrief).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException With validation failures (cited AWB rule).
	 * @throws RefusedException When the move is longer than the case type declares.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function requestSupervisorExtension(
		string $termInstanceId,
		string $rationale,
		string $newEndDate,
		string $documentLink = '',
	): array;
}//end interface
