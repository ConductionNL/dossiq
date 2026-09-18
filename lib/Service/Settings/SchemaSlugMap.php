<?php

/**
 * Dossiq schema slug map.
 *
 * The two declarative tables the schema reconcilers drive from: which
 * OpenRegister schema slug backs which dossiq appconfig key, and which
 * `x-openregister-*` annotation blocks dossiq owns on a schema's configuration.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService} — these are data, not
 * behaviour, and both reconcilers plus the post-import auto-configure step read
 * from them.
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

/**
 * Schema slug to appconfig key mapping, plus the owned annotation block names.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SchemaSlugMap {
	/**
	 * Mapping of schema slugs (from dossiq_register.json) to app config keys.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_TO_CONFIG_KEY = [
		// NOT 'catalog': opencatalogi owns that slug, and slugs are global on a
		// shared OpenRegister, so both definitions would resolve to each other.
		'zgwCatalogus' => 'catalogus_schema',
		'case' => 'case_schema',
		// `caseTask` is gone. remove-casetask deleted the schema from both
		// descriptors, so a mapping left here would ask SchemaKeyReconciler to
		// resolve a slug the register no longer declares, once per import. The
		// `task_schema` appconfig key survives as an inert row; see
		// {@see ConfigKeys::ALL}.
		'status' => 'status_schema',
		'statusRecord' => 'status_record_schema',
		'role' => 'role_schema',
		'result' => 'result_schema',
		'decision' => 'decision_schema',
		'caseType' => 'case_type_schema',
		'statusType' => 'status_type_schema',
		'resultType' => 'result_type_schema',
		'roleType' => 'role_type_schema',
		'propertyDefinition' => 'property_definition_schema',
		'documentType' => 'document_type_schema',
		'decisionType' => 'decision_type_schema',
		'zaaktypeInformatieobjecttype' => 'zaaktype_informatieobjecttype_schema',
		'caseProperty' => 'case_property_schema',
		'caseDocument' => 'case_document_schema',
		'caseObject' => 'case_object_schema',
		'customerContact' => 'customer_contact_schema',
		// One row per message the mailbox processed (inbound-mail-filters).
		'mailIntakeEntry' => 'mail_intake_entry_schema',
		'decisionDocument' => 'decision_document_schema',
		'dispatch' => 'dispatch_schema',
		'document' => 'document_schema',
		'documentLink' => 'document_link_schema',
		'usageRights' => 'usage_rights_schema',
		'notificationChannel' => 'kanaal_schema',
		'abonnement' => 'abonnement_schema',
		'inspectieChecklist' => 'inspectie_checklist_schema',
		'inspectieRapport' => 'inspectie_rapport_schema',
		'inspection' => 'inspection_schema',
		'inspectionChecklistTemplate' => 'inspection_checklist_template_schema',
		'inspectionChecklistRun' => 'inspection_checklist_run_schema',
		'handhavingsactie' => 'handhavingsactie_schema',
		'adviesAanvraag' => 'advies_aanvraag_schema',
		'mapLayer' => 'map_layer_schema',
		'wmsLayer' => 'wms_layer_schema',
		'workflowTemplate' => 'workflow_template_schema',
		'objection' => 'objection_schema',
		'hearingSession' => 'hearing_session_schema',
		'advisoryReport' => 'advisory_report_schema',
		'appealDecision' => 'appeal_decision_schema',
		'tenant' => 'tenant_schema',
		'aiAuditEntry' => 'ai_audit_entry_schema',
		'appointment' => 'appointment_schema',
		'appointmentProduct' => 'appointment_product_schema',
		'appointmentLocation' => 'appointment_location_schema',
		'caseShare' => 'case_share_schema',
		'partnerOrganization' => 'partner_organization_schema',
		'sharePermissionLevel' => 'share_permission_level_schema',
		'casetransfer' => 'case_transfer_schema',
		// The dated chain of holdings, and the pull that asks for one. Both are
		// custody-and-handover-of-a-case: the chain answers who held the case
		// in March, which the transfer record cannot, and the takeover is the
		// request the holder answers.
		'caseCustody' => 'case_custody_schema',
		'caseTakeover' => 'case_takeover_schema',
		// The sociaal-domein consent. Declared in `register.d/50-sociaal-domein.json`
		// since that fragment shipped and never mapped, so no service could
		// resolve it and the hand-off gate had nothing to read. Mapping it is
		// what turns a declared record into an enforced precondition (D-5).
		'toestemming' => 'consent_schema',
		'caseFederatedShare' => 'case_federated_share_schema',
		'caseFederatedActivity' => 'case_federated_activity_schema',
		'automaticAction' => 'automatic_action_schema',
		'lhsMatrix' => 'lhs_matrix_schema',
		'lhsRecommendation' => 'lhs_recommendation_schema',
		'location' => 'location_schema',
		'objectionProceeding' => 'bezwaar_schema',
		'bezwaaradviescommissie' => 'bezwaaradviescommissie_schema',
		'bacAdviceRequest' => 'bac_advice_request_schema',
		'beroep' => 'beroep_schema',
		'bezwaarDecision' => 'bezwaar_decision_schema',
		// Beschikking lifecycle (beschikking-generatie spec) — Awb besluit.
		// These four were imported by `register.d/30-beschikking.json` but never
		// mapped, so the reconciler never wrote their keys and every service that
		// resolved one threw `..._not_configured` on the first call.
		'beschikking' => 'beschikking_schema',
		'stateMachineLog' => 'state_machine_log_schema',
		'bezwaarTrigger' => 'bezwaar_trigger_schema',
		'mandateArrangement' => 'mandaat_regeling_schema',
		'routingRule' => 'routing_rule_schema',
		'kccAgent' => 'kcc_agent_schema',
		'decisionTable' => 'decision_table_schema',
		'callbackRequest' => 'callback_request_schema',
		'subsidieRegeling' => 'subsidie_regeling_schema',
		'subsidieAanvraag' => 'subsidie_aanvraag_schema',
		'subsidieBeoordeling' => 'subsidie_beoordeling_schema',
		'subsidieBeschikking' => 'subsidie_beschikking_schema',
		'subsidieUitvoering' => 'subsidie_uitvoering_schema',
		'interimReport' => 'tussenrapportage_schema',
		'subsidieVaststelling' => 'subsidie_vaststelling_schema',
		'terugvordering' => 'terugvordering_schema',
		'bewijsstuk' => 'bewijsstuk_schema',
		// KCC-werkplek bridge schemas (kcc-werkplek-zaaksysteem-bridge).
		'contactmoment' => 'contactmoment_schema',
		'kccQuickAction' => 'kcc_quick_action_schema',
		'belplan' => 'belplan_schema',
		'specialistBeschikbaarheid' => 'specialist_beschikbaarheid_schema',
		'doorverbinding' => 'doorverbinding_schema',
		'klantSentiment' => 'klant_sentiment_schema',
		// Complaint management (klachtafhandeling) — Awb chapter 9.
		'complaint' => 'complaint_schema',
		'hearing' => 'hearing_schema',
		'complaintDisposition' => 'complaint_disposition_schema',
		'complaintCategory' => 'complaint_category_schema',
		// Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
		'portaalBericht' => 'portaal_bericht_schema',
		'portaalVerzoek' => 'portaal_verzoek_schema',
		'portaalNotificatieVoorkeur' => 'portaal_notificatie_voorkeur_schema',
		// Termijnbewaking + dwangsom (AWB 4:13/4:14/4:17).
		'deadlineDefinition' => 'termijn_definitie_schema',
		'deadlineInstance' => 'termijn_instance_schema',
		'termijnGebeurtenis' => 'termijn_gebeurtenis_schema',
		'noticeOfDefault' => 'ingebrekestelling_schema',
		'penaltyPaymentCalculation' => 'dwangsom_berekening_schema',
		'dwangsomUitbetaling' => 'dwangsom_uitbetaling_schema',
		// Mandaat-matrix authorization engine.
		// KEY renamed with the schema slug; VALUE deliberately left as-is. The
		// value is the app-config key under which this schema's numeric id is
		// already stored on every existing install — renaming it would orphan
		// that id and the schema would silently resolve to nothing.
		'mandateDecision' => 'mandaterings_besluit_schema',
		// KEYS follow the renamed slugs; VALUES are deliberately unchanged.
		// Each value is the app-config key under which that schema's numeric id
		// is already stored on every existing install — renaming it orphans the
		// id and the schema silently resolves to nothing.
		'mandate' => 'mandaat_schema',
		'organisatieRol' => 'organisatie_rol_schema',
		'medewerkerRolToewijzing' => 'medewerker_rol_toewijzing_schema',
		'mandateUsage' => 'mandaat_gebruik_schema',
		'mandateEscalation' => 'mandaat_escalatie_schema',
		'substitution' => 'substitution_schema',
		// Archief / e-Depot SIP handover engine.
		'bewaarTermijnRegel' => 'bewaar_termijn_regel_schema',
		'overdrachtTrigger' => 'overdracht_trigger_schema',
		'sipBundel' => 'sip_bundel_schema',
		'overdrachtTransactie' => 'overdracht_transactie_schema',
		'archiefBewijs' => 'archief_bewijs_schema',
		'overdrachtAuditLog' => 'overdracht_audit_log_schema',
		// Case-email integration (case-email-integration spec).
		'emailTemplate' => 'email_template_schema',
		// Consultation management (consultation-management spec).
		'consultation' => 'consultation_schema',
		// One declared mechanism for everything a case waits on somebody else
		// to do (what-a-transition-declares). The advice request is the first
		// obligation of this kind rather than a second mechanism beside it.
		'obligation' => 'obligation_schema',
		'adviceResponse' => 'advice_response_schema',
		'advisoryBody' => 'advisory_body_schema',
		// Milestone tracking (milestone-tracking spec).
		'milestoneDefinition' => 'milestone_definition_schema',
		'milestoneRecord' => 'milestone_record_schema',
		// ZGW DRC case dossier (document-zaakdossier spec).
		'informatieobject' => 'dossier_informatieobject_schema',
		'zaakinformatieobject' => 'dossier_zaakinformatieobject_schema',
		'besluitinformatieobject' => 'dossier_besluitinformatieobject_schema',
		'informatieobjecttype' => 'dossier_informatieobjecttype_schema',
		// CMMN adaptive case-plan definitions (cmmn-adaptive-case spec).
		'caseModel' => 'case_model_schema',
		// The connections Dossiq has to systems outside it
		// (pluggable-integration-registry).
		'dossiqIntegration' => 'dossiq_integration_schema',
		// What a new instance starts with (starter-content-and-templates).
		// `shippedOrigin` is the provenance ledger: one row per seeded object,
		// carrying the set, its version and a fingerprint of what shipped, so
		// `shipped and untouched` is a comparison rather than a guess.
		'shippedOrigin' => 'shipped_origin_schema',
		'starterSetAdoption' => 'starter_set_adoption_schema',
		'reusableStep' => 'reusable_step_schema',
		'contentTemplate' => 'content_template_schema',
		// The domain a copy carries. The group already existed for the grant
		// (mandaat-matrix) and was never mapped, so no service could resolve
		// it; the domain copy is the first caller that needs to.
		'caseTypeGroup' => 'case_type_group_schema',
	];

	/**
	 * Declarative `x-openregister-*` annotation blocks (declared inside a
	 * schema's `configuration` in dossiq_register.json) that Dossiq
	 * reconciles directly onto the live OpenRegister schema configuration.
	 *
	 * OpenRegister's app-config import does not reliably round-trip these
	 * schema-level annotation blocks on an already-imported instance, so
	 * {@see SchemaAnnotationReconciler::reconcile()} merges them back in.
	 *
	 * @var string[]
	 */
	public const SCHEMA_ANNOTATION_KEYS = [
		'x-openregister-calculations',
		'x-openregister-references',
		'x-openregister-lifecycle',
		'x-openregister-aggregations',
		'x-openregister-object-source',
		// Which changes to a case are news to somebody who has already seen
		// it. OpenRegister's SubstantiveChangeEvaluator reads this block off
		// `Schema::getConfiguration()`, and an ABSENT block is not an inert
		// default: it means every non-computed property counts, so one bulk
		// correction marks four hundred cases unread. Leaving the key out of
		// this list would therefore not disable the badge, it would make it
		// cry wolf, with nothing anywhere saying so.
		'x-openregister-read-state',
		// What a typed case link is called from each side. OpenRegister's
		// RelationTypeResolver reads this vocabulary off
		// `Schema::getConfiguration()` and resolves a property's
		// `x-openregister-relation: {type: "vervolg"}` against it. A key the
		// vocabulary does not hold is DROPPED rather than carried, so on an
		// instance that imported the case schema before this block existed
		// every typed relation would quietly read as the property's own title
		// and "referenced by", with nothing anywhere reporting it. That is the
		// same silent fallback openregister#3764 exists to end.
		'x-openregister-relation-types',
		// Whether this schema's objects can be archived at all. OpenRegister's
		// ArchiveHandler refuses archive, restore, freeze and unfreeze on a
		// schema that does not declare it, and an absent block is the same
		// answer as `enabled: false`. On an instance that imported the case
		// schema before the block existed, leaving the key out of this list
		// would therefore leave Archive refusing every case with "this schema
		// does not declare x-openregister-archive", which reads as a broken
		// feature rather than as a configuration that never arrived.
		'x-openregister-archive',
		// What counts as the same case, and who may file one anyway.
		// OpenRegister's DuplicateDetectionService reads this block off
		// `Schema::getConfiguration()` and nowhere else, so an instance that
		// imported the case schema before the block existed answers the
		// dedup-check endpoint with an empty match list. That reads exactly
		// like "nothing looks like this case", which is the one answer a
		// duplicate warning must never give by accident. Carried here for the
		// same reason `x-openregister-read-state` is: an absent block is not
		// an inert default, it is a different answer.
		'x-openregister-dedup',
		// Which edge a grant travels down. OpenRegister resolves an inherited
		// grant from `Schema::getConfiguration()` and from nowhere else, and
		// an unknown configuration key is DROPPED on import in silence, so an
		// instance that never received this block answers that a deelzaak is
		// closed to somebody who holds the parent. That failure is invisible
		// from both ends: dossiq declared the edge, OpenRegister reports no
		// inheritance, and neither says the declaration never arrived. It is
		// listed here for the same reason `x-openregister-dedup` is, with one
		// difference worth stating: a dropped dedup block gives a wrong
		// answer about duplicates, and a dropped hierarchy block gives a wrong
		// answer about who may open a dossier.
		'x-openregister-hierarchy',
	];

	/**
	 * The stable alias key mirrored alongside the `workflowTemplate` schema id.
	 *
	 * Consumer specs (status-transition-engine, role-based-step-routing) resolve
	 * the workflow definition through this key rather than the legacy slug.
	 */
	public const WORKFLOW_DEFINITION_ALIAS = 'workflow_definition_schema';

	/**
	 * The schema slug whose id is mirrored under {@see self::WORKFLOW_DEFINITION_ALIAS}.
	 */
	public const WORKFLOW_TEMPLATE_SLUG = 'workflowTemplate';
}//end class
