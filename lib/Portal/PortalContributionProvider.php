<?php

/**
 * Dossiq Portal Contribution Provider
 *
 * Dossiq's contribution to the shared Portaliq external portal (hydra ADR-046
 * + contribution contract v2.2). Portaliq — the ONE shared portal for people
 * WITHOUT Nextcloud accounts — discovers this class by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and duck-types it
 * (getAudiences/getAudience + getContribution) via method_exists(), never
 * instanceof. This class is therefore deliberately PLAIN: no portaliq imports,
 * no `implements` clause, no info.xml dependency, no constructor dependencies.
 * Without portaliq installed it is inert and Dossiq behaves exactly as before.
 *
 * It moves Dossiq's four former in-app portal surfaces (ADR-046, procest#162)
 * into Portaliq's declarative contract, across three audiences:
 *
 *  - `supplier`  — a supplier's tenders, contracts, invoices and message inbox,
 *                  scoped by `supplierRef` (unchanged from the v1 provider).
 *  - `citizen`   — a citizen's own cases ('Mijn gemeente'), their portal
 *                  message inbox (berichtenbox) and their requests/complaints,
 *                  scoped by the pseudonymous subject reference the record
 *                  carries (`portaalSubject` / `recipientRef` / `submitterRef`).
 *  - `inspector` — an EXTERNAL field inspector's assigned inspection reports and
 *                  checklist runs, scoped by `assignedInspectorRef`.
 *
 * All scoping uses the subject's server-derived pseudonymous subjectRef as the
 * scope VALUE (Portaliq's default) — never a Nextcloud user id, because a portal
 * subject has no Nextcloud account by premise, and never a raw BSN/KvK, which is
 * one-way hashed into the subjectRef upstream. Every read collection ships an
 * explicit `fields` whitelist so Portaliq (which projects rows AFTER per-row
 * verification — identifiers always survive) never hands a subject a
 * staff/internal column. The whitelist tables, the scoping map and the
 * claim-names contract (`claims.procest.{bsn, supplierRef, inspectorRef}`, the
 * forward path for verified-claim scoping) live in
 * openspec/changes/move-portals-to-portaliq/design.md.
 *
 * Deferred create-actions (write-IDOR, portaliq#16): a citizen bezwaar
 * (needs a `tegenZaakId` client cross-reference + AWB deadline validation) and
 * an inspector run submit (needs a `case`/`template` client cross-reference)
 * cannot be safely stamped by Portaliq's flat writer, which only server-stamps
 * the scope field. Only the standalone citizen complaint (`createKlacht`) is
 * safe and shipped. See design.md "Deferred creates".
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Transitions\StatusPublicLabels;
use Throwable;

/**
 * Declares what an external Portaliq subject may see and do in Dossiq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by Portaliq's auth edge and MUST never be trusted from
 * the client (ADR-005). Returns null for any audience Dossiq does not serve
 * (fail-closed; the registry already filters by audience, but a provider must
 * not rely on that).
 *
 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
 */
class PortalContributionProvider {
	/**
	 * The OpenRegister register slug every collection/action below lives in.
	 *
	 * @var string
	 */
	// FROZEN: OpenRegister register SLUG, not this app's id, and unchanged by
	// the procest -> dossiq rename. The `claims.procest.*` claim names in the
	// docblock above are frozen for a different reason — they are a contract
	// the portal reads, so renaming them here would not rename them there.
	private const REGISTER = 'dossiq';

	/**
	 * The case fields a citizen may see.
	 *
	 * 🔴 THE ONE LIST, AND THE REASON IT IS A CONSTANT. The portal projects a
	 * case down to these fields, and the ontvangstbevestiging quotes a case
	 * back to the same person. Written twice, the two lists agree on the day
	 * they are written and diverge silently afterwards, and the first time
	 * anyone notices the divergence is a data-protection incident rather than a
	 * bug. So the acknowledgement reads this constant through
	 * {@see self::citizenCaseFields()} instead of keeping a second one.
	 *
	 * 🔑 `status` STAYS, AND IT IS NOT WHAT THE CITIZEN READS. It is the
	 * statusType's uuid, which the portal needs to tell one status from another
	 * and which says nothing to a person. The words come from
	 * `statusPublicLabel`, the case schema's own calculation over the linked
	 * statusType: its publicLabel, or its name when the status declares none
	 * (citizen-status-labels, REQ-CT-25). Both are named from
	 * {@see StatusPublicLabels} so the page and the portal cannot spell them
	 * differently.
	 *
	 * @var array<int, string>
	 */
	public const CITIZEN_CASE_FIELDS = [
		'identifier',
		'title',
		'caseType',
		'status',
		StatusPublicLabels::CASE_LABEL_FIELD,
		StatusPublicLabels::CASE_DESCRIPTION_FIELD,
		'result',
		'startDate',
		'endDate',
		'deadline',
	];

	/**
	 * Constructor.
	 *
	 * THE ONE DEPENDENCY, AND WHY IT IS OPTIONAL. Portaliq discovers this
	 * class by FQCN and may build it with `new`, so a required constructor
	 * argument would make the provider undiscoverable on exactly the
	 * instances it exists to serve. Defaulting to null keeps `new
	 * PortalContributionProvider()` working: the manifest below is still pure
	 * data, and only {@see self::caseTimeline()} needs the reader.
	 *
	 * @param CaseTimeline|null $timeline The one reader of the public feed, or null.
	 */
	public function __construct(
		private readonly ?CaseTimeline $timeline = null,
	) {
	}//end __construct()

	/**
	 * The public entries on one case, as the portal's case timeline.
	 *
	 * THE PROVIDER DOES NOT DECIDE WHAT IS PUBLIC. It asks
	 * {@see CaseTimeline::publicEntries()}, which is the same call
	 * `#PublicStatus` makes, so the portal and the status page cannot come to
	 * show different histories of the same case. A provider that filtered for
	 * itself would be the second reader this design exists to avoid.
	 *
	 * @param string $caseId The case the subject is looking at.
	 *
	 * @return array<int, array<string, mixed>> The public entries, newest first.
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function caseTimeline(string $caseId): array {
		if ($this->timeline === null) {
			return [];
		}

		try {
			return $this->timeline->publicEntries(caseId: $caseId);
		} catch (Throwable $e) {
			// THE BOUNDARY OWNS THIS DECISION, AND IT IS MADE ONCE. The reader
			// logs at warning and rethrows rather than reporting an empty
			// timeline it did not establish. Here, at the edge of a foreign
			// app that renders our contribution, a timeline that could not be
			// read must cost the citizen the history and not the page. The
			// null branch above answers the same empty list for the same
			// reason, so the two absences a portal can meet are handled in one
			// place instead of two.
			return [];
		}
	}//end caseTimeline()

	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first. Dossiq serves suppliers, the
	 * citizen ('Mijn gemeente') and external field inspectors.
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 */
	public function getAudiences(): array {
		return ['supplier', 'citizen', 'inspector'];
	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 */
	public function getAudience(): string {
		return 'supplier';
	}//end getAudience()

	/**
	 * The case fields a citizen may see, for a surface that quotes a case back.
	 *
	 * The ontvangstbevestiging names what was received, so it reads this rather
	 * than deciding for itself what a citizen may be shown.
	 *
	 * @return array<int, string> The field names.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function citizenCaseFields(): array {
		return self::CITIZEN_CASE_FIELDS;
	}//end citizenCaseFields()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject
	 *                                      (subjectRef, audience, organisation,
	 *                                      trust).
	 *
	 * @return array<string, mixed>|null The manifest, or null when not serving.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 */
	public function getContribution(array $subject): ?array {
		$audience = ($subject['audience'] ?? '');

		if ($audience === 'supplier') {
			return $this->supplierContribution();
		}

		if ($audience === 'citizen') {
			return $this->citizenContribution();
		}

		if ($audience === 'inspector') {
			return $this->inspectorContribution();
		}

		// Any audience Dossiq does not serve → null (fail-closed; ADR-005).
		return null;
	}//end getContribution()

	/**
	 * Manifest for the `supplier` audience (unchanged from the v1 provider).
	 *
	 * The supplier's tenders, contracts, invoices and message inbox, all scoped
	 * by the DEFAULT subjectRef == the record's `supplierRef`. Portaliq reads
	 * them RBAC-scoped to the subject; Dossiq exposes no portal endpoints of
	 * its own here.
	 *
	 * @return array<string, mixed> The supplier manifest.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 */
	private function supplierContribution(): array {
		return [
			'label' => 'Dossiq',
			'collections' => [
				[
					'id' => 'tenders',
					'register' => self::REGISTER,
					'schema' => 'supplierTender',
					'scopeField' => 'supplierRef',
					'label' => 'Aanbestedingen',
					'listable' => true,
				],
				[
					'id' => 'contracts',
					'register' => self::REGISTER,
					'schema' => 'supplierContract',
					'scopeField' => 'supplierRef',
					'label' => 'Contracten',
					'listable' => true,
				],
				[
					'id' => 'invoices',
					'register' => self::REGISTER,
					'schema' => 'caseSupplierInvoice',
					'scopeField' => 'supplierRef',
					'label' => 'Facturen',
					'listable' => true,
				],
				[
					'id' => 'messages',
					'kind' => 'inbox',
					'register' => self::REGISTER,
					'schema' => 'supplierMessage',
					'scopeField' => 'supplierRef',
					'label' => 'Berichten',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => ['tenderPublished', 'contractExpiring', 'invoiceDue'],
		];

	}//end supplierContribution()

	/**
	 * Manifest for the `citizen` audience (the 'Mijn gemeente' portal).
	 *
	 * `subject.subjectRef` is the citizen's pseudonymous, one-way subject
	 * reference. Every collection is scoped by the DEFAULT subjectRef against
	 * the reference the record already stores — never a raw BSN, which is
	 * hashed into the subjectRef upstream (so a `scopeClaim: 'bsn'` indirection
	 * would not match; see design.md):
	 *
	 *  - `mijnZaken` (`case`, scope `portaalSubject`) — the citizen's own cases,
	 *    field-projected to citizen-safe columns (case identity, type, status,
	 *    result, dates, deadline); assignee, confidentiality, workflow internals
	 *    and quality scores are dropped.
	 *  - `berichten` (`portaalBericht`, scope `recipientRef`, `kind: 'inbox'`) —
	 *    the citizen's berichtenbox: messages addressed to them.
	 *  - `verzoeken` (`portaalVerzoek`, scope `submitterRef`) — the citizen's own
	 *    requests/complaints/objections and their lifecycle status.
	 *
	 * Three creates ship. `createKlacht` (a standalone complaint) stamps
	 * `submitterRef` == subjectRef and whitelists only the citizen's own
	 * content, so it can never name another party's case at all.
	 *
	 * `createBezwaar` and `replyToMessage` DO name a case, and both declare it
	 * as a `crossRefs` reference to the citizen's own cases. Portaliq resolves
	 * that reference through the same scoped read it uses to show the citizen
	 * one of their cases, before anything is written, and refuses the whole
	 * write with 403 `cross_ref_refused` when it does not resolve. That guard
	 * is what these two were deferred on; without it a uuid in the body was
	 * accepted as typed.
	 *
	 * Neither lets the sender choose what the write IS. The `kind` of a bezwaar
	 * and the `direction` of a reply come from `defaults`, stamped server-side
	 * over the whitelisted body: a bezwaar and a klacht run different statutory
	 * clocks, and a form that let the sender pick would let one arrive dressed
	 * as the other.
	 *
	 * `againstDecisionId` stays OUT of the bezwaar's whitelist. A decision
	 * carries no portal scope of its own, so no reference to one can be guarded
	 * yet; the case it belongs to can be, and is.
	 *
	 * minTrust is `low` (Portaliq's password edge); raise to `substantial` once
	 * the DigiD broker lands and cases carry Wdo-level assurance.
	 *
	 * 🔴 THE DUPLICATE RULES ARE NOT CONTRIBUTED HERE, AND THAT IS THE POINT.
	 * `duplicate-warning-at-intake` asks the portal intake to carry the same
	 * rules as the desk. A `dedup` key on one of these entries would read as
	 * having done that, and nothing in Portaliq reads such a key: the manifest
	 * normaliser passes unknown keys through untouched, so it would sit in the
	 * contract for ever, look enforced, and enforce nothing.
	 *
	 * What actually carries the rules is the WRITE. `PortalObjectWriter` creates
	 * through OpenRegister's `ObjectService::saveObject()`, which is the same
	 * path the desk uses and the one `x-openregister-dedup` is evaluated on. So
	 * `createKlacht` is already scored against the rules `portaalVerzoek`
	 * declares in `register.d/50-zaakportaal.json`: the same applicant filing
	 * the same kind of request with the same subject is one pair the sweep finds
	 * and one the applicant can be warned about.
	 *
	 * What is still missing is the WARNING, not the rule. Showing a citizen the
	 * matches before they submit is a Portaliq surface, and Portaliq has none
	 * today. Until it does, a second complaint is filed and found rather than
	 * refused, which is the `onCreate: warn` the schema declares.
	 *
	 * @return array<string, mixed> The citizen manifest.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	private function citizenContribution(): array {
		return [
			'label' => 'Dossiq',
			'collections' => [
				[
					'id' => 'mijnZaken',
					'register' => self::REGISTER,
					'schema' => 'case',
					'scopeField' => 'portalSubject',
					'label' => 'Mijn zaken',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => self::CITIZEN_CASE_FIELDS,
					// The case detail carries what has happened on it. The
					// contract names the method rather than embedding the
					// entries, because the manifest is built once per subject
					// and a timeline is read once per case.
					'timeline' => [
						'label' => 'Wat er is gebeurd',
						'provider' => 'caseTimeline',
					],
				],
				[
					'id' => 'berichten',
					'kind' => 'inbox',
					'register' => self::REGISTER,
					'schema' => 'portaalBericht',
					'scopeField' => 'recipientRef',
					'label' => 'Berichten',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => [
						'caseReference',
						'senderType',
						'senderName',
						'subject',
						'content',
						'attachments',
						'direction',
						'sentAt',
						'readByRecipientAt',
					],
				],
				[
					'id' => 'verzoeken',
					'register' => self::REGISTER,
					'schema' => 'portaalVerzoek',
					'scopeField' => 'submitterRef',
					'label' => 'Mijn verzoeken',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => [
						'kind',
						'category',
						'subject',
						'rationale',
						'reference',
						'status',
						'submittedAt',
						'deadline',
						'withinTerm',
					],
				],
			],
			'actions' => [
				[
					'id' => 'createKlacht',
					'type' => 'create',
					'label' => 'Een klacht indienen',
					'register' => self::REGISTER,
					'schema' => 'portaalVerzoek',
					'scopeField' => 'submitterRef',
					'minTrust' => 'low',
					'fields' => [
						'kind',
						'category',
						'subject',
						'rationale',
						'attachments',
					],
				],
				[
					'id' => 'createBezwaar',
					'type' => 'create',
					'label' => 'Bezwaar maken',
					'register' => self::REGISTER,
					'schema' => 'portaalVerzoek',
					'scopeField' => 'submitterRef',
					'minTrust' => 'low',
					'fields' => [
						'subject',
						'rationale',
						'attachments',
						'againstCaseId',
					],
					// The kind is not the citizen's to choose. A bezwaar and a
					// klacht run different statutory clocks, and a form that
					// let the sender pick would let one arrive dressed as the
					// other.
					'defaults' => ['kind' => 'bezwaarschrift'],
					'crossRefs' => [
						'againstCaseId' => [
							'register' => self::REGISTER,
							'schema' => 'case',
							'scopeField' => 'portalSubject',
							'required' => true,
						],
					],
				],
				[
					'id' => 'replyToMessage',
					'type' => 'create',
					'label' => 'Antwoorden',
					'register' => self::REGISTER,
					'schema' => 'portaalBericht',
					// The citizen is the SENDER of a reply, so the reply is
					// scoped by who sent it. The inbox above is scoped by who
					// received it, which is the same person seen from the
					// other end.
					'scopeField' => 'senderRef',
					'minTrust' => 'low',
					'fields' => [
						'subject',
						'content',
						'attachments',
						'caseId',
					],
					'defaults' => [
						'direction' => 'citizen_to_handler',
						'senderType' => 'burger',
					],
					'crossRefs' => [
						'caseId' => [
							'register' => self::REGISTER,
							'schema' => 'case',
							'scopeField' => 'portalSubject',
							'required' => true,
						],
					],
				],
			],
			'notifications' => [],
		];

	}//end citizenContribution()

	/**
	 * Manifest for the `inspector` audience (an EXTERNAL field inspector).
	 *
	 * `subject.subjectRef` is the external inspector's pseudonymous portal
	 * reference — they have no Nextcloud account, so scoping is by the additive
	 * `assignedInspectorRef` (DEFAULT subjectRef), NOT the internal `inspector`
	 * NC-user-UID column. Two read collections, field-projected to the
	 * inspector's own result-level data (large/internal columns — the frozen
	 * `templateSnapshot`, raw per-item `responses`, `photos` blobs — are
	 * dropped):
	 *
	 *  - `inspectieRapporten` (`inspectieRapport`, scope `assignedInspectorRef`)
	 *    — the inspector's assigned/completed inspection reports.
	 *  - `checklistRuns` (`inspectionChecklistRun`, scope `assignedInspectorRef`)
	 *    — their checklist runs and lifecycle/result state.
	 *
	 * One write ships: `submitChecklistRun`. It is an UPDATE on a run the
	 * inspector is already assigned, not a create, and that is what removes the
	 * write-IDOR the submit was deferred over rather than guarding it. The old
	 * shape had the client send `case` and `template`, which the flat writer
	 * could not verify against the assignment; this one accepts neither. What
	 * arrives is the inspector's own answers, and the status is stamped by the
	 * server through `set`, so a run cannot be submitted as anything else.
	 *
	 * minTrust is `low` (Portaliq's password edge) pending an inspector identity
	 * broker.
	 *
	 * @return array<string, mixed> The inspector manifest.
	 *
	 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T1
	 */
	private function inspectorContribution(): array {
		return [
			'label' => 'Dossiq',
			'collections' => [
				[
					'id' => 'inspectieRapporten',
					'register' => self::REGISTER,
					'schema' => 'inspectieRapport',
					'scopeField' => 'assignedInspectorRef',
					'label' => 'Mijn inspecties',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => [
						'case',
						'checklist',
						'inspectionDate',
						'location',
						'result',
						'failedItems',
						'remarks',
						'followUpRequired',
					],
				],
				[
					'id' => 'checklistRuns',
					'register' => self::REGISTER,
					'schema' => 'inspectionChecklistRun',
					'scopeField' => 'assignedInspectorRef',
					'label' => 'Mijn checklists',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => [
						'case',
						'template',
						'templateVersion',
						'startedAt',
						'completedAt',
						'submittedAt',
						'status',
						'overallResult',
						'followUpType',
						'syncState',
					],
				],
			],
			'actions' => [
				[
					'id' => 'submitChecklistRun',
					'type' => 'update',
					'label' => 'Checklist indienen',
					'register' => self::REGISTER,
					'schema' => 'inspectionChecklistRun',
					'scopeField' => 'assignedInspectorRef',
					'minTrust' => 'low',
					'fields' => [
						'responses',
						'overallResult',
						'followUpType',
						'photos',
						'completedAt',
						'status',
					],
					// The transition is the server's. `status` is whitelisted only
					// so this may write it; the value comes from here and never from
					// the request.
					'set' => ['status' => 'submitted'],
				],
			],
			'notifications' => [],
		];

	}//end inspectorContribution()
}//end class
