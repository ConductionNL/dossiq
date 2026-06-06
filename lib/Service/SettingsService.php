<?php

/**
 * Procest Settings Service
 *
 * Service for managing Procest application configuration and settings.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Procest application configuration and settings.
 */
class SettingsService
{
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
    ];

    private const CONFIG_KEYS = [
        'register',
        'catalogus_schema',
        'case_schema',
        'task_schema',
        'status_schema',
        'status_record_schema',
        'role_schema',
        'result_schema',
        'decision_schema',
        'case_type_schema',
        'status_type_schema',
        'result_type_schema',
        'role_type_schema',
        'property_definition_schema',
        'document_type_schema',
        'decision_type_schema',
        'zaaktype_informatieobjecttype_schema',
        'case_property_schema',
        'case_document_schema',
        'case_object_schema',
        'customer_contact_schema',
        'decision_document_schema',
        'dispatch_schema',
        'document_schema',
        'document_link_schema',
        'usage_rights_schema',
        'kanaal_schema',
        'abonnement_schema',
        'map_layer_schema',
        // WMS/WFS overlay layers (wms-wfs-layers spec REQ-WMS-1).
        'wms_layer_schema',
        'workflow_template_schema',
        // Stable alias for consumer specs (status-transition-engine,
        // role-based-step-routing) that refer to the workflow definition
        // independent of the legacy schema slug.
        'workflow_definition_schema',
        'objection_schema',
        'hearing_session_schema',
        'advisory_report_schema',
        'appeal_decision_schema',
        'voorstel_schema',
        'parafeerroute_schema',
        'parafeeractie_schema',
        'parafering_audit_entry_schema',
        'default_case_type',
        'inspectie_checklist_schema',
        'inspectie_rapport_schema',
        'handhavingsactie_schema',
        'advies_aanvraag_schema',
        'advice_reminder_days',
        'tenant_schema',
        'appointment_schema',
        'appointment_product_schema',
        'appointment_location_schema',
        'appointment_backend',
        'appointment_backend_url',
        'appointment_backend_api_key',
        'appointment_reminder_days',
        'case_share_schema',
        'partner_organization_schema',
        'share_permission_level_schema',
        'case_transfer_schema',
        'automatic_action_schema',
        'location_schema',
        // Bezwaar (lifecycle) — Awb Hoofdstuk 7.
        'bezwaar_schema',
        // Bezwaar advisory committee (BAC) — Awb Art. 7:13.
        'bezwaaradviescommissie_schema',
        'bac_advice_request_schema',
        'bac_default_committee',
        // Beroep escalation (beroep-escalation spec) — Awb hoofdstuk 8.
        'beroep_schema',
        // Bezwaar decision (bezwaar-decision spec) — Awb art. 7:11/7:12.
        'bezwaar_decision_schema',
        // KCC klantcontact-integratie (kcc-klantcontact-integratie spec).
        // contactMoment reuses the existing customer_contact_schema; only the
        // KCC-specific operational schemas get new config keys here.
        'routing_rule_schema',
        'kcc_agent_schema',
        'callback_request_schema',
        // Subsidieverlening-keten (subsidieverlening-keten spec) — AWB titel 4.2.
        'subsidie_regeling_schema',
        'subsidie_aanvraag_schema',
        'subsidie_beoordeling_schema',
        'subsidie_beschikking_schema',
        'subsidie_uitvoering_schema',
        'tussenrapportage_schema',
        'subsidie_vaststelling_schema',
        'terugvordering_schema',
        'bewijsstuk_schema',
        'lhsMatrix',
        'lhs_matrix_schema',
        'lhs_recommendation_schema',
        // AI-Assisted Processing settings.
        'ai_audit_entry_schema',
        'ai_enabled',
        'ai_model_type',
        'ai_model_url',
        'ai_model_name',
        'ai_api_key',
        'ai_feature_classification',
        'ai_feature_extraction',
        'ai_feature_qa',
        'ai_feature_summary',
        'ai_feature_routing',
        'ai_feature_decision_support',
        'ai_dpia_acknowledged',
        'ai_pii_stripping',
        // PDOK integration settings (pdok-integration spec).
        // Endpoint overrides — empty falls back to PDOK service defaults.
        'pdok_locatieserver_endpoint',
        'pdok_bag_endpoint',
        'pdok_kadaster_endpoint',
        // OpenConnector source slugs — empty = call PDOK directly.
        'pdok_locatieserver_source',
        'pdok_bag_source',
        'pdok_kadaster_source',
        // Cache TTLs (seconds).
        'pdok_cache_lookup_ttl_seconds',
        'pdok_cache_suggest_ttl_seconds',
        // Per-service rate ceiling (requests / second).
        'pdok_rate_ceiling_rps',
        // Outage banner copy (nl + en).
        'pdok_outage_banner_nl',
        'pdok_outage_banner_en',
        // Consultation management (consultation-management spec).
        'consultation_schema',
        'advice_response_schema',
        'advisory_body_schema',
    ];

    /**
     * Mapping of schema slugs (from procest_register.json) to app config keys.
     */
    private const SLUG_TO_CONFIG_KEY = [
        'catalogus'                    => 'catalogus_schema',
        'case'                         => 'case_schema',
        'task'                         => 'task_schema',
        'status'                       => 'status_schema',
        'statusRecord'                 => 'status_record_schema',
        'role'                         => 'role_schema',
        'result'                       => 'result_schema',
        'decision'                     => 'decision_schema',
        'caseType'                     => 'case_type_schema',
        'statusType'                   => 'status_type_schema',
        'resultType'                   => 'result_type_schema',
        'roleType'                     => 'role_type_schema',
        'propertyDefinition'           => 'property_definition_schema',
        'documentType'                 => 'document_type_schema',
        'decisionType'                 => 'decision_type_schema',
        'zaaktypeInformatieobjecttype' => 'zaaktype_informatieobjecttype_schema',
        'caseProperty'                 => 'case_property_schema',
        'caseDocument'                 => 'case_document_schema',
        'caseObject'                   => 'case_object_schema',
        'customerContact'              => 'customer_contact_schema',
        'decisionDocument'             => 'decision_document_schema',
        'dispatch'                     => 'dispatch_schema',
        'document'                     => 'document_schema',
        'documentLink'                 => 'document_link_schema',
        'usageRights'                  => 'usage_rights_schema',
        'kanaal'                       => 'kanaal_schema',
        'abonnement'                   => 'abonnement_schema',
        'inspectieChecklist'           => 'inspectie_checklist_schema',
        'inspectieRapport'             => 'inspectie_rapport_schema',
        'inspection'                   => 'inspection_schema',
        'inspectionChecklistTemplate'  => 'inspection_checklist_template_schema',
        'inspectionChecklistRun'       => 'inspection_checklist_run_schema',
        'handhavingsactie'             => 'handhavingsactie_schema',
        'adviesAanvraag'               => 'advies_aanvraag_schema',
        'mapLayer'                     => 'map_layer_schema',
        'wmsLayer'                     => 'wms_layer_schema',
        'workflowTemplate'             => 'workflow_template_schema',
        'objection'                    => 'objection_schema',
        'hearingSession'               => 'hearing_session_schema',
        'advisoryReport'               => 'advisory_report_schema',
        'appealDecision'               => 'appeal_decision_schema',
        'voorstel'                     => 'voorstel_schema',
        'parafeerroute'                => 'parafeerroute_schema',
        'parafeeractie'                => 'parafeeractie_schema',
        'paraferingAuditEntry'         => 'parafering_audit_entry_schema',
        'tenant'                       => 'tenant_schema',
        'aiAuditEntry'                 => 'ai_audit_entry_schema',
        'appointment'                  => 'appointment_schema',
        'appointmentProduct'           => 'appointment_product_schema',
        'appointmentLocation'          => 'appointment_location_schema',
        'caseShare'                    => 'case_share_schema',
        'partnerOrganization'          => 'partner_organization_schema',
        'sharePermissionLevel'         => 'share_permission_level_schema',
        'casetransfer'                 => 'case_transfer_schema',
        'automaticAction'              => 'automatic_action_schema',
        'lhsMatrix'                    => 'lhs_matrix_schema',
        'lhsRecommendation'            => 'lhs_recommendation_schema',
        'location'                     => 'location_schema',
        'bezwaar'                      => 'bezwaar_schema',
        'bezwaaradviescommissie'       => 'bezwaaradviescommissie_schema',
        'bacAdviceRequest'             => 'bac_advice_request_schema',
        'beroep'                       => 'beroep_schema',
        'bezwaarDecision'              => 'bezwaar_decision_schema', << << <<< HEAD
        // Consultation management (consultation-management spec).
        'consultation'                 => 'consultation_schema',
        'adviceResponse'               => 'advice_response_schema',
        'advisoryBody'                 => 'advisory_body_schema',
    =======
        'routingRule'                  => 'routing_rule_schema',
        'kccAgent'                     => 'kcc_agent_schema',
        'callbackRequest'              => 'callback_request_schema',
        'subsidieRegeling'             => 'subsidie_regeling_schema',
        'subsidieAanvraag'             => 'subsidie_aanvraag_schema',
        'subsidieBeoordeling'          => 'subsidie_beoordeling_schema',
        'subsidieBeschikking'          => 'subsidie_beschikking_schema',
        'subsidieUitvoering'           => 'subsidie_uitvoering_schema',
        'tussenrapportage'             => 'tussenrapportage_schema',
        'subsidieVaststelling'         => 'subsidie_vaststelling_schema',
        'terugvordering'               => 'terugvordering_schema',
        'bewijsstuk'                   => 'bewijsstuk_schema',
    >>>>>>> origin/development
    ];

    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig  The app configuration service
     * @param IAppManager        $appManager The app manager service
     * @param ContainerInterface $container  The DI container
     * @param LoggerInterface    $logger     The logger interface
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if OpenRegister is installed and enabled.
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getObjectService(): ?object
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not access OpenRegister ObjectService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Load the register configuration from procest_register.json via ConfigurationService.
     *
     * @param bool $force Whether to force re-import regardless of version
     *
     * @return array Import result
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function loadConfiguration(bool $force=false): array
    {
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
                'Procest: Could not access ConfigurationService',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Could not access ConfigurationService: '.$e->getMessage(),
            ];
        }

        $configPath = __DIR__.'/../Settings/procest_register.json';
        if (file_exists($configPath) === false) {
            $this->logger->error(
                'Procest: Configuration file not found at '.$configPath
            );
            return [
                'success' => false,
                'message' => 'Configuration file not found',
            ];
        }

        $configContent = file_get_contents($configPath);
        $configData    = json_decode($configContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Procest: Invalid JSON in configuration file');
            return [
                'success' => false,
                'message' => 'Invalid JSON in configuration file',
            ];
        }

        // ADR-037: deep-merge any modular register fragments from
        // lib/Settings/register.d/*.json on top of the monolith. This lets
        // concurrent same-app builds add registers/schemas via isolated
        // fragment files instead of all editing procest_register.json and
        // conflicting. Fragments are applied in sorted filename order.
        [$configData, $fragmentHash] = self::mergeRegisterFragments(
            base: $configData,
            fragmentDir: __DIR__.'/../Settings/register.d'
        );

        $configVersion = ($configData['info']['version'] ?? '0.0.0');

        // Fold the fragment-set hash into the version so that adding,
        // changing, or removing a fragment forces ConfigurationService to
        // re-import (the version is its idempotency key).
        if ($fragmentHash !== '') {
            $configVersion = $configVersion.'+frag.'.$fragmentHash;
        }

        try {
            $importResult = $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $configData,
                version: $configVersion,
                force: $force,
            );

            $this->logger->info(
                'Procest: Configuration imported successfully',
                ['version' => $configVersion]
            );

            // Auto-configure schema IDs from import result.
            $configuredCount = $this->autoConfigureAfterImport(importResult: $importResult);

            return [
                'success'    => true,
                'message'    => 'Configuration imported and auto-configured ('.$configuredCount.' schemas mapped)',
                'version'    => $configVersion,
                'configured' => $configuredCount,
                'result'     => $importResult,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
            ];
        }//end try
    }//end loadConfiguration()

    /**
     * Get all current settings as an associative array.
     *
     * Returns full (unredacted) settings including secrets. Callers MUST
     * ensure only admin users receive this response.
     *
     * @return array

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getSettings(): array
    {
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
     */
    public function getPublicSettings(): array
    {
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        $this->logger->info('Procest settings updated', ['keys' => array_keys($data)]);

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Get a single configuration value by key.
     *
     * @param string $key     The configuration key
     * @param string $default The default value if key not found
     *
     * @return string
     */
    public function getConfigValue(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end getConfigValue()

    /**
     * Set a single configuration value.
     *
     * @param string $key   The configuration key
     * @param string $value The value to set
     *
     * @return void
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $key, $value);
    }//end setConfigValue()

    /**
     * Auto-configure schema and register IDs from the import result.
     *
     * Extracts schema entities from the ConfigurationService import result,
     * maps their slugs to app config keys, and persists the IDs.
     *
     * @param array $importResult The result from ConfigurationService::importFromApp()
     *
     * @return int The number of schemas successfully configured
     */
    private function autoConfigureAfterImport(array $importResult): int
    {
        $configuredCount = 0;

        // Configure register ID from imported registers.
        $registers = ($importResult['registers'] ?? []);
        foreach ($registers as $register) {
            if (is_object($register) === false) {
                continue;
            }

            $registerId = (string) $register->getId();
            $this->appConfig->setValueString(
                Application::APP_ID,
                'register',
                $registerId
            );
            $this->logger->info(
                'Procest: Auto-configured register ID',
                ['registerId' => $registerId]
            );
            break;
        }

        // Configure schema IDs from imported schemas.
        $schemas = ($importResult['schemas'] ?? []);
        foreach ($schemas as $schema) {
            if (is_object($schema) === false) {
                continue;
            }

            $slug = $schema->getSlug();
            if (isset(self::SLUG_TO_CONFIG_KEY[$slug]) === false) {
                continue;
            }

            $configKey = self::SLUG_TO_CONFIG_KEY[$slug];
            $schemaId  = (string) $schema->getId();

            $this->appConfig->setValueString(
                Application::APP_ID,
                $configKey,
                $schemaId
            );

            // Mirror the workflowTemplate schema id under the stable
            // workflow_definition_schema alias so consumer specs
            // (status-transition-engine, role-based-step-routing) can
            // resolve it without depending on the legacy slug.
            if ($slug === 'workflowTemplate') {
                $this->appConfig->setValueString(
                    Application::APP_ID,
                    'workflow_definition_schema',
                    $schemaId
                );
            }

            $this->logger->debug(
                'Procest: Auto-configured schema',
                [
                    'slug'      => $slug,
                    'configKey' => $configKey,
                    'schemaId'  => $schemaId,
                ]
            );

            $configuredCount++;
        }//end foreach

        $this->logger->info(
            'Procest: Auto-configuration complete',
            ['configuredSchemas' => $configuredCount]
        );

        return $configuredCount;
    }//end autoConfigureAfterImport()

    /**
     * Merge modular register fragments (ADR-037) onto a base configuration.
     *
     * Reads every `*.json` file in the given fragment directory in sorted
     * filename order and deep-merges each onto the base configuration. The
     * `README.md` (and any non-JSON files) are ignored. Returns the merged
     * configuration plus a short stable hash that fingerprints the applied
     * fragment set (filename + content), so callers can fold it into the
     * import version to force re-import when fragments change.
     *
     * @param array  $base        The parsed monolith configuration.
     * @param string $fragmentDir Absolute path to the register.d directory.
     *
     * @return array{0: array<string,mixed>, 1: string} The merged config and the fragment hash ('' when no fragments).
     */
    private static function mergeRegisterFragments(array $base, string $fragmentDir): array
    {
        if (is_dir($fragmentDir) === false) {
            return [$base, ''];
        }

        $files = glob($fragmentDir.'/*.json');
        if ($files === false || empty($files) === true) {
            return [$base, ''];
        }

        sort($files);

        $merged          = $base;
        $hashAccumulator = '';

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $fragment = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($fragment) === false) {
                continue;
            }

            $merged           = self::deepMergeConfig(base: $merged, override: $fragment);
            $hashAccumulator .= basename($file).':'.$content."\n";
        }//end foreach

        if ($hashAccumulator === '') {
            return [$merged, ''];
        }

        return [$merged, substr(hash('sha256', $hashAccumulator), 0, 12)];
    }//end mergeRegisterFragments()

    /**
     * Recursively deep-merge an override array onto a base array (ADR-037).
     *
     * Associative arrays (OpenAPI objects like `components.schemas`, `paths`)
     * are merged key-by-key, recursing on shared keys; list arrays (numeric,
     * sequential keys) are concatenated; scalar values from the override
     * overwrite the base. Disjoint fragments therefore union cleanly without
     * collision.
     *
     * @param array<int|string,mixed> $base     The base array.
     * @param array<int|string,mixed> $override The override array.
     *
     * @return array<int|string,mixed> The merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
            ) {
                if (self::isList(array: $value) === true && self::isList(array: $base[$key]) === true) {
                    $base[$key] = array_merge($base[$key], $value);
                    continue;
                }

                $base[$key] = self::deepMergeConfig(base: $base[$key], override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Determine whether an array is a sequential list (vs. an associative map).
     *
     * Backport of `array_is_list()` for portability across PHP runtimes.
     *
     * @param array<int|string,mixed> $array The array to inspect.
     *
     * @return bool True when the array has sequential integer keys from zero.
     */
    private static function isList(array $array): bool
    {
        if (function_exists('array_is_list') === true) {
            return array_is_list($array);
        }

        $expected = 0;
        foreach ($array as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }//end isList()
    }//end class
