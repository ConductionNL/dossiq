<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The OpenRegister reads and writes the LHS recommendation engine needs.
 *
 * Split out of {@see LhsRecommendationService} so that class holds only the
 * rules — which intervention the matrix prescribes, who may deviate from it and
 * in which direction — and not the shape of the storage underneath.
 *
 * The split was forced by a measurement rather than chosen for tidiness: adding
 * the stored-row read that closes the override bypass took the service past the
 * class-complexity threshold, and moving the storage out is the answer that
 * makes the service smaller instead of the answer that silences the rule.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
class LhsRecommendationStore {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves OpenRegister and the schema slugs.
	 * @param LoggerInterface $logger          Logger.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the object service plus the recommendation register and schema.
	 *
	 * Both the read and the write need the same three, and had the same twelve
	 * lines resolving them. One place means a misconfiguration reports itself
	 * the same way whichever path hit it.
	 *
	 * @return array{0: object, 1: string, 2: string} Service, register, schema.
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is absent.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function recommendationStore(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('lhs_recommendation_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('LHS-recommendation register/schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];

	}//end recommendationStore()

	/**
	 * Read a stored recommendation by id.
	 *
	 * @param string $recommendationId The stored recommendation's id.
	 *
	 * @return array<string, mixed> The stored row.
	 *
	 * @throws RuntimeException When it cannot be read.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function loadRecommendation(string $recommendationId): array {
		[$objectService, $register, $schema] = $this->recommendationStore();

		try {
			$row = $objectService->find($recommendationId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq LHS: kon aanbeveling niet lezen: ' . $e->getMessage(),
			);
			throw new RuntimeException('LHS-aanbeveling niet gevonden');
		}

		$row = $this->toArray(value: $row);
		if ($row === []) {
			throw new RuntimeException('LHS-aanbeveling niet gevonden');
		}

		return $row;

	}//end loadRecommendation()

	/**
	 * Persist an lhsRecommendation row through ObjectService.
	 *
	 * @param array<string, mixed> $row Row to persist
	 * @param string|null $id Existing id when updating; null for create
	 *
	 * @return array<string, mixed> Persisted row
	 *
	 * @throws RuntimeException On save failure.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function persistRecommendation(array $row, ?string $id = null): array {
		[$objectService, $register, $schema] = $this->recommendationStore();

		try {
			if ($id !== null) {
				$row['id'] = $id;
			}

			$saved = $objectService->saveObject(
				register: $register,
				schema: $schema,
				object: $row,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq LHS: failed to save lhsRecommendation: ' . $e->getMessage(),
			);
			throw new RuntimeException('Opslaan LHS-aanbeveling mislukt');
		}

		return $this->toArray(value: $saved);
	}//end persistRecommendation()

	/**
	 * Load the LHS matrix to use for the lookup.
	 *
	 * Without an explicit version, returns the matrix flagged `active = true`.
	 * With an explicit version, returns the matching versioned snapshot —
	 * used by historical recommendations that must remain stable across
	 * subsequent matrix edits (REQ-LHS-8).
	 *
	 * @param int|null $version Explicit matrix version, or null for active
	 *
	 * @return array<string, mixed> The matrix row
	 *
	 * @throws RuntimeException When no matrix is available.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function loadMatrix(?int $version): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('lhs_matrix_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('LHS-matrix register/schema is niet geconfigureerd');
		}

		$filters = ['active' => true];
		if ($version !== null) {
			$filters = ['version' => $version];
		}

		try {
			$results = $objectService->findAll(
				[
					'filters' => (['register' => $register, 'schema' => $schema] + $filters),
					'limit' => 1,
				],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq LHS: matrix lookup failed: ' . $e->getMessage(),
			);
			throw new RuntimeException('LHS-matrix lookup mislukt');
		}

		$row = $this->firstRow(results: $results);
		if ($row === null) {
			throw new RuntimeException('Geen actieve LHS-matrix gevonden');
		}

		return $this->toArray(value: $row);
	}//end loadMatrix()

	/**
	 * Pluck the first row from any ObjectService result shape.
	 *
	 * @param mixed $results ObjectService::getObjects() return
	 *
	 * @return mixed|null
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function firstRow(mixed $results): mixed {
		if (is_array($results) === true) {
			if (isset($results[0]) === true) {
				return $results[0];
			}

			if (isset($results['results']) === true
				&& is_array($results['results']) === true
				&& count($results['results']) > 0
			) {
				return $results['results'][0];
			}
		}

		return null;
	}//end firstRow()

	/**
	 * Coerce an ObjectService return value to an associative array.
	 *
	 * @param mixed $value The value
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				$serialised = $value->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($value, 'toArray') === true) {
				$arr = $value->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$value;
		}

		return [];
	}//end toArray()

}//end class
