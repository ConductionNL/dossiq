<?php

/**
 * Dossiq parafeer voorstel repository.
 *
 * Resolves the (register, voorstel schema, parafeeractie schema) triple the
 * parafering action recorder works against, and loads a single voorstel by
 * UUID. Split out of ParafeerActieService so that service keeps action
 * recording and step advancement while the configuration lookup and the load
 * that must fail as a bad request live here.
 *
 * A missing register/schema is an exception, not an empty answer: silently
 * recording actions against an unconfigured register would lose them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
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
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCP\AppFramework\OCS\OCSBadRequestException;
use RuntimeException;

/**
 * Register/schema resolution and voorstel loads for the parafering actions.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */
class ParafeerVoorstelRepository {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config bridge to OpenRegister.
	 * @param ObjectArrayNormalizer $normalizer Collapses OpenRegister's array-or-entity shape.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ObjectArrayNormalizer $normalizer,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService, or null when OpenRegister is absent.
	 *
	 * Callers that can degrade (read paths) test for null; callers that cannot
	 * use {@see self::requireObjectService()}.
	 *
	 * @return object|null The ObjectService, or null.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function objectServiceOrNull(): ?object {
		return $this->settingsService->getObjectService();
	}//end objectServiceOrNull()

	/**
	 * Resolve the OpenRegister ObjectService, throwing when it is unavailable.
	 *
	 * @return object The ObjectService.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function requireObjectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end requireObjectService()

	/**
	 * Resolve the OpenRegister register and schemas from settings.
	 *
	 * @return array{0: string, 1: string, 2: string} [register, voorstelSchema, parafeeractieSchema]
	 *
	 * @throws RuntimeException When register/schemas are not configured.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function resolveSchemas(): array {
		$register = $this->settingsService->getConfigValue('register');
		$proposalSchema = $this->settingsService->getConfigValue('voorstel_schema');
		$actionSchema = $this->settingsService->getConfigValue('parafeeractie_schema');

		if (empty($register) === true || empty($proposalSchema) === true || empty($actionSchema) === true) {
			throw new RuntimeException('Dossiq register/schemas not configured');
		}

		return [(string)$register, (string)$proposalSchema, (string)$actionSchema];
	}//end resolveSchemas()

	/**
	 * Fetch a voorstel by UUID.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register identifier.
	 * @param string $schema The voorstel schema identifier.
	 * @param string $proposalId The voorstel UUID.
	 *
	 * @return array<string, mixed> The voorstel as an associative array.
	 *
	 * @throws OCSBadRequestException When the voorstel cannot be located.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function findVoorstel(
		object $objectService,
		string $register,
		string $schema,
		string $proposalId,
	): array {
		try {
			$proposal = $objectService->find($proposalId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			throw new OCSBadRequestException('Voorstel not found');
		}

		$array = $this->normalizer->toArray(value: $proposal);
		if (empty($array) === true) {
			throw new OCSBadRequestException('Voorstel not found');
		}

		return $array;
	}//end findVoorstel()
}//end class
