<?php

/**
 * Dossiq schema annotation reconciler.
 *
 * Merges each schema's declarative `x-openregister-*` annotation blocks
 * (calculations, references, lifecycle, aggregations, object-source) from the
 * fragment-merged register JSON onto the LIVE OpenRegister schema's
 * `configuration` column.
 *
 * OpenRegister's app-config import does not reliably round-trip those
 * schema-level blocks on an already-imported instance, and the status engine,
 * the declarative calculation engine and the reference resolver all read them
 * from `Schema::getConfiguration()` — so a dropped block silently disables
 * auto-deadline / auto-identifier / initial-status on create. The merge is
 * additive (never a replace), so existing keys such as `objectNameField` are
 * preserved, and idempotent: a schema whose live configuration already matches
 * is left untouched.
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

use OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles declarative schema annotation blocks onto live OpenRegister schemas.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaAnnotationReconciler {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param RegisterFragmentMerger $fragments The register fragment merger.
	 * @param LoggerInterface $logger The logger interface.
	 * @param SchemaSlugResolver $slugResolver Resolves a slug inside our own register.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private RegisterFragmentMerger $fragments,
		private LoggerInterface $logger,
		private SchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Reconcile the declarative annotation blocks of every declared schema.
	 *
	 * @return int The number of schemas whose configuration was (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcile(): int {
		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister SchemaMapper for declarative reconcile',
				['exception' => $e]
			);
			return 0;
		}

		$schemas = $this->loadDeclarativeRegisterSchemas();
		if ($schemas === null) {
			return 0;
		}

		return $this->reconcileEach(schemaMapper: $schemaMapper, schemas: $schemas);
	}//end reconcile()

	/**
	 * Reconcile every declared schema and log how many were written.
	 *
	 * @param object                 $schemaMapper The OpenRegister SchemaMapper.
	 * @param array<array-key, mixed> $schemas      The declared schema definitions.
	 *
	 * @return int The number of schemas whose configuration was (re)written.
	 */
	private function reconcileEach(object $schemaMapper, array $schemas): int {
		$written = 0;
		foreach ($schemas as $key => $schemaDef) {
			$written += $this->reconcileSchemaAnnotationBlocks(
				schemaMapper: $schemaMapper,
				key: $key,
				schemaDef: $schemaDef
			);
		}//end foreach

		$this->logger->info(
			'Dossiq: Reconciled declarative schema configuration from register JSON',
			['written' => $written]
		);

		return $written;
	}//end reconcileEach()

	/**
	 * Load the fragment-merged schema definitions from the register JSON.
	 *
	 * @return array<array-key, mixed>|null The schema definitions, or null when
	 *                                      the register JSON is missing or invalid.
	 */
	private function loadDeclarativeRegisterSchemas(): ?array {
		$configData = self::readRegisterJson();
		if ($configData === null) {
			return null;
		}

		// Fold modular register fragments on top so a schema's annotation
		// blocks declared in a register.d fragment are reconciled too.
		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);

		$schemas = ($configData['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return null;
		}

		return $schemas;
	}//end loadDeclarativeRegisterSchemas()

	/**
	 * Read and decode the shipped register JSON.
	 *
	 * @return array<array-key, mixed>|null The register JSON, or null when missing or invalid.
	 */
	private static function readRegisterJson(): ?array {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			return null;
		}

		$configData = json_decode((string)file_get_contents($configPath), true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return null;
		}

		return $configData;
	}//end readRegisterJson()

	/**
	 * Reconcile the declarative annotation blocks of one schema definition.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param int|string $key The schema key in the register JSON.
	 * @param mixed $schemaDef The raw schema definition.
	 *
	 * @return int 1 when the configuration was (re)written, 0 otherwise.
	 */
	private function reconcileSchemaAnnotationBlocks(object $schemaMapper, int|string $key, mixed $schemaDef): int {
		if (is_array($schemaDef) === false) {
			return 0;
		}

		$slug = self::slugOf(key: $key, schemaDef: $schemaDef);
		$declaredCfg = ($schemaDef['configuration'] ?? []);
		if ($slug === '' || is_array($declaredCfg) === false) {
			return 0;
		}

		$annotations = self::ownedAnnotations(declaredCfg: $declaredCfg);
		if ($annotations === []) {
			return 0;
		}

		return $this->mergeOntoLiveSchema(
			schemaMapper: $schemaMapper,
			slug: (string)$slug,
			annotations: $annotations
		);
	}//end reconcileSchemaAnnotationBlocks()

	/**
	 * The slug a schema definition declares, or its register key when it declares none.
	 *
	 * @param int|string           $key       The schema key in the register JSON.
	 * @param array<string, mixed> $schemaDef The schema definition.
	 *
	 * @return mixed The slug as declared, '' when there is none.
	 */
	private static function slugOf(int|string $key, array $schemaDef): mixed {
		$fallbackSlug = '';
		if (is_string($key) === true) {
			$fallbackSlug = $key;
		}

		return ($schemaDef['slug'] ?? $fallbackSlug);
	}//end slugOf()

	/**
	 * Collect only the declarative annotation blocks we own.
	 *
	 * @param array<string, mixed> $declaredCfg The declared schema configuration.
	 *
	 * @return array<string, mixed> The owned annotation blocks, by key.
	 */
	private static function ownedAnnotations(array $declaredCfg): array {
		$annotations = [];
		foreach (SchemaSlugMap::SCHEMA_ANNOTATION_KEYS as $annotationKey) {
			if (array_key_exists($annotationKey, $declaredCfg) === true) {
				$annotations[$annotationKey] = $declaredCfg[$annotationKey];
			}
		}

		return $annotations;
	}//end ownedAnnotations()

	/**
	 * Merge one schema's declarative annotation blocks onto its live
	 * OpenRegister configuration. Idempotent — returns 0 when the live
	 * configuration already carries identical blocks.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param string $slug The schema slug (e.g. 'case').
	 * @param array<string, mixed> $annotations The annotation blocks to merge.
	 *
	 * 🔴 ONE KEY IS NOT THE REGISTER JSON'S TO OVERWRITE. The states under
	 * `x-openregister-lifecycle` on the `case` schema are keyed by statusType
	 * UUID, so they only exist on a running instance and no register JSON can
	 * declare them; {@see CaseStateFieldRuleProjector} writes them when a case
	 * type is published. Copying the declared block wholesale over them would
	 * unpublish every per-status field rule on the instance, in silence: nothing
	 * errors, OpenRegister simply stops finding a `states` key and stops
	 * refusing the saves those rules exist to refuse. The projector says what to
	 * carry, so the rule lives with the writer rather than in a condition here.
	 *
	 * @return int 1 when the configuration was (re)written, 0 otherwise.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function mergeOntoLiveSchema(object $schemaMapper, string $slug, array $annotations): int {
		// 🔴 RESOLVE INSIDE OUR OWN REGISTER. An unscoped slug lookup here merged
		// Dossiq's task calculations onto ANOTHER APP'S `task` schema, so
		// isTerminalStatus and daysUntilDue were installed where nothing read
		// them while Dossiq's own task schema never got them. Completed tasks
		// then stayed in "My Tasks" and every due-date column rendered blank.
		$schema = $this->slugResolver->resolve(schemaMapper: $schemaMapper, slug: $slug);
		if ($schema === null) {
			// Slug not present in this OpenRegister instance, so skip it.
			return 0;
		}

		$merged = self::mergedConfiguration(schema: $schema, annotations: $annotations);
		if ($merged === null) {
			return 0;
		}

		$write = function () use ($schema, $schemaMapper, $merged): void {
			$schema->setConfiguration($merged);
			$schemaMapper->update($schema);
		};

		return $this->writeElevated(write: $write, slug: $slug);
	}//end mergeOntoLiveSchema()

	/**
	 * The live configuration with the declared annotation blocks merged in, or null when nothing changes.
	 *
	 * @param object               $schema      The live OpenRegister schema.
	 * @param array<string, mixed> $annotations The annotation blocks to merge.
	 *
	 * @return array<string, mixed>|null The merged configuration, or null when every block already matches.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private static function mergedConfiguration(object $schema, array $annotations): ?array {
		$current = ($schema->getConfiguration() ?? []);
		if (is_array($current) === false) {
			$current = [];
		}

		$changed = self::changedBlocks(current: $current, annotations: $annotations);
		if ($changed === []) {
			return null;
		}

		return array_replace($current, $changed);
	}//end mergedConfiguration()

	/**
	 * The declared annotation blocks whose value differs from the live one.
	 *
	 * @param array<string, mixed> $current     The live configuration.
	 * @param array<string, mixed> $annotations The annotation blocks to merge.
	 *
	 * @return array<string, mixed> The blocks to write, by key.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `carryForwardStates()` reads one
	 *   annotation block and returns another, with no state of its own to hold.
	 *   It is static because it lives with the class that OWNS the key rather
	 *   than with the one that happens to copy it, and injecting a whole
	 *   projector here for one pure function would make the reconciler depend
	 *   on a case-type store it never reads.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private static function changedBlocks(array $current, array $annotations): array {
		$changed = [];
		foreach ($annotations as $annotationKey => $annotationValue) {
			$annotationValue = CaseStateFieldRuleProjector::carryForwardStates(
				annotationKey: $annotationKey,
				declared: $annotationValue,
				live: ($current[$annotationKey] ?? null)
			);

			if (($current[$annotationKey] ?? null) !== $annotationValue) {
				$changed[$annotationKey] = $annotationValue;
			}
		}

		return $changed;
	}//end changedBlocks()

	/**
	 * Run a schema write elevated where OpenRegister offers it, logging a failure.
	 *
	 * OpenRegister trusts a null user under CLI or a SystemOperationContext;
	 * the web updater is neither, so an unelevated write is denied in silence.
	 *
	 * @param callable $write The write.
	 * @param string   $slug  The schema slug, for the failure log.
	 *
	 * @return int 1 when written, 0 when the write failed.
	 */
	private function writeElevated(callable $write, string $slug): int {
		try {
			if (class_exists(\OCA\OpenRegister\Service\SystemOperationContext::class) === false) {
				$write();
				return 1;
			}

			\OCA\OpenRegister\Service\SystemOperationContext::run($write);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Failed to reconcile declarative configuration for schema ' . $slug,
				['exception' => $e]
			);
			return 0;
		}

		return 1;
	}//end writeElevated()
}//end class
