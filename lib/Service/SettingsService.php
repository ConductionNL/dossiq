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
 * @spec openspec/specs/admin-settings/spec.md
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
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — config bridge mapping ~73 schema slugs to appconfig keys; breadth is data, not branching
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
        // Dwangsom callback HMAC secret — never expose to non-admin callers.
        'dwangsom_callback_secret',
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
        // Federated case collaboration (OCM, via OpenRegister's federation leaf).
        'case_federated_share_schema',
        'case_federated_activity_schema',
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
        // DMN decision tables (dmn-decision-tables spec).
        'decision_table_schema',
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
        // KCC-werkplek bridge schema config keys (kcc-werkplek-zaaksysteem-bridge).
        'contactmoment_schema',
        'kcc_quick_action_schema',
        'belplan_schema',
        'specialist_beschikbaarheid_schema',
        'doorverbinding_schema',
        'klant_sentiment_schema',
        // KCC-werkplek bridge behaviour settings.
        'identification_method',
        'identification_score_threshold',
        'sentiment_polling_interval',
        'specialist_availability_polling_interval',
        'max_zaken_voorblad',
        'max_contactmomenten_history',
        'quick_action_templates',
        'belplan_overflow_threshold_wachttijd',
        'belplan_overflow_threshold_wachtrij_lengte',
        'sentiment_trigger_words',
        // Complaint management (klachtafhandeling) — Awb chapter 9.
        'complaint_schema',
        'hearing_schema',
        'complaint_disposition_schema',
        'complaint_category_schema',
        // Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
        'portaal_bericht_schema',
        'portaal_verzoek_schema',
        'portaal_notificatie_voorkeur_schema',
        // Termijnbewaking + dwangsom engine (AWB 4:13/4:14/4:17).
        'termijn_definitie_schema',
        'termijn_instance_schema',
        'termijn_gebeurtenis_schema',
        'ingebrekestelling_schema',
        'dwangsom_berekening_schema',
        'dwangsom_uitbetaling_schema',
        // Shared secret validating the X-Procest-Signature HMAC-SHA256 header
        // on the public dwangsom payment-confirmation callback (ADR-005;
        // enforce-dwangsom-callback-signature spec). Empty = callback fails
        // closed (401) rather than treated as an implicit pass.
        'dwangsom_callback_secret',
        // Mandaat-matrix authorization engine.
        'mandaterings_besluit_schema',
        'mandaat_schema',
        'organisatie_rol_schema',
        'medewerker_rol_toewijzing_schema',
        'mandaat_gebruik_schema',
        'mandaat_escalatie_schema',
        // Handler vervanging/waarneming (handler-vervanging-waarneming spec).
        'substitution_schema',
        // Archief / e-Depot SIP handover engine.
        'bewaar_termijn_regel_schema',
        'overdracht_trigger_schema',
        'sip_bundel_schema',
        'overdracht_transactie_schema',
        'archief_bewijs_schema',
        'overdracht_audit_log_schema',
        // Case-email integration (case-email-integration spec).
        // emailTemplate is the only net-new schema; sending/threading live in NC Mail.
        'email_template_schema',
        // Shared-mailbox poller / IMAP-side config (ADR-022 exception).
        'email_imap_host',
        'email_imap_port',
        'email_imap_encryption',
        'email_imap_username',
        'email_imap_password',
        'email_imap_folder',
        'email_transport',
        'email_poll_interval',
        'email_poll_batch_size',
        'email_max_attachment_size',
        // Consultation management (consultation-management spec).
        'consultation_schema',
        'advice_response_schema',
        'advisory_body_schema',
        // Besluitvorming workflow integration endpoints (besluitvorming-workflow spec).
        // Official publication (DROP / LVBB) — empty disables dispatch.
        'drop_lvbb_endpoint',
        'drop_lvbb_token',
        // Mandaatregister authority validation — empty falls back to manual confirmation.
        'mandaatregister_endpoint',
        'mandaatregister_token',
        // ZGW DRC case dossier (document-zaakdossier spec).
        'dossier_informatieobject_schema',
        'dossier_zaakinformatieobject_schema',
        'dossier_besluitinformatieobject_schema',
        'dossier_informatieobjecttype_schema',
        // Maximum upload size in bytes (0 = no app-level limit, NC limit applies).
        'dossier_max_file_size',
        // Toggle: organise ZIP export into per-informatieobjecttype sub-folders.
        'dossier_subfolder_per_type',
        // Comma-separated map of NC group ids to clearance levels, e.g.
        // "vertrouwelijk-cleared:vertrouwelijk,geheim-cleared:geheim". Empty
        // means every authenticated user has the baseline clearance below.
        'dossier_clearance_group_map',
        // Baseline clearance for any authenticated user lacking a mapped group.
        'dossier_default_clearance',
        // GIS / geo viewer settings (gis-integration spec).
        // Map library used by the frontend viewer ('leaflet' or 'openlayers').
        'geo_map_library',
        // Default map centre + zoom (Netherlands) for the cases-on-map view.
        'geo_default_center_lat',
        'geo_default_center_lon',
        'geo_default_zoom',
        // Pixel radius for client-side marker clustering.
        'geo_max_cluster_radius',
        // Toggle: expose the public /wfs/cases OGC WFS endpoint.
        'geo_wfs_endpoint_enabled',
        // PDOK Locatieserver cache TTL (seconds) + endpoint override.
        'pdok_locatieserver_cache_ttl',
        'pdok_locatieserver_url',
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
        'caseFederatedShare'           => 'case_federated_share_schema',
        'caseFederatedActivity'        => 'case_federated_activity_schema',
        'automaticAction'              => 'automatic_action_schema',
        'lhsMatrix'                    => 'lhs_matrix_schema',
        'lhsRecommendation'            => 'lhs_recommendation_schema',
        'location'                     => 'location_schema',
        'bezwaar'                      => 'bezwaar_schema',
        'bezwaaradviescommissie'       => 'bezwaaradviescommissie_schema',
        'bacAdviceRequest'             => 'bac_advice_request_schema',
        'beroep'                       => 'beroep_schema',
        'bezwaarDecision'              => 'bezwaar_decision_schema',
        'routingRule'                  => 'routing_rule_schema',
        'kccAgent'                     => 'kcc_agent_schema',
        'decisionTable'                => 'decision_table_schema',
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
        // KCC-werkplek bridge schemas (kcc-werkplek-zaaksysteem-bridge).
        'contactmoment'                => 'contactmoment_schema',
        'kccQuickAction'               => 'kcc_quick_action_schema',
        'belplan'                      => 'belplan_schema',
        'specialistBeschikbaarheid'    => 'specialist_beschikbaarheid_schema',
        'doorverbinding'               => 'doorverbinding_schema',
        'klantSentiment'               => 'klant_sentiment_schema',
        // Complaint management (klachtafhandeling) — Awb chapter 9.
        'complaint'                    => 'complaint_schema',
        'hearing'                      => 'hearing_schema',
        'complaintDisposition'         => 'complaint_disposition_schema',
        'complaintCategory'            => 'complaint_category_schema',
        // Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
        'portaalBericht'               => 'portaal_bericht_schema',
        'portaalVerzoek'               => 'portaal_verzoek_schema',
        'portaalNotificatieVoorkeur'   => 'portaal_notificatie_voorkeur_schema',
        // Termijnbewaking + dwangsom (AWB 4:13/4:14/4:17).
        'termijnDefinitie'             => 'termijn_definitie_schema',
        'termijnInstance'              => 'termijn_instance_schema',
        'termijnGebeurtenis'           => 'termijn_gebeurtenis_schema',
        'ingebrekestelling'            => 'ingebrekestelling_schema',
        'dwangsomBerekening'           => 'dwangsom_berekening_schema',
        'dwangsomUitbetaling'          => 'dwangsom_uitbetaling_schema',
        // Mandaat-matrix authorization engine.
        'mandateringsBesluit'          => 'mandaterings_besluit_schema',
        'mandaat'                      => 'mandaat_schema',
        'organisatieRol'               => 'organisatie_rol_schema',
        'medewerkerRolToewijzing'      => 'medewerker_rol_toewijzing_schema',
        'mandaatGebruik'               => 'mandaat_gebruik_schema',
        'mandaatEscalatie'             => 'mandaat_escalatie_schema',
        'substitution'                 => 'substitution_schema',
        // Archief / e-Depot SIP handover engine.
        'bewaarTermijnRegel'           => 'bewaar_termijn_regel_schema',
        'overdrachtTrigger'            => 'overdracht_trigger_schema',
        'sipBundel'                    => 'sip_bundel_schema',
        'overdrachtTransactie'         => 'overdracht_transactie_schema',
        'archiefBewijs'                => 'archief_bewijs_schema',
        'overdrachtAuditLog'           => 'overdracht_audit_log_schema',
        // Case-email integration (case-email-integration spec).
        'emailTemplate'                => 'email_template_schema',
        // Consultation management (consultation-management spec).
        'consultation'                 => 'consultation_schema',
        'adviceResponse'               => 'advice_response_schema',
        'advisoryBody'                 => 'advisory_body_schema',
        // Milestone tracking (milestone-tracking spec).
        'milestoneDefinition'          => 'milestone_definition_schema',
        'milestoneRecord'              => 'milestone_record_schema',
        // ZGW DRC case dossier (document-zaakdossier spec).
        'informatieobject'             => 'dossier_informatieobject_schema',
        'zaakinformatieobject'         => 'dossier_zaakinformatieobject_schema',
        'besluitinformatieobject'      => 'dossier_besluitinformatieobject_schema',
        'informatieobjecttype'         => 'dossier_informatieobjecttype_schema',
        // CMMN adaptive case-plan definitions (cmmn-adaptive-case spec).
        'caseModel'                    => 'case_model_schema',
    ];

    /**
     * Declarative `x-openregister-*` annotation blocks (declared inside a
     * schema's `configuration` in procest_register.json) that Procest
     * reconciles directly onto the live OpenRegister schema configuration.
     *
     * OpenRegister's app-config import does not reliably round-trip these
     * schema-level annotation blocks on an already-imported instance, so
     * {@see self::reconcileSchemaDeclarativeConfig()} merges them back in.
     */
    private const SCHEMA_ANNOTATION_KEYS = [
        'x-openregister-calculations',
        'x-openregister-references',
        'x-openregister-lifecycle',
        'x-openregister-aggregations',
        'x-openregister-object-source',
    ];

    /**
     * Default values for KCC-werkplek bridge behaviour settings.
     *
     * Used by getKccConfigValue() so that an unset app-config key resolves to
     * the documented default rather than an empty string.
     */
    public const KCC_DEFAULTS = [
        'identification_method'                      => 'both',
        'identification_score_threshold'             => '0.8',
        'sentiment_polling_interval'                 => '5',
        'specialist_availability_polling_interval'   => '30',
        'max_zaken_voorblad'                         => '10',
        'max_contactmomenten_history'                => '5',
        'belplan_overflow_threshold_wachttijd'       => '180',
        'belplan_overflow_threshold_wachtrij_lengte' => '5',
        'sentiment_trigger_words'                    => '["ongelooflijk","klacht","wethouder","advocaat","media","rechtszaak"]',
        'quick_action_templates'                     => '{}',
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
        'woo_publication_register'        => 'publication',
        'woo_publication_schema'          => 'publication',
        'woo_publication_document_schema' => 'document',
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
    public function isOpenRegisterAvailable(): bool
    {
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
    public function getApprovalService(): ?object
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ApprovalService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not access OpenRegister ApprovalService',
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
    public function getOpenRegisterClass(string $class): ?object
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return null;
        }

        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not access OpenRegister class',
                ['class' => $class, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getOpenRegisterClass()

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

            $configuredCount = $this->autoConfigureAfterImport(importResult: $importResult);
            $this->reconcileSchemaConfig();

            $this->logger->info(
                'Procest: Configuration imported and reconciled',
                ['version' => $configVersion, 'configured' => $configuredCount]
            );

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
     *
     * @spec openspec/specs/admin-settings/spec.md
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
     *
     * @spec openspec/specs/admin-settings/spec.md
     */
    public function getConfigValue(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end getConfigValue()

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
    public function getKccConfigValue(string $key): string
    {
        $default = (self::KCC_DEFAULTS[$key] ?? '');
        $value   = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
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
    public function getWooPublicationConfigValue(string $key): string
    {
        $default = (self::WOO_PUBLICATION_DEFAULTS[$key] ?? '');
        $value   = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
        if ($value === '') {
            return $default;
        }

        return $value;
    }//end getWooPublicationConfigValue()

    /**
     * Set a single configuration value.
     *
     * @param string $key   The configuration key
     * @param string $value The value to set
     *
     * @return void
     *
     * @spec openspec/specs/admin-settings/spec.md
     */
    public function setConfigValue(string $key, string $value): void
    {
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
     * This method closes that gap: for each schema slug Procest knows about it
     * resolves the LIVE schema ID via OpenRegister's SchemaMapper (slug-aware
     * `find()`) and writes the matching appconfig key. It is fully idempotent —
     * a key that already holds the correct ID is left untouched — so it is safe
     * to call on every install/upgrade and after every import.
     *
     * @return int The number of schema config keys (re)written.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function reconcileSchemaConfig(): int
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return 0;
        }

        try {
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not access OpenRegister SchemaMapper for reconcile',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }

        $written = 0;
        foreach (self::SLUG_TO_CONFIG_KEY as $slug => $configKey) {
            $written += $this->reconcileSingleSchemaKey(
                schemaMapper: $schemaMapper,
                slug: (string) $slug,
                configKey: $configKey
            );
        }

        $this->logger->info(
            'Procest: Reconciled schema config keys from OpenRegister',
            ['written' => $written]
        );

        return $written;
    }//end reconcileSchemaConfig()

    /**
     * Reconcile each schema's declarative `x-openregister-*` annotation blocks
     * (calculations, references, lifecycle, …) from procest_register.json onto
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
     * This method closes that gap declaratively: for every schema defined in the
     * (fragment-merged) register JSON it reads the annotation keys listed in
     * {@see self::SCHEMA_ANNOTATION_KEYS} and writes them onto the live schema's
     * configuration via the SchemaMapper, MERGING (never replacing) so existing
     * keys such as `objectNameField` are preserved. Fully idempotent: a schema
     * whose live configuration already matches is left untouched.
     *
     * @return int The number of schemas whose configuration was (re)written.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function reconcileSchemaDeclarativeConfig(): int
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return 0;
        }

        try {
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not access OpenRegister SchemaMapper for declarative reconcile',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }

        $configPath = __DIR__.'/../Settings/procest_register.json';
        if (file_exists($configPath) === false) {
            return 0;
        }

        $configData = json_decode((string) file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
            return 0;
        }

        // Fold modular register fragments on top so a schema's annotation
        // blocks declared in a register.d fragment are reconciled too.
        [$configData] = self::mergeRegisterFragments(
            base: $configData,
            fragmentDir: __DIR__.'/../Settings/register.d'
        );

        $schemas = ($configData['components']['schemas'] ?? []);
        if (is_array($schemas) === false) {
            return 0;
        }

        $written = 0;
        foreach ($schemas as $key => $schemaDef) {
            if (is_array($schemaDef) === false) {
                continue;
            }

            $fallbackSlug = '';
            if (is_string($key) === true) {
                $fallbackSlug = $key;
            }

            $slug        = ($schemaDef['slug'] ?? $fallbackSlug);
            $declaredCfg = ($schemaDef['configuration'] ?? []);
            if ($slug === '' || is_array($declaredCfg) === false) {
                continue;
            }

            // Collect only the declarative annotation blocks we own.
            $annotations = [];
            foreach (self::SCHEMA_ANNOTATION_KEYS as $annotationKey) {
                if (array_key_exists($annotationKey, $declaredCfg) === true) {
                    $annotations[$annotationKey] = $declaredCfg[$annotationKey];
                }
            }

            if ($annotations === []) {
                continue;
            }

            $written += $this->reconcileSingleSchemaDeclarativeConfig(
                schemaMapper: $schemaMapper,
                slug: (string) $slug,
                annotations: $annotations
            );
        }//end foreach

        $this->logger->info(
            'Procest: Reconciled declarative schema configuration from register JSON',
            ['written' => $written]
        );

        return $written;
    }//end reconcileSchemaDeclarativeConfig()

    /**
     * Merge one schema's declarative annotation blocks onto its live
     * OpenRegister configuration. Idempotent — returns 0 when the live
     * configuration already carries identical blocks.
     *
     * @param object               $schemaMapper The OpenRegister SchemaMapper.
     * @param string               $slug         The schema slug (e.g. 'case').
     * @param array<string, mixed> $annotations  The annotation blocks to merge.
     *
     * @return int 1 when the configuration was (re)written, 0 otherwise.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function reconcileSingleSchemaDeclarativeConfig(
        object $schemaMapper,
        string $slug,
        array $annotations
    ): int {
        try {
            // Find by slug with signature find($id, $_extend, $_rbac, $_multitenancy):
            // bypass RBAC + tenancy — the repair runs in a system context with no
            // active organisation.
            $schema = $schemaMapper->find($slug, [], false, false);
        } catch (\Throwable $e) {
            // Slug not present in this OpenRegister instance — skip it.
            return 0;
        }

        $current = ($schema->getConfiguration() ?? []);
        if (is_array($current) === false) {
            $current = [];
        }

        $merged  = $current;
        $changed = false;
        foreach ($annotations as $annotationKey => $annotationValue) {
            if (($current[$annotationKey] ?? null) !== $annotationValue) {
                $merged[$annotationKey] = $annotationValue;
                $changed = true;
            }
        }

        if ($changed === false) {
            return 0;
        }

        try {
            $schema->setConfiguration($merged);
            $schemaMapper->update($schema);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Failed to reconcile declarative configuration for schema '.$slug,
                ['exception' => $e->getMessage()]
            );
            return 0;
        }

        return 1;
    }//end reconcileSingleSchemaDeclarativeConfig()

    /**
     * Resolve one schema slug to its live ID and persist its appconfig key.
     *
     * Idempotent: returns 0 (and writes nothing) when the slug does not resolve
     * or the key already holds the correct ID; returns 1 when it (re)writes.
     *
     * @param object $schemaMapper The OpenRegister SchemaMapper.
     * @param string $slug         The schema slug (e.g. 'caseType').
     * @param string $configKey    The Procest appconfig key to write.
     *
     * @return int 1 when the key was (re)written, 0 otherwise.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    private function reconcileSingleSchemaKey(object $schemaMapper, string $slug, string $configKey): int
    {
        try {
            // Slug-aware lookup with RBAC + multi-tenancy disabled: the repair
            // step runs in a system context that has no active organisation,
            // and the schema set is app-owned config, not tenant data.
            // Signature is find($id, $_extend, $_rbac, $_multitenancy).
            $schema   = $schemaMapper->find($slug, [], false, false);
            $schemaId = (string) $schema->getId();
        } catch (\Throwable $e) {
            // Slug not present in this OpenRegister instance — skip it.
            return 0;
        }

        if ($schemaId === '') {
            return 0;
        }

        $current = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
        if ($current === $schemaId) {
            return 0;
        }

        $this->appConfig->setValueString(Application::APP_ID, $configKey, $schemaId);

        // Keep the stable workflow_definition_schema alias in sync.
        if ($slug === 'workflowTemplate') {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'workflow_definition_schema',
                $schemaId
            );
        }

        return 1;
    }//end reconcileSingleSchemaKey()

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
