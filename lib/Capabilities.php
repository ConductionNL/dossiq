<?php

/**
 * What dossiq announces through the Nextcloud capabilities API.
 *
 * `conflictResolution` tells the sync frame's conflict card that the resolve
 * endpoint is there; without it the card keeps its buttons disabled.
 *
 * @category Capabilities
 * @package  OCA\Dossiq
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

namespace OCA\Dossiq;

use OCP\Capabilities\ICapability;

/**
 * Dossiq's capabilities.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
class Capabilities implements ICapability {

	/**
	 * The capabilities dossiq announces.
	 *
	 * @return array<string, array<string, bool>> Keyed by app id.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function getCapabilities(): array {
		return [
			'dossiq' => [
				'conflictResolution' => true,
			],
		];
	}//end getCapabilities()
}//end class
