<?php

/**
 * OpenRegister's object service, with a logged failure when it cannot be had.
 *
 * Resolves the service through `$this->appManager` and `$this->container`,
 * and logs at error level through `$this->logger` when the container fails.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The installed object service, or null with a logged error.
 *
 * @spec exclude shared object service lookup; each using class carries its own spec
 */
trait ResolvesLoggedObjectService {

	/**
	 * Resolve the OpenRegister ObjectService if OpenRegister is installed.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The object service or null.
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('Dossiq: Could not get ObjectService', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end trait
