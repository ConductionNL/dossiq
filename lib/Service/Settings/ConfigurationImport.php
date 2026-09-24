<?php

/**
 * Dossiq configuration import.
 *
 * Reading the register this app ships, merging the ADR-037 fragments on top of
 * it, handing the result to OpenRegister's ConfigurationService, and then
 * reconciling what the import does not carry: the `*_schema` config keys and
 * the declarative `x-openregister-*` annotation blocks. All four steps run as
 * one flow because skipping either reconcile leaves a fresh instance with
 * schemas whose annotations silently do nothing.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService}, which was over its
 * complexity ceiling. Holding the app's configuration and importing it into
 * another app are two jobs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Importing the shipped register into OpenRegister, and reconciling what the
 * import does not carry.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ConfigurationImport {

	/**
	 * The ADR-037 register-fragment merger.
	 *
	 * @var RegisterFragmentMerger
	 */
	private RegisterFragmentMerger $fragments;

	/**
	 * Declares magic-table storage for every schema of the register.
	 *
	 * @var RegisterStorageDeclaration
	 */
	private RegisterStorageDeclaration $storage;

	/**
	 * Reconciles `*_schema` appconfig keys against live OpenRegister schema ids.
	 *
	 * @var SchemaKeyReconciler
	 */
	private SchemaKeyReconciler $schemaKeys;

	/**
	 * Reconciles declarative `x-openregister-*` blocks onto live schemas.
	 *
	 * @var SchemaAnnotationReconciler
	 */
	private SchemaAnnotationReconciler $schemaAnnotations;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterBridge $openRegister Whether OpenRegister is here at all.
	 * @param IAppConfig         $appConfig    The app configuration the schema keys are written to.
	 * @param ContainerInterface $container    The DI container the importer is resolved through.
	 * @param LoggerInterface    $logger       Logger.
	 */
	public function __construct(
		private readonly OpenRegisterBridge $openRegister,
		IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		$this->fragments = new RegisterFragmentMerger();
		$this->storage   = new RegisterStorageDeclaration();

		// One resolver, shared by both reconcilers. They must agree on which
		// schema a slug means: when they disagreed, the config keys pointed at
		// one `task` schema while the calculations were merged onto another.
		$slugResolver = new SchemaSlugResolver(
			appConfig: $appConfig,
			container: $container,
			logger: $logger
		);

		$this->schemaKeys = new SchemaKeyReconciler(
			appConfig: $appConfig,
			container: $container,
			logger: $logger,
			slugResolver: $slugResolver
		);
		$this->schemaAnnotations = new SchemaAnnotationReconciler(
			container: $container,
			fragments: $this->fragments,
			logger: $logger,
			slugResolver: $slugResolver
		);
	}//end __construct()

	/**
	 * Load the register configuration from dossiq_register.json via ConfigurationService.
	 *
	 * @param bool $force Whether to force re-import regardless of version
	 *
	 * @return array Import result
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->openRegister->isAvailable() === false) {
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled',
			];
		}

		try {
			$configurationService = $this->container->get(
				'OCA\OpenRegister\Service\ConfigurationService'
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access ConfigurationService',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => 'Could not access ConfigurationService: ' . $e->getMessage(),
			];
		}

		$effective = $this->readEffectiveConfiguration();
		if (isset($effective['error']) === true) {
			return $effective['error'];
		}

		$configData = $effective['data'];
		$configVersion = ($configData['info']['version'] ?? '0.0.0');

		try {
			$importResult = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force,
			);

			$configuredCount = $this->schemaKeys->autoConfigureAfterImport(importResult: $importResult);
			$this->reconcileSchemaConfig();

			// 🔴 THE IMPORT DOES NOT CARRY THE DECLARATIVE ANNOTATION BLOCKS, SO
			// MERGE THEM HERE. Importing the register creates the schemas, but the
			// `x-openregister-*` blocks declared alongside them in
			// dossiq_register.json do not survive onto the live schema. Without
			// this call a FRESH instance never gets them: `isTerminalStatus` never
			// materialises, so every completed task keeps reading false and the
			// widgets filtering on it keep showing finished work, and
			// `daysUntilDue` does not exist to extend, so due-date columns render
			// blank. Both failures are silent. The e2e suite caught it on a clean
			// CI install after passing on a dev box where the reconcile had been
			// run by hand. Idempotent, so it is safe on every import.
			$this->reconcileSchemaDeclarativeConfig();

			$this->logger->info(
				'Dossiq: Configuration imported and reconciled',
				['version' => $configVersion, 'configured' => $configuredCount]
			);

			return [
				'success' => true,
				'message' => 'Configuration imported and auto-configured (' . $configuredCount . ' schemas mapped)',
				'version' => $configVersion,
				'configured' => $configuredCount,
				'result' => $importResult,
			];
		} catch (\Throwable $e) {
			// 🔴 `\Throwable`, NOT `\Exception`. A declaration this app ships
			// that OpenRegister's entity setters refuse arrives here as a
			// `TypeError`, which is an `\Error` and not an `\Exception`, so a
			// `catch (\Exception)` lets it out of the controller and the
			// caller reads HTTP 500 with a Nextcloud error page. Measured
			// 2026-09-19 on a live instance: one fragment declared
			// `searchable` as an array of property names, OpenRegister's
			// `Schema::setSearchable(bool)` raised a TypeError, and
			// `POST /api/settings/load` answered 500. The seed then fell back
			// to the importer that cannot merge `register.d`, so every schema
			// this app declares in a fragment was absent and none of the
			// `*_schema` config keys was ever written. A 500 says nothing
			// about which declaration is wrong; the shape below names it.
			$this->logger->error(
				'Dossiq: Configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
			];
		}//end try
	}//end loadConfiguration()

	/**
	 * Read dossiq_register.json and deep-merge the ADR-037 register fragments
	 * on top of it, producing the effective register configuration to import.
	 *
	 * Returns either `['data' => array]` on success or `['error' => array]`
	 * carrying the caller-facing failure shape, so {@see self::loadConfiguration()}
	 * stays a single import flow rather than also being a file reader.
	 *
	 * @return array{data?: array, error?: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function readEffectiveConfiguration(): array {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error(
				'Dossiq: Configuration file not found at ' . $configPath
			);
			return [
				'error' => [
					'success' => false,
					'message' => 'Configuration file not found',
				],
			];
		}

		$configContent = file_get_contents($configPath);
		$configData = json_decode($configContent, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Dossiq: Invalid JSON in configuration file');
			return [
				'error' => [
					'success' => false,
					'message' => 'Invalid JSON in configuration file',
				],
			];
		}

		// ADR-037: deep-merge any modular register fragments from
		// lib/Settings/register.d/*.json on top of the monolith. This lets
		// concurrent same-app builds add registers/schemas via isolated
		// fragment files instead of all editing dossiq_register.json and
		// conflicting. Fragments are applied in sorted filename order.
		// The merge also returns a hash of the fragment set. It is deliberately
		// not captured: it used to be folded into the version so that adding or
		// changing a fragment forced a re-import, but OpenRegister gates with
		// version_compare, which treats `+…` as further version parts and
		// compares them LEXICALLY rather than as semver build metadata — so
		// whether the gate fired depended on how two md5 hashes happened to
		// sort. Unchanged content re-imported about half the time; a real
		// change was skipped the other half. OpenRegister now hashes the merged
		// configuration itself and skips on hash equality, which detects a
		// changed fragment from the data. The version stays a version.
		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);
		// Every schema of the register is stored in a magic table; the
		// register has to say so, or OpenRegister cannot name its objects.
		$configData = $this->storage->declare(config: $configData);

		return ['data' => $configData];
	}//end readEffectiveConfiguration()

	/**
	 * Reconcile every `*_schema` appconfig key directly from OpenRegister.
	 *
	 * `autoConfigureAfterImport()` only persists schema IDs that appear in the
	 * ConfigurationService import RESULT. On an already-imported instance an
	 * idempotent re-import returns an empty `schemas` list, so the per-schema
	 * config keys (case_type_schema, status_type_schema, status_record_schema,
	 * workflow_template_schema, …) were never written — the status-name lookup
	 * and the WorkflowBoard then silently broke on a fresh deploy.
	 *
	 * This method closes that gap: for each schema slug Dossiq knows about it
	 * resolves the LIVE schema ID via OpenRegister's SchemaMapper (slug-aware
	 * `find()`) and writes the matching appconfig key. It is fully idempotent —
	 * a key that already holds the correct ID is left untouched — so it is safe
	 * to call on every install/upgrade and after every import.
	 *
	 * @return int The number of schema config keys (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcileSchemaConfig(): int {
		if ($this->openRegister->isAvailable() === false) {
			return 0;
		}

		return $this->schemaKeys->reconcile();
	}//end reconcileSchemaConfig()

	/**
	 * Reconcile each schema's declarative `x-openregister-*` annotation blocks
	 * (calculations, references, lifecycle, …) from dossiq_register.json onto
	 * the LIVE OpenRegister schema's `configuration` column.
	 *
	 * OpenRegister's app-config import maps a schema's `properties` but does not
	 * reliably round-trip the schema-level `configuration` annotation blocks on
	 * an already-imported instance (the per-schema version gate plus the import
	 * pipeline can drop the nested `x-openregister-*` keys). The status engine,
	 * the declarative calculation engine and the reference resolver all read
	 * those blocks from `Schema::getConfiguration()`, so a dropped block silently
	 * disables auto-deadline / auto-identifier / initial-status on create.
	 *
	 * The reconcile itself lives in {@see SchemaAnnotationReconciler}: for every
	 * schema defined in the (fragment-merged) register JSON it reads the
	 * annotation keys listed in {@see \OCA\Dossiq\Service\Settings\SchemaSlugMap::SCHEMA_ANNOTATION_KEYS} and
	 * writes them onto the live schema's configuration via the SchemaMapper,
	 * MERGING (never replacing) so existing keys such as `objectNameField` are
	 * preserved. Fully idempotent: a schema whose live configuration already
	 * matches is left untouched.
	 *
	 * @return int The number of schemas whose configuration was (re)written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reconcileSchemaDeclarativeConfig(): int {
		if ($this->openRegister->isAvailable() === false) {
			return 0;
		}

		return $this->schemaAnnotations->reconcile();
	}//end reconcileSchemaDeclarativeConfig()
}//end class
