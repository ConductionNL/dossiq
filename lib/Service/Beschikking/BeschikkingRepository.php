<?php

/**
 * Dossiq BeschikkingRepository.
 *
 * The OpenRegister persistence boundary for beschikking objects: resolving
 * the configured register/schema pair, reading one beschikking by id,
 * writing one back, and the "load or fail" variant every lifecycle
 * transition starts with.
 *
 * Split out of BeschikkingService so that service keeps only the lifecycle
 * orchestration. Reads are tolerant — an unavailable or unconfigured
 * OpenRegister yields null so a caller can answer 404 — while writes are
 * strict, because silently dropping a beschikking would break the state
 * machine's immutable log.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads and writes beschikking objects via OpenRegister.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingRepository {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a single beschikking by id. [T06]
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function find(string $decisionId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		[$register, $schema] = $this->resolveRegisterSchema();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->toArray(value: $objectService->find($decisionId, register: $register, schema: $schema));
		} catch (\Throwable $e) {
			$this->logger->error(
				'BeschikkingService: find failed',
				['exception' => $e->getMessage(), 'decisionId' => $decisionId],
			);
			return null;
		}
	}//end find()

	/**
	 * Load a beschikking or throw.
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException 'not_found' when absent.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function requireBeschikking(string $decisionId): array {
		$decision = $this->find(decisionId: $decisionId);
		if ($decision === null) {
			throw new RuntimeException('not_found');
		}

		// Preserve the id for downstream save() calls.
		if (isset($decision['id']) === false) {
			$decision['id'] = $decisionId;
		}

		return $decision;
	}//end requireBeschikking()

	/**
	 * Persist a beschikking via ObjectService.
	 *
	 * @param array<string, mixed> $decision The beschikking payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When storage is unavailable or unconfigured.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function save(array $decision): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('storage_unavailable');
		}

		[$register, $schema] = $this->resolveRegisterSchema();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('beschikking_schema_not_configured');
		}

		return $this->toArray(value: $objectService->saveObject(object: $decision, register: $register, schema: $schema));
	}//end save()

	/**
	 * Resolve the register id and beschikking schema id from config.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function resolveRegisterSchema(): array {
		return [
			$this->settingsService->getConfigValue(key: 'register'),
			$this->settingsService->getConfigValue(key: 'beschikking_schema'),
		];
	}//end resolveRegisterSchema()

	/**
	 * Normalise an ObjectService return value to an array.
	 *
	 * @param mixed $value The entity, array, or JsonSerializable.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
