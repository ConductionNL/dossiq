<?php

/**
 * Dossiq Settings Service
 *
 * Service for managing Dossiq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Settings\ConfigKeys;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler;
use OCA\Dossiq\Service\Settings\SchemaKeyReconciler;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Dossiq application configuration and settings.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsService {
	/**
	 * Configuration keys that contain secrets and must be redacted for non-admin callers.
	 *
	 * Any key matching one of these suffixes is masked with '***' in public responses.
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = [
		'ai_api_key',
		'appointment_backend_api_key',
		// AI model URL reveals internal infrastructure topology; redact for non-admins.
		'ai_model_url',
		// Dwangsom callback HMAC secret — never expose to non-admin callers.
		'dwangsom_callback_secret',
	];

	/**
	 * The appconfig keys this app owns.
	 *
	 * The list lives in {@see ConfigKeys} because it is data, not behaviour,
	 * and because this class sits on the phpmd ExcessiveClassLength ceiling:
	 * 200 array entries in a 1000-line class meant no new key could be added
	 * without reddening the whole tree.
	 *
	 * @var string[]
	 */
	private const CONFIG_KEYS = ConfigKeys::ALL;

	/**
	 * Default values for KCC-werkplek bridge behaviour settings.
	 *
	 * Used by getKccConfigValue() so that an unset app-config key resolves to
	 * the documented default rather than an empty string.
	 */
	public const KCC_DEFAULTS = [
		'identification_method' => 'both',
		'identification_score_threshold' => '0.8',
		'sentiment_polling_interval' => '5',
		'specialist_availability_polling_interval' => '30',
		'max_zaken_voorblad' => '10',
		'max_contactmomenten_history' => '5',
		'belplan_overflow_threshold_wachttijd' => '180',
		'belplan_overflow_threshold_wachtrij_lengte' => '5',
		'sentiment_trigger_words' => '["ongelooflijk","complaint","alderman","advocaat","media","rechtszaak"]',
		'quick_action_templates' => '{}',
	];

	/**
	 * Default values for the WOO-publication-via-OpenCatalogi bridge.
	 *
	 * Match OpenCatalogi's own shipped bundle (`lib/Settings/publication_register.json`
	 * in the opencatalogi repo, register slug `publication`, schemas
	 * `publication`/`document`) so publishing works out of the box on a
	 * default install; overridable per instance via getWooPublicationConfigValue().
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
	 */
	public const WOO_PUBLICATION_DEFAULTS = [
		'woo_publication_register' => 'publication',
		'woo_publication_schema' => 'publication',
		'woo_publication_document_schema' => 'document',
	];

	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The ADR-037 register-fragment merger.
	 *
	 * @var RegisterFragmentMerger
	 */
	private RegisterFragmentMerger $fragments;

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
	 * The app-config key holding the Besluit schema id.
	 *
	 * @var string
	 */
	private const DECISION_SCHEMA_KEY = 'decision_schema';

	/**
	 * The app that owns the `decision` slug fleet-wide.
	 *
	 * @var string
	 */
	private const DECIDIQ_APP_ID = 'decidiq';

	/**
	 * decidiq's slug for it.
	 *
	 * @var string
	 */
	private const DECISION_SLUG = 'decision';

	/**
	 * Constructor for the SettingsService.
	 *
	 * The three collaborators are constructed here rather than injected so the
	 * container-facing signature stays `(appConfig, appManager, container,
	 * logger)` — the shape the bespoke factory in
	 * {@see \OCA\Dossiq\AppInfo\Registrar\BespokeServiceRegistrar} and ~180
	 * injection sites already use.
	 *
	 * @param IAppConfig $appConfig The app configuration service
	 * @param IAppManager $appManager The app manager service
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		$this->fragments = new RegisterFragmentMerger();

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
	 * Check if OpenRegister is installed and enabled.
	 *
	 * The isEnabledForUser() check resolves against the current user session
	 * and returns false in session-less contexts (occ commands, repair steps,
	 * background jobs) even when OpenRegister is enabled globally — which
	 * silently skipped the bezwaar/beroep seed during install/repair. Fall back
	 * to the session-less isInstalled() check so CLI/background callers see it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID) === true
			|| $this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === true;
	}//end isOpenRegisterAvailable()

	/**
	 * Resolve the OpenRegister ObjectService from the DI container.
	 *
	 * Returns null when OpenRegister is not installed/enabled, or when the
	 * container cannot resolve the service (e.g. on a fresh install before
	 * configuration). Callers are expected to handle the null case.
	 *
	 * Mirrors the lazy-resolve pattern already used for ConfigurationService
	 * in loadConfiguration() — OpenRegister is an optional runtime dependency
	 * so we cannot type-hint the class directly in the constructor.
	 *
	 * @return object|null The OpenRegister ObjectService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getObjectService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ObjectService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Lazily resolve OpenRegister's FileService for in-process file attachment.
	 *
	 * ADR-084 publishes `ObjectServiceInterface` — 25 methods — and **none of
	 * them attaches a file**. OpenRegister's own `files#create` route runs
	 * `FileService::addFile()`, and `FileService` is not a published contract,
	 * so an app that must attach bytes to an OpenRegister object in process has
	 * exactly this one route. Recorded as a contract gap in
	 * `openspec/changes/woo-publication-in-process-object-writes/proposal.md`
	 * rather than worked around with a self-addressed HTTP call, which is what
	 * ADR-080 D2/D3 forbids.
	 *
	 * Same lazy-resolve contract as {@see self::getObjectService()} and
	 * {@see self::getApprovalService()}: OpenRegister is an optional runtime
	 * dependency, so the class is resolved through the container at call time
	 * rather than type-hinted in the constructor, and callers MUST handle null.
	 *
	 * @return object|null The OpenRegister FileService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function getFileService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister FileService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getFileService()

	/**
	 * Lazily resolve any OpenRegister service by its fully-qualified name.
	 *
	 * The three resolvers above each hard-code one class, which is right when
	 * a seam is permanent. This one takes the name, because the task engine's
	 * seam is TEMPORARY: `EngineTaskGateway` is the dual-run half of the
	 * caseTask migration and is deleted with it, and adding a fourth
	 * copy-pasted resolver for something scheduled for removal is how a
	 * migration leaves debris behind.
	 *
	 * 🔴 The caller is responsible for `class_exists()`. A container `get()`
	 * on an absent class throws, which is caught and logged here as a resolve
	 * failure, and a rename then reads identically to a misconfiguration.
	 * `EngineTaskGateway::unavailableReason()` separates the two and is the
	 * only reason a silent namespace move would ever be noticed.
	 *
	 * @param string $className Fully-qualified OpenRegister service class.
	 *
	 * @return object|null The service, or null when unavailable.
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
	 */
	public function resolveOpenRegisterService(string $className): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get($className);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access an OpenRegister service',
				['class' => $className, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveOpenRegisterService()

	/**
	 * Lazily resolve OpenRegister's ApprovalService for parafering chain delegation.
	 *
	 * Per ADR-022 (apps consume OpenRegister abstractions) the parafering
	 * (sign-off routing) chain-state backend is OpenRegister's
	 * `approval-workflow` capability, exposed through
	 * `OCA\OpenRegister\Service\ApprovalService`. OpenRegister is an optional
	 * runtime dependency, so — exactly like getObjectService() — the class is
	 * resolved through the container at call time rather than type-hinted in the
	 * constructor. Callers MUST handle the null case (graceful degradation to
	 * the legacy in-array path during the migration window).
	 *
	 * @return object|null The OpenRegister ApprovalService or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getApprovalService(): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ApprovalService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister ApprovalService',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getApprovalService()

	/**
	 * Lazily resolve an OpenRegister DI class by fully-qualified name.
	 *
	 * Generic helper for the parafering approval bridge to reach OpenRegister's
	 * ApprovalChainMapper / ApprovalStepMapper without a hard constructor
	 * dependency on the optional OpenRegister app.
	 *
	 * @param string $class Fully-qualified OpenRegister class name
	 *
	 * @return object|null The resolved service, or null when unavailable
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 *
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getOpenRegisterClass(string $class): ?object {
		if ($this->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: Could not access OpenRegister class',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getOpenRegisterClass()

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
		if ($this->isOpenRegisterAvailable() === false) {
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
		} catch (\Exception $e) {
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
	 * carrying the caller-facing failure shape, so {@see loadConfiguration()}
	 * stays a single import flow rather than also being a file reader.
	 *
	 * @return array{data?: array, error?: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	private function readEffectiveConfiguration(): array {
		$configPath = __DIR__ . '/../Settings/dossiq_register.json';
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
			fragmentDir: __DIR__ . '/../Settings/register.d'
		);

		return ['data' => $configData];
	}//end readEffectiveConfiguration()

	/**
	 * Get all current settings as an associative array.
	 *
	 * Returns full (unredacted) settings including secrets. Callers MUST
	 * ensure only admin users receive this response.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getSettings(): array {
		$config = [];
		foreach (self::CONFIG_KEYS as $key) {
			$config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		return $config;
	}//end getSettings()

	/**
	 * Get settings safe for non-admin callers.
	 *
	 * Identical to getSettings() but replaces every SECRET_KEYS entry
	 * with '***' so that bearer tokens and API keys are never exposed
	 * to ordinary authenticated users.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPublicSettings(): array {
		$config = $this->getSettings();
		foreach (self::SECRET_KEYS as $secretKey) {
			if (isset($config[$secretKey]) === true && $config[$secretKey] !== '') {
				$config[$secretKey] = '***';
			}
		}

		return $config;
	}//end getPublicSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array $data The settings data to update
	 *
	 * @return array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		$this->logger->info('Dossiq settings updated', ['keys' => array_keys($data)]);

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Get a single configuration value by key.
	 *
	 * @param string $key The configuration key
	 * @param string $default The default value if key not found
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getConfigValue(string $key, string $default = ''): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);

		// LAST, and only when nothing local answered.
		//
		// A schema slug is global per organisation, and two apps declared a
		// `decision`: decidiq's is the governance decision (motion, voting,
		// adoption, repeals) and this app's was the VNG Besluit behind the BRC.
		// `SchemaMapper::find()` matches `LOWER(slug)`, so whichever row it
		// reached first answered for both. decidiq's Decision now carries the
		// four BRC fields it lacked (decidiq#1161), so it can hold the record,
		// and the BrcController stays here — the standard belongs where it is
		// served from — reading decidiq's schema instead of a second one.
		//
		// Resolving LAST is what makes this safe to ship before any migration.
		// An instance that still has its own `decision_schema` configured keeps
		// using it, because its besluiten are in that schema; a fresh install
		// has no such key and lands on decidiq's. Preferring decidiq
		// unconditionally would have pointed every existing instance at an empty
		// schema, and the BRC would have answered 404 for every besluit it had.
		if ($value === '' && $key === self::DECISION_SCHEMA_KEY) {
			return $this->decidiqDecisionSchemaId();
		}

		return $value;
	}//end getConfigValue()

	/**
	 * The id of decidiq's `decision` schema, or '' when it cannot be resolved.
	 *
	 * Looked up by the `(application, slug)` PAIR rather than by slug alone.
	 * Slug alone is exactly the ambiguity this exists to end: it would match
	 * this app's own row as readily as decidiq's, and which one it returned
	 * would depend on insertion order.
	 *
	 * Fails to '' rather than throwing. decidiq is an optional peer, and a
	 * caller that gets '' behaves as it always did when the key was unset.
	 *
	 * @return string The schema id, or '' when decidiq or its schema is absent.
	 *
	 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-the-besluit-resolves-to-decidiqs-decision-req-brc-020
	 */
	private function decidiqDecisionSchemaId(): string {
		if ($this->appManager->isInstalled(self::DECIDIQ_APP_ID) === false) {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			if (method_exists($schemaMapper, 'findByApplicationAndSlug') === false) {
				return '';
			}

			$schema = $schemaMapper->findByApplicationAndSlug(self::DECISION_SLUG, self::DECIDIQ_APP_ID);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Dossiq: decidiq\'s decision schema is unavailable',
				['error' => $e->getMessage()]
			);
			return '';
		}

		if ($schema === null) {
			return '';
		}

		return (string)$schema->getId();
	}//end decidiqDecisionSchemaId()

	/**
	 * Get a KCC-werkplek behaviour setting, falling back to its documented default.
	 *
	 * Unlike getConfigValue(), an unset key resolves to the value declared in
	 * self::KCC_DEFAULTS rather than an empty string. This keeps the KCC bridge
	 * functional out-of-the-box before an administrator visits the settings form.
	 *
	 * @param string $key The configuration key (must exist in self::KCC_DEFAULTS).
	 *
	 * @return string The configured value, or the documented default.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	public function getKccConfigValue(string $key): string {
		$default = (self::KCC_DEFAULTS[$key] ?? '');
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end getKccConfigValue()

	/**
	 * Get a WOO-publication-via-OpenCatalogi bridge setting, falling back to
	 * its documented default.
	 *
	 * Mirrors getKccConfigValue(): an unset key resolves to the value
	 * declared in self::WOO_PUBLICATION_DEFAULTS (OpenCatalogi's own shipped
	 * register/schema slugs) rather than an empty string, so publishing works
	 * out of the box before an administrator visits the settings form.
	 *
	 * @param string $key The configuration key (must exist in self::WOO_PUBLICATION_DEFAULTS).
	 *
	 * @return string The configured value, or the documented default.
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d1
	 */
	public function getWooPublicationConfigValue(string $key): string {
		$default = (self::WOO_PUBLICATION_DEFAULTS[$key] ?? '');
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end getWooPublicationConfigValue()

	/**
	 * Set a single configuration value.
	 *
	 * @param string $key The configuration key
	 * @param string $value The value to set
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function setConfigValue(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}//end setConfigValue()

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
		if ($this->isOpenRegisterAvailable() === false) {
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
	 * annotation keys listed in {@see SchemaSlugMap::SCHEMA_ANNOTATION_KEYS} and
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
		if ($this->isOpenRegisterAvailable() === false) {
			return 0;
		}

		return $this->schemaAnnotations->reconcile();
	}//end reconcileSchemaDeclarativeConfig()
}//end class
