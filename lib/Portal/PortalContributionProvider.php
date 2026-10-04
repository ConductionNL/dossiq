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
 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
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
 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
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
	 * What the citizen inbox projects, and where it keeps what portaliq's
	 * inbox shows.
	 *
	 * THE INBOX READS ITS OWN NAMES. portaliq's inbox shows `body`, sorts on
	 * `receivedAt` and counts `read`; `portaalBericht` keeps them as
	 * `content`, `sentAt` and `readByRecipientAt`. Without this map a
	 * handler's letter arrived as a subject line, dated nowhere, sorted last
	 * and never read (portaliq#702). Each name must also be in the
	 * `fields` whitelist below: portaliq projects before it maps. The files a
	 * handler attaches to a message are listed and downloadable for the
	 * resident who received it (`filesDownload`).
	 *
	 * @spec openspec/changes/portal-messages-name-their-inbox-fields/specs/portal-contribution/spec.md#requirement-req-portal-005-an-inbox-collection-must-name-the-fields-that-carry-its-message
	 */
	private const CITIZEN_INBOX = [
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
		'messageFields' => [
			'body' => 'content',
			'receivedAt' => 'sentAt',
			'readAt' => 'readByRecipientAt',
			'attachments' => 'attachments',
		],
		'filesDownload' => true,
	];

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
		// THE OUTCOME IN WORDS, NOT ITS UUID. `result` is a uuid reference to
		// the result record and says nothing to a person, so a resident read
		// their outcome as a long code (dossiq#3143). The case schema carries
		// the result's name and its public explanation as calculations over
		// the result record, the way it carries the status label.
		'resultPublicLabel',
		'resultPublicDescription',
		'startDate',
		'endDate',
		'deadline',
		// WHETHER THE CASE HAS ENDED, as "Mijn zaken" reads it (`closedField`).
		// A yes or no, calculated from the status type; nothing internal.
		'isFinalStatus',
		// WHEN THE CLOCK STARTS, not only when it ends. A case filed on a
		// Sunday evening does not start counting on Sunday evening, and until
		// these three were on this list nothing anywhere told the person who
		// filed it: they counted eight weeks from the moment they pressed send
		// and the municipality counted eight weeks from Monday
		// (intake-says-when-the-term-starts, row Q8.21). The portal projects a
		// case down to this list and the ontvangstbevestiging quotes it back,
		// so adding them here is what puts the answer on BOTH surfaces from
		// one definition.
		'receivedAt',
		'termStartsAt',
		'receivedOutsideWorkingHours',
		// WHERE THE DECISION ON A WOO REQUEST CAN BE READ. Set by dossiq when
		// the decision is published (woo-publish-decision-from-the-case D-8);
		// the change rule `dossiq.wooRequest.published` fires on it, so the
		// notice links to a case that shows the link. A public URL, nothing
		// internal.
		'wooPublicationUrl',
	];

	/**
	 * The columns of the resident's case list, in portaliq's `{field, label, render}` shape.
	 *
	 * Every field is on {@see self::CITIZEN_CASE_FIELDS}; no uuid is a column.
	 *
	 * @var array<int, array<string, string>>
	 */
	public const CITIZEN_CASE_COLUMNS = [
		['field' => 'identifier', 'label' => 'Zaaknummer', 'render' => 'text'],
		['field' => 'title', 'label' => 'Onderwerp', 'render' => 'text'],
		['field' => StatusPublicLabels::CASE_LABEL_FIELD, 'label' => 'Status', 'render' => 'badge'],
		['field' => 'resultPublicLabel', 'label' => 'Uitkomst', 'render' => 'text'],
		['field' => 'startDate', 'label' => 'Gestart op', 'render' => 'date'],
		['field' => 'deadline', 'label' => 'Uiterlijk klaar op', 'render' => 'date'],
		['field' => 'termStartsAt', 'label' => 'Termijn loopt vanaf', 'render' => 'date'],
		['field' => 'receivedOutsideWorkingHours', 'label' => 'Ontvangen buiten kantoortijd', 'render' => 'boolean'],
	];

	/**
	 * The fields a resident reads when they open one case. No uuid.
	 *
	 * @var array<int, string>
	 */
	public const CITIZEN_CASE_DETAIL_FIELDS = [
		'identifier',
		'title',
		StatusPublicLabels::CASE_LABEL_FIELD,
		StatusPublicLabels::CASE_DESCRIPTION_FIELD,
		'resultPublicLabel',
		'resultPublicDescription',
		'startDate',
		'endDate',
		'deadline',
		'receivedAt',
		'termStartsAt',
		'receivedOutsideWorkingHours',
		'wooPublicationUrl',
	];

	/**
	 * The resident's labels for the detail fields that are not a column.
	 *
	 * Portaliq labels a detail field by the column that shows it, then by the
	 * collection's `fieldConfigs.<field>.label`, and only then by the field's
	 * key as words. So a resident read "Status public description" and "End
	 * date" next to "Zaaknummer" and "Uitkomst". The labels live here, in
	 * portaliq's `fieldConfigs` shape, rather than on the schema's property
	 * titles, which the case screens of a colleague read too.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const CITIZEN_CASE_DETAIL_LABELS = [
		StatusPublicLabels::CASE_DESCRIPTION_FIELD => ['label' => 'Toelichting op de status'],
		'resultPublicDescription' => ['label' => 'Toelichting op de uitkomst'],
		'endDate' => ['label' => 'Einddatum'],
		'receivedAt' => ['label' => 'Ontvangen op'],
		'wooPublicationUrl' => ['label' => 'Gepubliceerd besluit'],
	];

	/**
	 * The rule key a resident is told by when the decision on their Woo request is published.
	 *
	 * @var string
	 */
	public const RULE_WOO_REQUEST_PUBLISHED = 'dossiq.wooRequest.published';

	/**
	 * The menu heading a resident reads above dossiq's pages (portaliq's `group`).
	 *
	 * Pages of several apps with the same group share one heading in the
	 * site's menu, so a resident sees what the pages are about, not which app
	 * made them.
	 *
	 * @var string
	 */
	public const CITIZEN_GROUP = 'Mijn zaken en verzoeken';

	/**
	 * The menu heading a supplier reads above dossiq's pages.
	 *
	 * @var string
	 */
	public const SUPPLIER_GROUP = 'Opdrachten en facturen';

	/**
	 * The menu heading an inspector reads above dossiq's pages.
	 *
	 * @var string
	 */
	public const INSPECTOR_GROUP = 'Inspecties';

	/**
	 * The page names that differ from their collection's label.
	 *
	 * THE SITE ALREADY HAS "MIJN ZAKEN" AND "BERICHTEN". Its own case list
	 * merges every app's cases and its own inbox merges every app's messages,
	 * so dossiq's pages under those names read as the same item twice. The
	 * pages stay: opening a case from the site's case list, or from the link
	 * in a notice, needs a page that shows `mijnZaken`, and only the
	 * `berichten` page offers the reply. They carry what they add instead.
	 *
	 * @var array<string, string>
	 */
	private const CITIZEN_PAGE_LABELS = [
		'mijnZaken' => 'Voortgang van uw zaken',
		'berichten' => 'Een bericht beantwoorden',
	];

	/**
	 * Builds the declared pages (no dependencies, so `new` still works).
	 *
	 * @var PortalPages
	 */
	private readonly PortalPages $pages;

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
	 * {@see self::caseDocuments()} needs the documents reader, optional for
	 * the same reason.
	 *
	 * {@see self::messageBoxRecipient()} needs the recipient reader, optional
	 * for the same reason.
	 *
	 * @param CaseTimeline|null              $timeline   The one reader of the public feed, or null.
	 * @param PortalCaseDocuments|null       $documents  The documents a resident may see on a case, or null.
	 * @param PortalMessageBoxRecipient|null $messageBox Who a portal letter goes to in the message box, or null.
	 */
	public function __construct(
		private readonly ?CaseTimeline $timeline = null,
		private readonly ?PortalCaseDocuments $documents = null,
		private readonly ?PortalMessageBoxRecipient $messageBox = null,
	) {
		$this->pages = new PortalPages();
	}//end __construct()

	/**
	 * The identity an inbox message goes to in the resident's government
	 * message box, or null to keep it in the portal (dossiq#3192).
	 *
	 * Portaliq calls this server-side, passes the value into integriq's send
	 * event and writes it nowhere. Who is named is decided in
	 * {@see PortalMessageBoxRecipient}, once.
	 *
	 * @param string $messageId The portaalBericht uuid.
	 *
	 * @return string|null The applicant's BSN, or null.
	 *
	 * @spec openspec/changes/portal-message-box-recipient/tasks.md
	 */
	public function messageBoxRecipient(string $messageId): ?string {
		if ($this->messageBox === null) {
			return null;
		}

		return $this->messageBox->forMessage(messageId: $messageId);
	}//end messageBoxRecipient()

	/**
	 * The documents a resident may see on one case (dossiq#3205).
	 *
	 * Portaliq calls this only after it proved the case is the resident's,
	 * never sends the `file` reference to the browser, and on a download asks
	 * again and streams only an entry this returned. What is published is
	 * decided in {@see PortalCaseDocuments}, once.
	 *
	 * @param string $caseId The case the resident is looking at.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, kind, date, file, mimeType?, size?}` per document.
	 *
	 * @spec openspec/changes/portal-case-documents/tasks.md
	 */
	public function caseDocuments(string $caseId): array {
		if ($this->documents === null) {
			return [];
		}

		return $this->documents->forCase(caseId: $caseId);
	}//end caseDocuments()

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
	 * resident ('Mijn gemeente') and external field inspectors.
	 *
	 * A RESIDENT ARRIVES AS `client`. Portaliq maps a DigiD or eIDAS login to
	 * audience `client` (its OidcClaimMapperService presets), and asks only the
	 * providers that advertise the session's audience, so a provider serving
	 * `citizen` alone was never asked about a DigiD session (dossiq#3152).
	 * `citizen` stays for sessions minted before `client` existed; both read
	 * the same manifest.
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
	 * @spec openspec/changes/portal-case-list-declarations/tasks.md#1.1
	 */
	public function getAudiences(): array {
		return ['supplier', 'citizen', 'client', 'inspector'];
	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
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
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
	 */
	public function getContribution(array $subject): ?array {
		$audience = ($subject['audience'] ?? '');

		if ($audience === 'supplier') {
			return $this->supplierContribution();
		}

		if ($audience === 'citizen' || $audience === 'client') {
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
	 * The supplier's tenders, contracts, invoices, performance figures and
	 * message inbox, all scoped by the DEFAULT subjectRef == the record's
	 * `supplierRef`. Portaliq reads
	 * them RBAC-scoped to the subject; Dossiq exposes no portal endpoints of
	 * its own here.
	 *
	 * @return array<string, mixed> The supplier manifest.
	 *
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
	 * @spec openspec/changes/portal-messages-name-their-inbox-fields/specs/portal-contribution/spec.md#requirement-req-portal-005-an-inbox-collection-must-name-the-fields-that-carry-its-message
	 */
	private function supplierContribution(): array {
		$contribution = [
			'label' => self::SUPPLIER_GROUP,
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
					// The supplier's own performance figures. `supplierKpi`
					// shipped with the portal's schema foundation and no
					// surface, so an instance computed a supplier's payment
					// record and showed it to nobody, least of all the
					// supplier it was about. Scoped by `supplierRef` like
					// every collection here, so a supplier reads their own
					// row and no one else's.
					'id' => 'performance',
					'register' => self::REGISTER,
					'schema' => 'supplierKpi',
					'scopeField' => 'supplierRef',
					'label' => 'Prestaties',
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
					// The inbox reads its own names (portaliq#702). Mark-read
					// writes the time to `readAt`'s field; without it a
					// supplier's message stayed unread forever.
					'messageFields' => [
						'receivedAt' => 'sentAt',
						'readAt' => 'readByRecipientAt',
						'attachments' => 'attachmentRefs',
					],
				],
			],
			'actions' => [],
			'notifications' => ['tenderPublished', 'contractExpiring', 'invoiceDue'],
		];
		$contribution['pages'] = $this->pages->forCollections(collections: $contribution['collections'], actions: [], group: self::SUPPLIER_GROUP);

		return $contribution;

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
	 * None lets the sender choose what the write IS. The `kind` of a klacht or bezwaar
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
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	private function citizenContribution(): array {
		$collections = $this->citizenCollections();
		$actions = $this->citizenActions();

		return [
			'label' => self::CITIZEN_GROUP,
			'collections' => $collections,
			'actions' => $actions,
			'pages' => $this->pages->withCaseScreen(
				pages: $this->pages->forCollections(collections: $collections, actions: $actions, group: self::CITIZEN_GROUP, labels: self::CITIZEN_PAGE_LABELS)
			),
			// A declared rule key, not a change rule: dossiq writes the
			// message itself (WooDecisionNotice), so portaliq sends its
			// e-mail. A change rule would add a generic "is bijgewerkt"
			// notice for the same publish.
			'notifications' => [self::RULE_WOO_REQUEST_PUBLISHED],
		];
	}//end citizenContribution()

	/**
	 * The collections a citizen may list, and what each one is scoped by.
	 *
	 * @return array<int, array<string, mixed>> The collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/portal-messages-name-their-inbox-fields/specs/portal-contribution/spec.md#requirement-req-portal-005-an-inbox-collection-must-name-the-fields-that-carry-its-message
	 * @spec openspec/changes/portal-case-page-withdraws/specs/portal-contribution/spec.md#requirement-req-portal-022-mijn-zaken-must-show-the-status-in-words
	 * @spec openspec/changes/portal-case-list-declarations/specs/portal-contribution/spec.md#requirement-the-case-list-says-what-is-a-case-and-when-it-is-closed-req-portal-010
	 */
	private function citizenCollections(): array {
		return [
			[
				'id' => 'mijnZaken',
				'register' => self::REGISTER,
				'schema' => 'case',
				'scopeField' => 'portalSubject',
				'label' => 'Mijn zaken',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::CITIZEN_CASE_FIELDS,
				// WHAT THE RESIDENT READS, labelled and typed. Without these
				// portaliq falls back to every projected field as plain text
				// under its key, uuids included (dossiq#3143). `caseType` and
				// `status` stay in the projection because the portal tells
				// cases and statuses apart by them; they are simply not shown.
				'columns' => self::CITIZEN_CASE_COLUMNS,
				'detail' => ['layout' => 'card', 'fields' => self::CITIZEN_CASE_DETAIL_FIELDS],
				'fieldConfigs' => self::CITIZEN_CASE_DETAIL_LABELS,
				// The case detail carries what has happened on it. The
				// contract names the method rather than embedding the
				// entries, because the manifest is built once per subject
				// and a timeline is read once per case.
				'timeline' => [
					'label' => 'Wat er is gebeurd',
					'provider' => 'caseTimeline',
				],
				// The documents the organisation publishes on the case, the
				// decision first. The method answers per case, and dossiq
				// decides what a resident may see (dossiq#3205).
				'documents' => [
					'label' => 'Stukken',
					'provider' => 'caseDocuments',
				],
				// LISTED ON "MY CASES". Portaliq's merged case list keeps only
				// collections of kind `cases` (PortalCaseListReader), and reads a
				// row as closed when `closedField` holds a value (false does not
				// count). `isFinalStatus` is OpenRegister's calculation over the
				// status type's `isFinal`, so it follows every way a case reaches
				// a final status. `endDate` did not: a withdrawal from the portal
				// lands on a final status without an end date, and a resident
				// read three withdrawn Woo requests under "Lopend" (site-parity,
				// 2026-10-02). It is on CITIZEN_CASE_FIELDS because portaliq
				// drops a closed marker the collection does not project.
				'kind' => 'cases',
				'closedField' => 'isFinalStatus',
				// THE STATUS IN WORDS ON "MIJN ZAKEN". `status` is a uuid the
				// portal needs to tell statuses apart; the merged case list
				// showed it as is. portaliq shows this field instead.
				'statusLabelField' => StatusPublicLabels::CASE_LABEL_FIELD,
				// Where the case type of a case lives, so a portal
				// administrator can hide a case type the portal has no form
				// for (portaliq operate-show-per-case-type).
				'caseTypeField' => 'caseType',
				'caseTypeSource' => [
					'register' => self::REGISTER,
					'schema' => 'caseType',
					'labelField' => 'title',
				],
			],
			[
				'id' => 'berichten',
				'kind' => 'inbox',
				// A letter from the organisation to the applicant also goes to
				// their government message box. Portaliq holds no BSN, so it
				// asks this method for the recipient per message (dossiq#3192).
				'messageBox' => ['recipientProvider' => 'messageBoxRecipient'],
				'register' => self::REGISTER,
				'schema' => 'portaalBericht',
				'scopeField' => 'recipientRef',
				'label' => 'Berichten',
				'listable' => true,
				'minTrust' => 'low',
				...self::CITIZEN_INBOX,
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
		];
	}//end citizenCollections()

	/**
	 * The things a citizen may start from the portal.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function citizenActions(): array {
		return [
			[
				'id' => 'createKlacht',
				'type' => 'create',
				'label' => 'Een klacht indienen',
				'register' => self::REGISTER,
				'schema' => 'portaalVerzoek',
				'scopeField' => 'submitterRef',
				'minTrust' => 'low',
				'fields' => [
					'category',
					'subject',
					'rationale',
					'attachments',
				],
				// Stamped server-side, like the bezwaar's. Left to the
				// sender, the portal sent 'klacht', which the schema's enum
				// refuses, so every complaint answered 502 write_failed.
				'defaults' => ['kind' => 'klachtschrift'],
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
			$this->amendCaseAction(),
			$this->startWooVerzoekAction(),
		];
	}//end citizenActions()

	/**
	 * A resident starts a Woo request from their dossier (hydra woo-citizen-journey C5).
	 *
	 * AN ENDPOINT ACTION, NOT A FLAT CREATE. The request writes a case, one
	 * case object per dossier item and a link back on the dossier, which
	 * portaliq's writer cannot do in one object. portaliq forwards the form to
	 * dossiq with a signed `X-Portal-Subject` assertion; dossiq takes the
	 * resident from that assertion, checks the dossier is theirs and calls
	 * {@see \OCA\Dossiq\Woo\WooRequestIntake}, the one path pipelinq's
	 * conversion uses too. No `type`, `register` or `schema`, the vocabulary of
	 * the fleet's reference endpoint action, so the flat writer never takes it
	 * for one of its own creates.
	 *
	 * `fields` is the whitelist portaliq rebuilds the body from. `subjectRef`
	 * and `origin` are not on it: the first comes from the assertion, the
	 * second is always `portal` here.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
	 */
	private function startWooVerzoekAction(): array {
		return [
			'id' => 'startWooVerzoek',
			'label' => 'Start een Woo-verzoek',
			'endpoint' => '/index.php/apps/dossiq/api/portal/woo-verzoek',
			'method' => 'POST',
			// ON THE DOSSIER PAGE (hydra woo-citizen-journey C7): portaliq shows
			// the action on opencatalogi's collection detail, proves the dossier
			// is the resident's through opencatalogi's own scope and forwards it
			// with `collectionId` set. WooRequestIntake checks ownership again.
			'attachTo' => ['app' => 'opencatalogi', 'schema' => 'collection'],
			'rowField' => 'collectionId',
			'minTrust' => 'low',
			'fields' => ['collectionId', 'onderwerp', 'omschrijving', 'periodeVan', 'periodeTot'],
			'fieldConfigs' => [
				'collectionId' => ['visible' => false],
				'onderwerp' => ['label' => 'Waar gaat uw verzoek over?', 'required' => true],
				'omschrijving' => ['label' => 'Welke informatie wilt u hebben?', 'size' => 'large'],
				'periodeVan' => ['label' => 'Periode vanaf'],
				'periodeTot' => ['label' => 'Periode tot en met'],
			],
			'submitLabel' => 'Verzoek versturen',
			'successMessage' => 'Uw Woo-verzoek is ontvangen. U vindt het onder Mijn zaken.',
		];
	}//end startWooVerzoekAction()

	/**
	 * The one update a resident may make on their own case.
	 *
	 * Portaliq's case screen (read the case with its documents, amend an
	 * answer, add a document, withdraw) runs only under a `type: update`
	 * action on the case's register and schema that carries `citizenWrite`;
	 * without one every dossiq case answered 403 `portal-writes-not-declared`
	 * (dossiq#3152). Portaliq takes the FIRST such action, so there is exactly
	 * one.
	 *
	 * `fields` IS THE CEILING. Whatever a case type opens in `portalWritable`,
	 * portaliq narrows it to this list. `description` is the resident's own
	 * account of what they asked; nothing a handler decides (status, result,
	 * deadlines, assignee) is on it. A withdrawal writes the status, but the
	 * status it lands on comes from the case type's `portalWithdrawal`, never
	 * from the request.
	 *
	 * `citizenWrite` names where the case type lives; portaliq reads the
	 * windows there and records each write in `portalWrites` on the case (its
	 * default `recordField`).
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/portal-citizen-writes-on-the-case/tasks.md#1.1
	 */
	private function amendCaseAction(): array {
		return [
			'id' => 'amendCase',
			'type' => 'update',
			'label' => 'Uw zaak aanpassen',
			'register' => self::REGISTER,
			'schema' => 'case',
			'scopeField' => 'portalSubject',
			'minTrust' => 'low',
			'fields' => ['description'],
			'citizenWrite' => [
				'typeField' => 'caseType',
				'typeRegister' => self::REGISTER,
				'typeSchema' => 'caseType',
			],
		];
	}//end amendCaseAction()

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
	 * @spec openspec/changes/archive/2026-09-09-move-portals-to-portaliq/tasks.md#T1
	 */
	private function inspectorContribution(): array {
		$contribution = [
			'label' => self::INSPECTOR_GROUP,
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
		$contribution['pages'] = $this->pages->forCollections(
			collections: $contribution['collections'],
			actions: $contribution['actions'],
			group: self::INSPECTOR_GROUP
		);

		return $contribution;

	}//end inspectorContribution()
}//end class
