<?php

/**
 * Dossiq ResolvesInstalledObjectService trait
 *
 * OpenRegister's object service out of the container when OpenRegister is
 * installed, or null when it is not installed or cannot be resolved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared object service lookup; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Throwable;

/**
 * Resolves OpenRegister's object service through `$this->appManager` and `$this->container`.
 *
 * @spec exclude shared object service lookup; each using class carries its own spec
 */
trait ResolvesInstalledObjectService {
	/**
	 * Resolve the OpenRegister ObjectService when available.
	 *
	 * @return mixed|null
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end trait
