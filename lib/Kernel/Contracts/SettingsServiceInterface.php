<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

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
	public function getConfigValue(string $key, string $default = ''): string;

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
	 * Same lazy-resolve contract as {@see self::getObjectService()}: OpenRegister is an
	 * optional runtime dependency, so the class is resolved through the
	 * container at call time rather than type-hinted in the constructor, and
	 * callers MUST handle null.
	 *
	 * @return object|null The OpenRegister FileService or null when unavailable
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function getFileService(): ?object;

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
	public function getKccConfigValue(string $key): string;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getObjectService(): ?object;

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
	 * @spec openspec/changes/archive/2026-06-14-migrate-parafering-to-or-approval-workflow/tasks.md#P0.1
	 */
	public function getOpenRegisterClass(string $class): ?object;

	/**
	 * The resident portal's name as portaliq holds it ("Mijn Zuiddrecht"),
	 * or '' when portaliq names none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/requester-identified-at-intake/spec.md#requirement-a-portal-intake-records-how-the-requester-logged-in-and-where
	 */
	public function getPortalName(): string;

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
	public function getPublicSettings(): array;

	/**
	 * Get all current settings as an associative array.
	 *
	 * Returns full (unredacted) settings including secrets, except the
	 * write-only keys, which read as `<key>Set`. Callers MUST
	 * ensure only admin users receive this response.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getSettings(): array;

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
	 * @spec openspec/changes/archive/2026-07-13-woo-publication-via-opencatalogi/design.md#d1
	 */
	public function getWooPublicationConfigValue(string $key): string;

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
	public function isOpenRegisterAvailable(): bool;

	/**
	 * Load the register configuration from dossiq_register.json via ConfigurationService.
	 *
	 * @param bool $force Whether to force re-import regardless of version
	 *
	 * @return array Import result
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadConfiguration(bool $force = false): array;

	/**
	 * Reconcile every `*_schema` appconfig key directly from OpenRegister.
	 *
	 * `autoConfigureAfterImport()` only persists schema IDs that appear in the
	 * ConfigurationService import RESULT. On an already-imported instance an
	 * idempotent re-import returns an empty `schemas` list, so the per-schema
	 * config keys (case_type_schema, status_type_schema, status_record_schema,
	 * workflow_template_schema, …) were never written — the status-name lookup
	 * and the workflow board then silently broke on a fresh deploy.
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
	public function reconcileSchemaConfig(): int;

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
	public function reconcileSchemaDeclarativeConfig(): int;

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
	public function setConfigValue(string $key, string $value): void;

	/**
	 * Update settings with the provided data.
	 *
	 * @param array $data The settings data to update
	 *
	 * @return array
	 *
	 * @throws \OCA\Dossiq\Exception\RefusedException When the throughput report would go on without an existing reader group.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public function updateSettings(array $data): array;
}//end interface
