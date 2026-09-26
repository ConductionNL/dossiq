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
use OCA\Dossiq\Service\Settings\ConfigurationImport;
use OCA\Dossiq\Service\Settings\OpenRegisterBridge;
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

	/**
	 * Reaching OpenRegister's optional services by name.
	 *
	 * @var OpenRegisterBridge
	 */
	private OpenRegisterBridge $openRegister;

	/**
	 * Importing the shipped register, and reconciling what the import drops.
	 *
	 * @var ConfigurationImport
	 */
	private ConfigurationImport $import;

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
		$this->openRegister = new OpenRegisterBridge(
			appManager: $appManager,
			container: $container,
			logger: $logger
		);

		$this->import = new ConfigurationImport(
			openRegister: $this->openRegister,
			appConfig: $appConfig,
			container: $container,
			logger: $logger
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
		return $this->openRegister->isAvailable();
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getObjectService(): ?object {
		return $this->openRegister->objectService();
	}//end getObjectService()

	/**
	 * Lazily resolve OpenRegister's per-object grant resolver.
	 *
	 * THE ONE THING DOSSIQ CANNOT ANSWER FOR ITSELF. A grant is a real
	 * Nextcloud share on the object's folder, resolved per request by
	 * OpenRegister, and since openregister#3873 that resolution walks the
	 * declared hierarchy: a grant on a parent case answers for its deelzaken.
	 * Asking this service is how dossiq consumes that instead of keeping a
	 * second, parallel answer, which is what ADR-022 is about and what
	 * `deelzaken-inherit-the-parent-grants` D-2 asks for by name.
	 *
	 * Same lazy-resolve contract as {@see self::getObjectService()}: an
	 * optional runtime dependency, resolved at call time rather than
	 * type-hinted, and callers MUST handle null. A null answer means dossiq
	 * cannot ask, which every caller here treats as NOT GRANTED — the
	 * fail-closed direction, and the behaviour dossiq had before inheritance
	 * existed at all.
	 *
	 * @return object|null OpenRegister's ObjectGrantResolver, or null when unavailable.
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	public function getObjectGrantResolver(): ?object {
		return $this->openRegister->objectGrantResolver();
	}//end getObjectGrantResolver()

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
	 * @spec openspec/changes/woo-publication-in-process-object-writes/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function getFileService(): ?object {
		return $this->openRegister->fileService();
	}//end getFileService()

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
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getApprovalService(): ?object {
		return $this->openRegister->approvalService();
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
	 * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getOpenRegisterClass(string $class): ?object {
		return $this->openRegister->classNamed(class: $class);
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
		return $this->import->loadConfiguration(force: $force);
	}//end loadConfiguration()

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
		return $this->import->reconcileSchemaConfig();
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
	 * The reconcile itself lives in {@see \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler}: for every
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
		return $this->import->reconcileSchemaDeclarativeConfig();
	}//end reconcileSchemaDeclarativeConfig()
}//end class
