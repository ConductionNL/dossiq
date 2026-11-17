<?php

/**
 * Dossiq RequiresObjectService trait
 *
 * OpenRegister's object service read off the class's settings service, or a
 * refusal when OpenRegister is not available.
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

use RuntimeException;

/**
 * Resolves OpenRegister's object service through `$this->settingsService`.
 *
 * @spec exclude shared object service lookup; each using class carries its own spec
 */
trait RequiresObjectService {
	/**
	 * OpenRegister's object service, or an exception when it is absent.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function requireObjectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end requireObjectService()
}//end trait
