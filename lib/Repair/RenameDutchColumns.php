<?php

/**
 * Procest RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * ALL FIFTY OWNERS MOVE TOGETHER. The map below covers every property name in
 * the cluster, and each was checked to be free of a collision with its English
 * target before being added. A register-scoped step cannot rename a column for
 * one owner and not the rest — the others would silently read null.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so this is reversible
 *     and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
 */
class RenameDutchColumns implements IRepairStep {
	/**
	 * Slug prefix of the registers in scope.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'procest';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
	 * `requested_amount`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'aangevraagd_bedrag' => 'requested_amount',
		'aangevraagd_op' => 'requested_on',
		'aantal_verlengingen' => 'count_extensions',
		'aanvraag_datum' => 'request_date',
		'aanvraag_soort' => 'request_kind',
		'aanvraag_termijn_weken' => 'request_term_weeks',
		'aanvrager' => 'applicant',
		'aanvrager_bsn_ref' => 'applicant_bsn_ref',
		'aanvrager_kvk_ref' => 'applicant_kvk_ref',
		'aard' => 'nature',
		'aard_relatie_weergave' => 'nature_relationship_display',
		'acceptatie_tijd' => 'acceptance_time',
		'accountantsverklaring_drempel' => 'auditors_statement_threshold',
		'accountantsverklaring_vereist' => 'auditors_statement_required',
		'actie' => 'action',
		'actie_type' => 'action_type',
		'advies_instantie' => 'advice_authority',
		'advies_onderbouwing' => 'advice_substantiation',
		'afdeling' => 'department',
		'afgekeurd_reden' => 'rejected_reason',
		'afgewezen_reden' => 'rejected_reason',
		'afsluitdatum' => 'closure_date',
		'afstand_tot_arbeidsmarkt' => 'distance_to_labour_market',
		'akkoord' => 'approved',
		'akkoord_datum' => 'approved_date',
		'akkoord_door' => 'approved_by',
		'anonimisering_bij_delen' => 'anonymisation_at_parts',
		'archief_datum' => 'archive_date',
		'bedrag' => 'amount',
		'begin_geldigheid' => 'start_validity',
		'begroting' => 'budget',
		'begunstigingstermijn' => 'compliance_period',
		'behandelaar' => 'handler',
		'behandelaar_id' => 'handler_id',
		'behandeling' => 'handling',
		'bekendmaking_datum' => 'announcement_date',
		'beller_identificatie' => 'caller_identification',
		'bericht_id' => 'message_id',
		'berichtenbox_kanaal' => 'message_box_channel',
		'beroep_term' => 'appeal_term',
		'beschikking_geregistreerd_datum' => 'decision_registered_date',
		'beschikking_id' => 'decision_id',
		'beschikking_type' => 'decision_type',
		'beschikking_types' => 'decision_types',
		'beschikking_weken' => 'decision_weeks',
		'beschikkingsdatum' => 'decision_date',
		'besluit' => 'decision',
		'bestand_hash_sha256' => 'file_hash_sha256',
		'bestand_id' => 'file_id',
		'bestandsgrootte' => 'file_size',
		'bestandsnaam' => 'file_name',
		'betaald_bedrag' => 'paid_amount',
		'betrokken_afdeling' => 'involved_department',
		'betrokken_medewerker' => 'involved_employee',
		'bevestiging' => 'confirmation',
		'bewaar_termijn_jaren' => 'retention_term_years',
		'bewaartermijn_einde' => 'retention_period_end',
		'bewaartermijn_jaren' => 'retention_period_years',
		'bewijs_bestand_id' => 'evidence_file_id',
		'bewijs_materiaal' => 'evidence_material',
		'bewijsstukken' => 'supporting_documents',
		'bewijsstukken_index' => 'supporting_documents_index',
		'bezwaar_ontvangen' => 'objection_received',
		'bezwaar_termijn_eind_datum' => 'objection_term_end_date',
		'bezwaar_zaak_id' => 'objection_case_id',
		'bezwaartermijn_einde' => 'objection_period_end',
		'bijlagen' => 'attachments',
		'binnen_termijn' => 'within_term',
		'boven_vermogensvrijstelling' => 'above_asset_exemption',
		'burgerservicenummer' => 'citizen_service_number',
		'cascade_bezwaar_case' => 'cascade_objection_case',
		'categorie' => 'category',
		'categorieen' => 'categories',
		'certificaat_serienummer' => 'certificate_serial_number',
		'classificatie' => 'classification',
		'contactpersoon_beheer_emailadres' => 'contact_person_management_email_address',
		'contactpersoon_beheer_naam' => 'contact_person_management_name',
		'contactpersoon_beheer_telefoonnummer' => 'contact_person_management_phone_number',
		'cumulatiev_bedrag' => 'cumulative_amount',
		'dagen_impact' => 'days_impact',
		'datum' => 'date',
		'datum_afgerond' => 'date_completed',
		'datum_onderzoek' => 'date_investigation',
		'deadline_datum' => 'deadline_date',
		'deelnemers' => 'participants',
		'definitiev_bedrag' => 'definitive_amount',
		'documentatie_link' => 'documentation_link',
		'doorlooptijd_wettelijk' => 'lead_time_statutory',
		'doorverbindings_reden' => 'transfer_reason',
		'dossier_bundle' => 'file_bundle',
		'dso_toelichting' => 'dso_notes',
		'duur_maanden' => 'duration_months',
		'duur_seconden' => 'duration_seconds',
		'dwangsom' => 'penalty_payment',
		'dwangsom_bedrag' => 'penalty_payment_amount',
		'dwangsom_maximaal' => 'penalty_payment_maximum',
		'eenheid' => 'unit',
		'effectuerings_datum' => 'effectuation_date',
		'eind_datum' => 'end_date',
		'eind_tijd' => 'end_time',
		'einddatum' => 'end_date',
		'einddatum_actueel' => 'end_date_current',
		'einddatum_berekend' => 'end_date_calculated',
		'einde_geldigheid' => 'end_validity',
		'escalatie_level' => 'escalation_level',
		'escalatie_reden' => 'escalation_reason',
		'evaluatie_datum' => 'evaluation_date',
		'evaluatie_momenten' => 'evaluation_moments',
		'event_bericht_van_behandelaar' => 'event_message_from_handler',
		'event_termijn_herinnering' => 'event_term_reminder',
		'financiele_toets_oordeel' => 'financial_assessment_opinion',
		'financiele_verantwoording' => 'financial_accountability',
		'formaat' => 'format',
		'fractie_resultaten' => 'political_group_results',
		'functie' => 'role',
		'gearchiveerd_op' => 'archived_on',
		'geboorte' => 'birth',
		'gebruikte_voorwaarden' => 'used_terms',
		'geescaleerde_zaak' => 'escalated_case',
		'gekoppeld_aan' => 'linked_in',
		'gekoppeld_verplichting_id' => 'linked_commitment_id',
		'geldig_tot' => 'valid_to',
		'geldigheid_status' => 'validity_status',
		'gemandateerde_rol' => 'mandatee_role',
		'gemiddelde_behandelduur' => 'average_handling_duration',
		'gereageerd_op' => 'responded_on',
		'gerelateerde_zaken' => 'related_cases',
		'geslachtsnaam' => 'surname',
		'grondslag' => 'basis',
		'handelsnaam' => 'trade_name',
		'handtekening' => 'signature',
		'herinnering_datum' => 'reminder_date',
		'hoorgespreks_waiver' => 'hearing_waiver',
		'huidige_dag' => 'current_dag',
		'huidige_status' => 'current_status',
		'huidige_wachtrij_lengte' => 'current_queue_length',
		'huisnummer' => 'house_number',
		'identificatie_methode' => 'identification_method',
		'identificatie_score' => 'identification_score',
		'incident_datum' => 'incident_date',
		'indicatie_steller' => 'indication_author',
		'ingangsdatum' => 'effective_date',
		'ingangsdatum_gewenst' => 'effective_date_desired',
		'ingekeurde_bedrag' => 'approved_amount',
		'ingetrokken' => 'withdrawn',
		'inhoudelijke_toets_oordeel' => 'substantive_assessment_opinion',
		'inhoudelijke_voortgang' => 'substantive_progress',
		'interventie' => 'intervention',
		'interventie_step' => 'intervention_step',
		'intrekking_mogelijk' => 'withdrawal_possible',
		'intrekkings_datum' => 'withdrawal_date',
		'invorderingsrente_berekend' => 'collection_interest_calculated',
		'iv3_taakveld' => 'iv3_task_field',
		'jeugdige_leeftijd' => 'juvenile_age',
		'kcc_medewerker_id' => 'kcc_employee_id',
		'kenmerk' => 'reference',
		'klachtnummer' => 'complaint_number',
		'kvk_nummer' => 'kvk_number',
		'laatste_update' => 'last_update',
		'leeftijd_toestemmingsvereiste' => 'age_consent_requirement',
		'lees_bevestiging_op' => 'read_confirmation_on',
		'legesbedrag' => 'fee_amount',
		'locatie' => 'location',
		'looptijd_eind' => 'term_end',
		'looptijd_start' => 'term_start',
		'maatregelen' => 'measures',
		'mandaat_niveau' => 'mandate_level',
		'mandaatregeling_id' => 'mandate_scheme_id',
		'medewerker_id' => 'employee_id',
		'melding_ap' => 'report_ap',
		'melding_datum' => 'report_date',
		'melding_kanaal' => 'report_channel',
		'melding_referentie' => 'report_reference',
		'motivering' => 'rationale',
		'naam' => 'name',
		'naar_medewerker_id' => 'to_employee_id',
		'naar_wachtrij' => 'to_queue',
		'nieuwe_zaak_id' => 'new_case_id',
		'nieuwe_zaak_ids' => 'new_case_ids',
		'nummeraanduiding_id' => 'address_designation_id',
		'ondertekenaar' => 'signatory',
		'ondertekend_door' => 'signed_by',
		'ondertekend_op' => 'signed_on',
		'ondertekening_tijdstip' => 'signing_moment',
		'ontvangst_bevestiging_op' => 'receipt_confirmation_on',
		'ontvangst_datum' => 'receipt_date',
		'ontvangstbevestiging_deadline' => 'acknowledgement_of_receipt_deadline',
		'ontvangstdatum' => 'receipt_date',
		'ontvangstkanaal' => 'receipt_channel',
		'ontwerp_versie' => 'draft_version',
		'oordeel' => 'opinion',
		'oorzaak' => 'cause',
		'openingstijden' => 'opening_hours',
		'openstaande_voorschotten' => 'outstanding_advances',
		'opgesteld_datum' => 'prepared_date',
		'opgesteld_door' => 'prepared_by',
		'opschorting' => 'suspension',
		'opschorting_end' => 'suspension_end',
		'opschorting_start' => 'suspension_start',
		'organisatie' => 'organisation',
		'overleg_datum' => 'consultation_date',
		'parent_rol_id' => 'parent_role_id',
		'parent_zaak' => 'parent_case',
		'plaats' => 'place',
		'plafond_berekend' => 'plafond_calculated',
		'portaal_subject' => 'portal_subject',
		'prioriteit' => 'priority',
		'pro_voorziening' => 'pro_provision',
		'proceskostenvergoeding' => 'legal_costs_compensation',
		'project_einddatum' => 'project_end_date',
		'project_startdatum' => 'project_start_date',
		'publicatiedatum' => 'publication_date',
		'rapportage_periode_eind' => 'rapportage_period_end',
		'rapportage_periode_start' => 'rapportage_period_start',
		'realisatie_verplichtingen' => 'actuals_commitments',
		'rechtvaardiging' => 'justification',
		'rechtvaardiging_toelichting' => 'justification_notes',
		'recommended_interventie' => 'recommended_intervention',
		'reden' => 'reason',
		'referentie' => 'reference',
		'regeling_naam' => 'scheme_name',
		'registratiedatum' => 'registration_date',
		'rekeninghouder_naam' => 'account_holder_name',
		'resultaat' => 'result',
		'richting' => 'direction',
		'rol' => 'role',
		'rol_id' => 'role_id',
		'rol_naam' => 'role_name',
		'rol_op_moment_van_besluit' => 'role_on_moment_from_decision',
		'rol_type' => 'role_type',
		'routering_stappen' => 'routing_steps',
		'samenvatting' => 'summary',
		'samenwerkende_partijen' => 'collaborating_parties',
		'samenwerkverzoeken' => 'collaboration_requests',
		'sms_nummer' => 'sms_number',
		'soort' => 'kind',
		'source_bezwaar' => 'source_objection',
		'staatssteun_categorie' => 'state_aid_category',
		'staatssteun_grondslag' => 'state_aid_basis',
		'standaard_duur_dagen' => 'standard_duration_days',
		'standaard_duur_weken' => 'standard_duration_weeks',
		'start_datum' => 'start_date',
		'start_tijd' => 'start_time',
		'stemmen_voor' => 'votes_for',
		'straat' => 'street',
		'straatnaam' => 'street_name',
		'target_zaaktype' => 'target_case_type',
		'te_partijen' => 'recipient_parties',
		'tegen_beschikking_id' => 'against_decision_id',
		'tegen_zaak_id' => 'against_case_id',
		'telefoon' => 'phone',
		'terugval_actie' => 'fallback_action',
		'tijdstip' => 'moment',
		'toelichting' => 'notes',
		'toestemming_deelname_door_client' => 'consent_participation_by_client',
		'tot_bedrag' => 'to_amount',
		'totaal_weken' => 'total_weeks',
		'traject_soort' => 'traject_kind',
		'transcriptie' => 'transcript',
		'transcriptie_snippet' => 'transcript_snippet',
		'transfer_naar' => 'transfer_to',
		'trekt_in_besluit' => 'draws_in_decision',
		'trigger_nummer' => 'trigger_number',
		'tussenrapportage_frequentie' => 'interim_report_frequency',
		'tussenrapportage_termijn_weken' => 'interim_report_term_weeks',
		'tweede_behandelaar_id' => 'second_handler_id',
		'uiterlijke_reactiedatum' => 'latest_response_date',
		'validatie_rapport_id' => 'validation_rapport_id',
		'van_medewerker_id' => 'from_employee_id',
		'vastgelegd_via_kanaal' => 'recorded_via_channel',
		'vastgesteld_bedrag' => 'determined_amount',
		'vaststelling_termijn_weken' => 'determination_term_weeks',
		'vaststellingsdatum' => 'determination_date',
		'verantwoordelijke' => 'responsible',
		'verblijfplaats' => 'residence',
		'verdaagd_op' => 'adjourned_on',
		'verdaging_justificatie' => 'postponement_justification',
		'verdaging_mogelijk' => 'postponement_possible',
		'vergrendeld_op' => 'locked_on',
		'vergunningaanvraag_ref' => 'permit_application_ref',
		'verleend_bedrag' => 'granted_amount',
		'verleend_datum' => 'granted_date',
		'verleend_door' => 'granted_by',
		'verleend_door_bsn' => 'granted_by_bsn',
		'verleend_door_naam' => 'granted_by_name',
		'verlenging_historie' => 'extension_history',
		'verlenging_mogelijk' => 'extension_possible',
		'verlenging_van' => 'extension_from',
		'vermogensvrijstelling' => 'asset_exemption',
		'vernietiging_datum' => 'destruction_date',
		'vernietigingsdatum' => 'destruction_date',
		'verzoek_datum' => 'request_date',
		'verzoek_kanaal' => 'request_channel',
		'verzonden_door' => 'sent_by',
		'verzonden_op' => 'sent_on',
		'volgnummer' => 'sequence_number',
		'voorlopige_voorziening' => 'provisional_provision',
		'voornamen' => 'given_names',
		'voorstel_type' => 'proposal_type',
		'voorvoegsel' => 'name_prefix',
		'voorwaarden' => 'terms',
		'voorziening_requested' => 'provision_requested',
		'vraag' => 'question',
		'vraagstelling' => 'question_formulation',
		'waarde' => 'value',
		'werkelijke_betaaldatum' => 'actual_payment_date',
		'werkelijke_kosten_totaal' => 'actual_cost_total',
		'woonplaats' => 'city',
		'zaak_id' => 'case_id',
		'zaak_ids' => 'case_ids',
		'zaak_number' => 'case_number',
		'zaaktype' => 'case_type',
		'zaaktypes' => 'case_types',
		'aangezocht_bevoegd_gezag' => 'requested_competent_authority',
		'aanwezigen' => 'attendees',
		'accountantsverklaring_document' => 'auditors_statement_document',
		'adres' => 'address',
		'advies_document' => 'advice_document',
		'advies_text' => 'advice_text',
		'adviseur' => 'advisor',
		'afwijkend_dwangsom_regime' => 'deviating_penalty_payment_regime',
		'algoritme' => 'algorithm',
		'amendement_teller' => 'amendment_numerator',
		'archief' => 'archive',
		'archief_id' => 'archive_id',
		'archief_status' => 'archive_status',
		'archief_trigger_actief' => 'archive_trigger_active',
		'autorisatie_grond' => 'authorisation_ground',
		'beoordelaar' => 'assessor',
		'bericht_soort' => 'message_kind',
		'berichtenbox_actief' => 'message_box_active',
		'berichtenbox_bevestigd' => 'message_box_confirmed',
		'beslissing' => 'decision',
		'betaaldatum_uiterlijk' => 'payment_date_latest',
		'betaaltermijn_einde' => 'payment_term_end',
		'bevoegd_gezag' => 'competent_authority',
		'bevoegdheid_type' => 'competence_type',
		'bewijsstuk_type' => 'evidence_type',
		'bijstandsnorm' => 'social_assistance_norm',
		'bijzondere_persoonsgegevens' => 'special_personal_data',
		'bovenliggend_agendapunt' => 'parent_agenda_item',
		'bron_entiteit' => 'source_entity',
		'cofinanciering_list' => 'co_financing_list',
		'commissie' => 'committee',
		'conclusie' => 'conclusion',
		'contactmoment_id' => 'interaction_id',
		'context_overdracht' => 'context_transfer',
		'correctieverzoek' => 'correction_request',
		'dagtarief' => 'daily_rate',
		'deurwaarders_referentie' => 'bailiffs_reference',
		'doelen' => 'goals',
		'doelgroep' => 'target_group',
		'domein' => 'domain',
		'duur_ms' => 'duration_ms',
		'email_actief' => 'email_active',
		'email_adres' => 'email_address',
		'ernst_axis' => 'severity_axis',
		'escalatie_aanbevolen' => 'escalation_recommended',
		'event_document_toegevoegd' => 'event_document_added',
		'export_beperking' => 'export_restriction',
		'foto_required' => 'photo_required',
		'geadresseerde' => 'addressee',
		'geadviseerde_ondersteuning' => 'advised_support',
		'gedrag_axis' => 'behaviour_axis',
		'gedrag_row' => 'behaviour_row',
		'gemeente_code' => 'municipality_code',
		'gerelateerde_zaak_id' => 'related_case_id',
		'gevolg_column' => 'consequence_column',
		'gezin_id' => 'family_id',
		'goedkeuring_status' => 'approval_status',
		'griffierecht' => 'court_fee',
		'herbeoordeling_datum' => 'reassessment_date',
		'huishoudens_samenstelling' => 'households_composition',
		'huishoudens_situatie' => 'households_situation',
		'indicatiestelling_id' => 'needs_assessment_id',
		'inhoud_type' => 'content_type',
		'initiator_bevoegd_gezag' => 'initiator_competent_authority',
		'inkomen' => 'income',
		'inkomens_toets' => 'income_assessment',
		'integriteit' => 'integrity',
		'inzet_trajecten' => 'deployment_trajectories',
		'ip_adres' => 'ip_address',
		'juridische_grondslag' => 'legal_basis',
		'klager' => 'complainant',
		'klantmanager_id' => 'case_manager_id',
		'leeftijdsgroep' => 'age_group',
		'leverancier_keuze_burger' => 'supplier_choice_burger',
		'mdo_overleg_ids' => 'mdo_consultation_ids',
		'methode' => 'method',
		'naar' => 'to',
		'niveau' => 'level',
		'omvang' => 'size',
		'omvang_per_week' => 'size_per_week',
		'ondersteuningsvraag' => 'support_request',
		'ondertoezichtstelling_actief' => 'supervision_order_active',
		'onderzoek_termijn_overschreden_sinds' => 'investigation_term_exceeded_sinds',
		'onderzoek_verslag' => 'investigation_minutes',
		'onderzoek_weken' => 'investigation_weeks',
		'ontvanger_applicatie' => 'recipient_application',
		'ontvanger_gebruiker' => 'recipient_user',
		'ontvanger_organisatie' => 'recipient_organisation',
		'pauze_deadline' => 'pause_deadline',
		'pauzee_verlengings_duren' => 'pause_extension_duration',
		'pending_email_adres' => 'pending_email_address',
		'poging' => 'attempt',
		're_integratie_traject_id' => 're_integration_trajectory_id',
		'recht_op_bijstand' => 'right_on_social_assistance',
		'rechtsmiddelen_clausule' => 'legal_remedies_clause',
		'rechtsvorm' => 'legal_form',
		'referentienummer' => 'reference_number',
		'samengestelde_inhoud' => 'composite_content',
		'sectormodel' => 'sector_model',
		'sms_actief' => 'sms_active',
		'steller' => 'author',
		'stemmen_tegen' => 'votes_against',
		'subsidiebeschikking' => 'subsidy_decision',
		'subsidieregeling' => 'subsidy_scheme',
		'tegenprestatie_verplicht' => 'consideration_mandatory',
		'titel' => 'title',
		'toegangs_beperking' => 'access_restriction',
		'toewijzing_type' => 'allocation_type',
		'vereiste_context' => 'requirement_context',
		'verlengings_ruimte' => 'extension_capacity',
		'vermogen' => 'equity',
		'vermogens_toets' => 'equity_assessment',
		'verslag' => 'minutes',
		'verwijzer' => 'referrer',
		'verzending' => 'dispatch',
		'volgens_intent' => 'according_to_intent',
		'volgorde' => 'sort_order',
		'voorschot_schema' => 'advance_schema',
		'vorm' => 'form',
		'vrije_berichten_templates' => 'free_messages_templates',
		'vrijstelling_arbeidsverplichting' => 'exemption_work_obligation',
		'waarnemer_voor' => 'observer_for',
		'wijkteam' => 'district_team',
		'zaak' => 'case',
		'zender_applicatie' => 'sender_application',
		'zender_organisatie' => 'sender_organisation',
		'authenticatie' => 'authentication',
		'bron_id' => 'source_id',
		'ernst' => 'severity',
		'extern_entiteit' => 'external_entity',
		'extern_identificatie' => 'external_identification',
		'gebruikersnaam' => 'username',
		'gedrag' => 'behaviour',
		'laatste_synchronisatie' => 'last_synchronisation',
		'onderwerp' => 'subject',
		'ontvangen_op' => 'received_on',
		'portefeuillehouder' => 'portfolio_holder',
		'synchronisatie_status' => 'synchronisation_status',
		'trigger_terugvordering' => 'recovery_trigger',
		'wachtwoord_kluis_ref' => 'password_vault_ref',
		'zaak_identificatie' => 'case_identification',
		'zaak_identificatie_strategie' => 'case_identification_strategy',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move procest data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every procest shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchColumns: no procest shard tables on this install; nothing to do.');
			return;
		}

		$renamed = 0;
		$copied = 0;
		$refused = 0;

		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			$qTable = $this->quote(identifier: $table);

			foreach (self::COLUMN_MAP as $old => $new) {
				if (in_array($old, $columns, true) === false) {
					continue;
				}

				if ($this->hasCollision(columns: $columns, target: $new) === true) {
					$this->logger->warning(
						'RenameDutchColumns: two sources target one destination; migrating neither.',
						['table' => $table, 'source' => $old, 'destination' => $new]
					);
					$refused++;
					continue;
				}

				if (in_array($new, $columns, true) === false) {
					$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN '
						. $this->quote(identifier: $old) . ' TO ' . $this->quote(identifier: $new);
					if ($this->exec(sql: $sql) === true) {
						$renamed++;
					}

					continue;
				}

				$qNew = $this->quote(identifier: $new);
				$qOld = $this->quote(identifier: $old);
				$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
					. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
				if ($this->exec(sql: $sql) === true) {
					$copied++;
				}
			}//end foreach
		}//end foreach

		$output->info(
			'RenameDutchColumns: ' . $renamed . ' renamed, ' . $copied . ' back-filled, '
			. $refused . ' refused, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Whether another mapped source already targets the same destination here.
	 *
	 * @param array<int, string> $columns Column names present in the table.
	 * @param string $target The destination column name.
	 *
	 * @return bool True when two sources compete for one destination.
	 */
	private function hasCollision(array $columns, string $target): bool {
		$sources = 0;
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources++;
			}
		}

		return $sources > 1;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection:
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * Matching anchors on the `openregister_table_` MARKER rather than a computed
	 * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
	 * which a raw information_schema string never resolves.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIds();
		if ($ids === []) {
			return [];
		}

		$names = $this->openRegisterTableNames();
		if ($names === []) {
			return [];
		}

		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		$tables = [];
		foreach ($names as $name) {
			foreach ($markers as $marker) {
				$offset = strpos($name, $marker);
				if ($offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}

	/**
	 * Ids of every register whose slug starts with the prefix.
	 *
	 * Split out of shardTables() only to keep that method under phpmd's
	 * cyclomatic-complexity limit; the behaviour is unchanged.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIds(): array {
		try {
			return $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}

	/**
	 * Every table name containing the openregister shard marker.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end shardTables()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one statement, logging and swallowing failure.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
