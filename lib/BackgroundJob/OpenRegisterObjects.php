<?php

/**
 * Dossiq OpenRegister objects
 *
 * OpenRegister as a background job sees it: the object service it reads and
 * writes through, once OpenRegister is installed and its object service is
 * there, and each search result as an array.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;

/**
 * Hands a background job OpenRegister's object service, and reads its results as arrays.
 *
 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
 */
final class OpenRegisterObjects {

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager      Tells whether OpenRegister is installed.
	 * @param SettingsService $settingsService Holds the object service.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The object service, or null when OpenRegister is not installed or its service is not there.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	public function objectService(): ?object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		return $this->settingsService->getObjectService();
	}//end objectService()

	/**
	 * The result as an array: an array as is, a serialisable entity serialised,
	 * any other object by its properties, anything else as [].
	 *
	 * @param mixed $result One search result.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	public function toArray(mixed $result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array)$result->jsonSerialize();
		}

		if (is_object($result) === true) {
			return (array)$result;
		}

		return [];
	}//end toArray()
}//end class
