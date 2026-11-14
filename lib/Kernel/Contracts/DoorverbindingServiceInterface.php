<?php

/**
 * Contract of DoorverbindingService
 *
 * The public surface of DoorverbindingService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of DoorverbindingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DoorverbindingServiceInterface {

	/**
	 * Mark a doorverbinding as accepted by the receiving specialist.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param string $callerUid The UID of the medewerker answering this transfer.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When already answered, caller is not the assigned recipient, or the update fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function acceptTransfer(string $doorverbindingId, string $callerUid = ''): array;

	/**
	 * Initiate a warm transfer and persist the doorverbinding record.
	 *
	 * @param array<string, mixed> $data The transfer fields.
	 *
	 * @return array<string, mixed> The created doorverbinding record.
	 *
	 * @throws RuntimeException When the schema is unconfigured or the write fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function initiateWarmTransfer(array $data): array;

	/**
	 * Mark a doorverbinding as rejected with a reason.
	 *
	 * @param string $doorverbindingId The doorverbinding UUID.
	 * @param string $reason The rejection reason.
	 * @param string $callerUid The UID of the medewerker answering this transfer.
	 *
	 * @return array<string, mixed> The updated record.
	 *
	 * @throws RuntimeException When already answered, caller is not the assigned recipient, reason missing, or update fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
	 */
	public function rejectTransfer(string $doorverbindingId, string $reason, string $callerUid = ''): array;
}//end interface
