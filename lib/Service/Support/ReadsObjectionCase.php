<?php

/**
 * Dossiq ReadsObjectionCase trait
 *
 * The case a stored objection belongs to.
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
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The case a stored objection belongs to.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsObjectionCase {
	/**
	 * The case an objection belongs to, as stored, or '' when it cannot be read.
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function storedObjectionCase(string $objectionId): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$objectionSchema = $this->settingsService->getConfigValue(key: 'bezwaar_schema');
		if ($register === '' || $objectionSchema === '') {
			return '';
		}

		$objection = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $objectionSchema,
			id: $objectionId
		);

		return (string)($objection['case'] ?? '');
	}//end storedObjectionCase()
}//end trait
