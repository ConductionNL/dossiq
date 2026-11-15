<?php

/**
 * Dossiq Subsidie Schema Lookup.
 *
 * Resolves the ObjectService and the register and schema ids one subsidie
 * service stores its records under, refusing when OpenRegister is absent or
 * the schema is not configured. Each service names its own schema key and
 * its own refusal.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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

namespace OCA\Dossiq\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Where one subsidie service's records live in OpenRegister.
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
 */
final class SubsidieSchemaLookup {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Config + ObjectService resolution.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @param string $schemaConfigKey     The config key naming the schema.
	 * @param string $unconfiguredMessage The refusal when the register or schema is not configured.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
	 */
	public function resolve(string $schemaConfigKey, string $unconfiguredMessage): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException($unconfiguredMessage);
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
