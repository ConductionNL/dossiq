<?php

/**
 * Dossiq mandaat repository.
 *
 * The single OpenRegister access path for the mandaat matrix: the
 * rolNaam→rolId index, the prior vastgesteld besluit for a besluit number, the
 * mandaten belonging to a besluit, the schema/register context an approval
 * needs, the bulk activation of a besluit's mandaten, and the generic
 * configured-schema save. Split out of MandaatImportService so that service
 * keeps the import *decision* — new vs changed vs removed, and the approval
 * state machine — while every register/schema lookup lives here.
 *
 * The read paths degrade rather than throw when OpenRegister or a schema is
 * unconfigured: the caller's own guards decide whether an empty result is
 * fatal, exactly as before the split.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mandaat
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
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mandaat;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredSchemaWriter;
use OCA\Dossiq\Service\Support\ReadsConfiguredSource;
use OCA\Dossiq\Service\Support\SearchesConfiguredSchema;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister access for MandateringsBesluiten, Mandaten and OrganisatieRollen.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
 */
class MandaatRepository {
	use SearchesObjects;
	use ReadsConfiguredSource;
	use SearchesConfiguredSchema;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings (config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the object service, register and schemas needed to approve an import.
	 *
	 * @return array<string, mixed> carrying objectService, register, bSchema and mSchema.
	 *
	 * @throws RuntimeException When the mandaat services are not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function resolveApprovalContext(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue('mandaterings_besluit_schema');
		$mSchema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		if ($objectService === null || $register === '' || $bSchema === '' || $mSchema === '') {
			throw new RuntimeException('Mandaat services not configured');
		}

		return [
			'objectService' => $objectService,
			'register' => $register,
			'bSchema' => $bSchema,
			'mSchema' => $mSchema,
		];
	}//end resolveApprovalContext()

	/**
	 * Flip every mandaat of a besluit to active, defaulting a missing validFrom.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $mSchema The mandaat schema id.
	 * @param string $decisionId The owning MandateringsBesluit id.
	 * @param string $now The activation date (Y-m-d).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function activateMandatenForBesluit(
		object $objectService,
		string $register,
		string $mSchema,
		string $decisionId,
		string $now,
	): void {
		$store = ['objectService' => $objectService, 'register' => $register, 'schema' => $mSchema];
		foreach ($this->mandatenOf(store: $store, decisionId: $decisionId) as $m) {
			$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $mSchema, object: $this->activated(mandaat: $m, now: $now));
		}
	}//end activateMandatenForBesluit()

	/**
	 * The mandaten a MandateringsBesluit owns; none when they cannot be read.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store      The object service, the
	 *                                                                                   register and the mandaat schema.
	 * @param string                                                          $decisionId The owning MandateringsBesluit id.
	 *
	 * @return array<int, array<string, mixed>> The mandaten.
	 */
	private function mandatenOf(array $store, string $decisionId): array {
		try {
			$mandaten = $this->searchObjectsAsArrays(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				filters: ['mandateDecision' => $decisionId]
			);
		} catch (\Throwable $e) {
			$mandaten = [];
		}

		return $mandaten;
	}//end mandatenOf()

	/**
	 * The mandaat made active, valid from today when it names no start.
	 *
	 * @param array<string, mixed> $mandaat The mandaat.
	 * @param string               $now     The activation date (Y-m-d).
	 *
	 * @return array<string, mixed> The active mandaat.
	 */
	private function activated(array $mandaat, string $now): array {
		$mandaat['status'] = 'active';
		if (isset($mandaat['validFrom']) === false || $mandaat['validFrom'] === '') {
			$mandaat['validFrom'] = $now;
		}

		return $mandaat;
	}//end activated()

	/**
	 * Build a rolNaam to rolId index from OrganisatieRol objects.
	 *
	 * @return array<string, string> rolNaam → rolId.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function loadRoleIndex(): array {
		$source = $this->configuredSource(schemaKey: 'organisatie_rol_schema');
		if ($source === null) {
			return [];
		}

		[$objectService, $register, $schema] = $source;
		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		} catch (\Throwable $e) {
			return [];
		}

		return $this->roleIndex(rows: $rows);
	}//end loadRoleIndex()

	/**
	 * Index OrganisatieRol rows by role name.
	 *
	 * @param array<int, array<string, mixed>> $rows The OrganisatieRol rows.
	 *
	 * @return array<string, string> rolNaam → rolId.
	 */
	private function roleIndex(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$roleName = (string)($row['roleName'] ?? '');
			if ($roleName !== '') {
				$out[$roleName] = (string)($row['id'] ?? '');
			}
		}

		return $out;
	}//end roleIndex()

	/**
	 * Find the prior adopted decision for a decision number.
	 *
	 * @param string $decisionNumber Number.
	 * @param string|null $excludeId Optional id to exclude.
	 *
	 * @return array<string, mixed>|null The prior adopted decision, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function findPriorDecision(string $decisionNumber, ?string $excludeId = null): ?array {
		$rows = $this->configuredRows(schemaKey: 'mandaterings_besluit_schema', filters: ['decisionNumber' => $decisionNumber]);
		if ($rows === null) {
			return null;
		}

		return $this->firstDetermined(rows: $rows, excludeId: $excludeId);
	}//end findPriorDecision()

	/**
	 * The first adopted decision among the rows, skipping the excluded id.
	 *
	 * @param array<int, array<string, mixed>> $rows      The decisions with one decision number.
	 * @param string|null                      $excludeId Optional id to skip.
	 *
	 * @return array<string, mixed>|null The adopted decision, or null.
	 */
	private function firstDetermined(array $rows, ?string $excludeId): ?array {
		foreach ($rows as $row) {
			if ($this->isAdoptedOutside(row: $row, excludeId: $excludeId) === true) {
				return $row;
			}
		}

		return null;
	}//end firstDetermined()

	/**
	 * Whether the decision is adopted and not the excluded one.
	 *
	 * @param array<string, mixed> $row       One stored decision.
	 * @param string|null          $excludeId Optional id to skip.
	 *
	 * @return bool
	 */
	private function isAdoptedOutside(array $row, ?string $excludeId): bool {
		if ($excludeId !== null && (string)($row['id'] ?? '') === $excludeId) {
			return false;
		}

		return ($row['status'] ?? '') === 'determined';
	}//end isAdoptedOutside()

	/**
	 * Find the mandaten linked to a besluit.
	 *
	 * @param string $decisionId Besluit id.
	 *
	 * @return array<int, array<string, mixed>> The besluit's mandaten.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function findMandatenForBesluit(string $decisionId): array {
		return ($this->configuredRows(schemaKey: 'mandaat_schema', filters: ['mandateDecision' => $decisionId]) ?? []);
	}//end findMandatenForBesluit()

	/**
	 * Persist a single object for the configured schema.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed> The saved object, or the payload when the
	 *                              save could not be performed.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function save(string $schemaConfigKey, array $object): array {
		$writer = new ConfiguredSchemaWriter(
			settingsService: $this->settingsService,
			onFailure: function (\Throwable $e) use ($schemaConfigKey): void {
				$this->logger->error('Mandaat import persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
			}
		);

		return $writer->save(schemaConfigKey: $schemaConfigKey, object: $object);
	}//end save()
}//end class
