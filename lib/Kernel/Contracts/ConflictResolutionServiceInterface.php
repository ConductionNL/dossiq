<?php

/**
 * Contract of ConflictResolutionService
 *
 * The public surface of ConflictResolutionService that other components call, so they depend
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

use OCA\Dossiq\Service\Sync\ConflictResolutionOutcome;

/**
 * Contract of ConflictResolutionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConflictResolutionServiceInterface {

	/**
	 * The device's version wins.
	 *
	 * @var string
	 */
	public const CLIENT_WINS = 'client_wins';

	/**
	 * The office's version wins.
	 *
	 * @var string
	 */
	public const SERVER_WINS = 'server_wins';

	/**
	 * Resolve one conflict record for the signed-in user.
	 *
	 * @param string $id         The conflict record's id.
	 * @param string $resolution `client_wins` or `server_wins`.
	 * @param string $userId     The session user's uid.
	 *
	 * @return ConflictResolutionOutcome The status and body to answer with.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function resolve(string $id, string $resolution, string $userId): ConflictResolutionOutcome;
}//end interface
