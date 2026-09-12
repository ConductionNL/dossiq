<?php

/**
 * Dossiq appconfig key allowlist.
 *
 * The keys `SettingsService` reads on `getSettings()` and accepts on
 * `updateSettings()`. Data, not behaviour, so it lives beside
 * {@see SchemaSlugMap} rather than inside the service.
 *
 * It was extracted because `SettingsService` sat exactly on the phpmd
 * ExcessiveClassLength ceiling with 200 array entries inside it, so adding a
 * key for a new subsystem reddened the whole tree and the key was left out
 * instead. A list that is expensive to append to stops being appended to.
 *
 * A key here is visible on the admin settings surface. A schema id also needs
 * an entry in {@see SchemaSlugMap} for the reconciler to write it; the two
 * lists answer different questions and neither implies the other.
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

/**
 * The appconfig keys dossiq owns.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class ConfigKeys {
	/**
	 * Every appconfig key `SettingsService` reads and writes.
	 *
	 * @var string[]
	 */
	public const ALL = [
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
		// Beschikking lifecycle (beschikking-generatie spec) — Awb besluit.
		'beschikking_schema',
		'state_machine_log_schema',
		'bezwaar_trigger_schema',
		'mandaat_regeling_schema',
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
		// Mandate-matrix behaviour knobs edited by MandaatMatrixSettingsTab.vue.
		// ⚠️ Same measured caveat as the consultation_* block above: nothing
		// reads these three yet. They are registered so the admin's save
		// persists rather than being dropped by the CONFIG_KEYS allowlist.
		'mandaat_decidesk_connection',
		'mandaat_default_extension_days',
		'mandaat_auto_finalize_approved',
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
		// Outbound envelope + recipient policy (case-management REQ-103).
		// ⚠️ email_from_address was read by CaseEmailService and settable by
		// NOTHING: absent from this allow-list and from EmailSettings, so the
		// error text telling the admin to set it via the admin settings named a
		// field that did not exist. Registered here so it does.
		'email_from_address',
		'email_from_name',
		// Empty means "the from-address's own domain", never "no restriction".
		'email_recipient_allowlist',
		// Per-user mail matching (email-case-matching). Off unless an admin
		// says yes; an empty pattern means CaseEmailMatchService::DEFAULT_PATTERN.
		'email_case_matching_enabled',
		'email_case_matching_pattern',
		// Consultation management (consultation-management spec).
		'consultation_schema',
		'advice_response_schema',
		'advisory_body_schema',
		// Consultation behaviour knobs edited by ConsultationSettingsTab.vue.
		// ⚠️ Registered here because updateSettings() is an ALLOWLIST: a key
		// absent from CONFIG_KEYS is silently dropped on save — the same
		// silent-discard failure as the dead route these fields used to POST to
		// (procest#794), just one layer deeper.
		// ⚠️ MEASURED, not assumed: as of this commit NOTHING reads these four
		// values. A repo-wide grep finds them only in the Vue tab and in this
		// list; the n8n consultation workflows (n8n/consultation-deadline-
		// monitor.json, n8n/consultation-bottleneck-detection.json) do not
		// reference them either. Registering them makes the admin's save
		// round-trip honestly instead of vanishing; wiring a consumer is
		// tracked separately and must not be inferred from their presence here.
		'consultation_default_deadline_days',
		'consultation_warning_offset_days',
		'consultation_external_response_url',
		'consultation_bottleneck_threshold',
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
		// The two adapter seams an integrator may substitute WITHOUT editing
		// dossiq. Each names a class implementing the seam's interface; empty
		// means the built-in mock, which the Integrations page then reports as
		// Simulated rather than letting it pass for a working channel. Neither
		// transport ships here: both are per-customer contracts and belong in
		// integriq (ADR-041, and the dossiq-delivers-nothing ruling).
		'berichtenbox_adapter',
		'beschikking_template_adapter',
	];
}//end class
