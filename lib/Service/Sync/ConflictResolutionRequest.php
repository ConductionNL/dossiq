<?php

/**
 * The conflict record a resolve request is about, and what the user chose for it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sync
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
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sync;

/**
 * The conflict record a resolve request is about, and what the user chose for it.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
final class ConflictResolutionRequest {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register of the conflict record.
	 * @param string $id            The conflict record's id.
	 * @param string $resolution    `client_wins` or `server_wins`.
	 * @param string $userId        The session user's uid.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $id,
		public readonly string $resolution,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
