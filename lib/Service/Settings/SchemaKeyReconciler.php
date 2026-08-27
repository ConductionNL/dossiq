<?php

/**
 * Dossiq schema-key reconciler.
 *
 * Keeps every `*_schema` appconfig key pointing at the LIVE OpenRegister schema
 * id, from two directions: the ids that come back in a ConfigurationService
 * import result, and — for an already-imported instance, where an idempotent
 * re-import returns an empty `schemas` list — a direct slug lookup against the
 * SchemaMapper.
 *
 * That second path is not a nicety: without it the per-schema config keys were
 * never written on a fresh deploy of an existing instance, and the status-name
 * lookup and the WorkflowBoard silently broke.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves schema slugs to live OpenRegister ids and persists their config keys.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaKeyReconciler {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration service.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reconcile every `*_schema` appconfig key directly from OpenRegister.
	 *
	 * For each schema slug Dossiq knows about, resolves the LIVE schema ID via
	 * OpenRegister's SchemaMapper (slug-aware `find()`) and writes the matching
	 * appconfig key. Fully idempotent — a key that already holds the correct ID
	 * is left untouched — so it is safe to call on every install/upgrade and
	 * after every import.
	 *
	 * @return int The number of schema config keys (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcile(): int {
		$schemaMapper = $this->schemaMapper();
		if ($schemaMapper === null) {
			return 0;
		}

		$written = 0;
		foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $configKey) {
			$written += $this->reconcileSingleSchemaKey(
				schemaMapper: $schemaMapper,
				slug: (string)$slug,
				configKey: $configKey
			);
		}

		$this->logger->info(
			'Dossiq: Reconciled schema config keys from OpenRegister',
			['written' => $written]
		);

		return $written;
	}//end reconcile()

	/**
	 * Auto-configure schema and register IDs from the import result.
	 *
	 * Extracts schema entities from the ConfigurationService import result,
	 * maps their slugs to app config keys, and persists the IDs.
	 *
	 * @param array $importResult The result from ConfigurationService::importFromApp()
	 *
	 * @return int The number of schemas successfully configured
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function autoConfigureAfterImport(array $importResult): int {
		$this->configureRegisterId(registers: ($importResult['registers'] ?? []));

		$configuredCount = 0;
		foreach (($importResult['schemas'] ?? []) as $schema) {
			$configuredCount += $this->configureImportedSchema(schema: $schema);
		}

		$this->logger->info(
			'Dossiq: Auto-configuration complete',
			['configuredSchemas' => $configuredCount]
		);

		return $configuredCount;
	}//end autoConfigureAfterImport()

	/**
	 * Persist the register ID from the first register in an import result.
	 *
	 * @param iterable<mixed> $registers The imported register entities.
	 *
	 * @return void
	 */
	private function configureRegisterId(iterable $registers): void {
		foreach ($registers as $register) {
			if (is_object($register) === false) {
				continue;
			}

			$registerId = (string)$register->getId();
			$this->appConfig->setValueString(Application::APP_ID, 'register', $registerId);
			$this->logger->info(
				'Dossiq: Auto-configured register ID',
				['registerId' => $registerId]
			);
			return;
		}
	}//end configureRegisterId()

	/**
	 * Persist the appconfig key for one imported schema entity.
	 *
	 * @param mixed $schema The imported schema entity.
	 *
	 * @return int 1 when a key was written, 0 otherwise.
	 */
	private function configureImportedSchema(mixed $schema): int {
		if (is_object($schema) === false) {
			return 0;
		}

		$slug = $schema->getSlug();
		if (isset(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug]) === false) {
			return 0;
		}

		$configKey = SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug];
		$schemaId = (string)$schema->getId();

		$this->writeSchemaKey(slug: (string)$slug, configKey: $configKey, schemaId: $schemaId);

		$this->logger->debug(
			'Dossiq: Auto-configured schema',
			[
				'slug' => $slug,
				'configKey' => $configKey,
				'schemaId' => $schemaId,
			]
		);

		return 1;
	}//end configureImportedSchema()

	/**
	 * Resolve one schema slug to its live ID and persist its appconfig key.
	 *
	 * Idempotent: returns 0 (and writes nothing) when the slug does not resolve
	 * or the key already holds the correct ID; returns 1 when it (re)writes.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param string $slug The schema slug (e.g. 'caseType').
	 * @param string $configKey The Dossiq appconfig key to write.
	 *
	 * @return int 1 when the key was (re)written, 0 otherwise.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function reconcileSingleSchemaKey(object $schemaMapper, string $slug, string $configKey): int {
		try {
			// Slug-aware lookup with RBAC + multi-tenancy disabled: the repair
			// step runs in a system context that has no active organisation,
			// and the schema set is app-owned config, not tenant data.
			// Signature is find($id, $_extend, $_rbac, $_multitenancy).
			$schema = $schemaMapper->find($slug, [], false, false);
			$schemaId = (string)$schema->getId();
		} catch (\Throwable $e) {
			// Slug not present in this OpenRegister instance — skip it.
			return 0;
		}

		if ($schemaId === '') {
			return 0;
		}

		if ($this->appConfig->getValueString(Application::APP_ID, $configKey, '') === $schemaId) {
			return 0;
		}

		$this->writeSchemaKey(slug: $slug, configKey: $configKey, schemaId: $schemaId);

		return 1;
	}//end reconcileSingleSchemaKey()

	/**
	 * Write one schema id to its appconfig key, keeping the stable
	 * workflow_definition_schema alias in sync.
	 *
	 * @param string $slug The schema slug.
	 * @param string $configKey The appconfig key to write.
	 * @param string $schemaId The live schema id.
	 *
	 * @return void
	 */
	private function writeSchemaKey(string $slug, string $configKey, string $schemaId): void {
		$this->appConfig->setValueString(Application::APP_ID, $configKey, $schemaId);

		if ($slug === SchemaSlugMap::WORKFLOW_TEMPLATE_SLUG) {
			$this->appConfig->setValueString(
				Application::APP_ID,
				SchemaSlugMap::WORKFLOW_DEFINITION_ALIAS,
				$schemaId
			);
		}
	}//end writeSchemaKey()

	/**
	 * Resolve OpenRegister's SchemaMapper, or null when it is unavailable.
	 *
	 * @return object|null The SchemaMapper.
	 */
	private function schemaMapper(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister SchemaMapper for reconcile',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaMapper()
}//end class
