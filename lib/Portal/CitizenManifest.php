<?php

/**
 * Dossiq Citizen Manifest
 *
 * What a resident's contribution declares: the collections they may read and
 * the actions they may start. Pure data, with no read in it.
 *
 * WHY IT IS NOT IN THE PROVIDER. It was, and the provider then held both the
 * declarations and the three readers portaliq calls per case, which took the
 * class past the length phpmd refuses. The split is along the seam that was
 * already there: this file answers "what does a resident get", the provider
 * answers "what does portaliq get when it asks about one case".
 *
 * The constants stay on {@see PortalContributionProvider}: they are the
 * contract portaliq reads, and moving them would rename them for everything
 * that cites them.
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
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\Transitions\StatusPublicLabels;

/**
 * The resident's collections and actions, as declared data.
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
 */
class CitizenManifest {
	/**
	 * What a Woo request asks, in the order the steps ask it. `collectionId`
	 * is not here: only the dossier variant carries it, and it is hidden.
	 */
	private const WOO_FIELDS = [
		'onderwerp',
		'omschrijving',
		'periodeVan',
		'periodeTot',
		'documentSoorten',
		'toelichting',
		'verzoekerNaam',
		'verzoekerEmail',
		'verzoekerType',
	];

	/**
	 * The words of each field, from the mockup (DossiqWoo.dc.html).
	 *
	 * NO `required` HERE, on purpose. Portaliq takes a required marker from a
	 * written schema's own `required` list or from the action's
	 * `requiredFields`, and drops `required` on a field config in silence
	 * (REQ-SMF-023/024). A `required` written here would read as a promise the
	 * form does not keep. Only `onderwerp` is required, because it is the only
	 * one dossiq's own route refuses a request without; whether the other five
	 * should be refused there too is decision 0.1 of this change.
	 */
	private const WOO_FIELD_CONFIGS = [
		'onderwerp' => ['label' => 'Waar gaat uw verzoek over?'],
		'omschrijving' => ['label' => 'Welke informatie wilt u hebben?', 'size' => 'large'],
		'periodeVan' => ['label' => 'Vanaf welke datum zoekt u informatie?'],
		'periodeTot' => ['label' => 'Tot en met welke datum?'],
		// The answer cards, not a dropdown: `widget: choices` with the options
		// in `optionsProviders` below. Portaliq reads the options from there
		// and nowhere else, so a `choices` list written inline here is dropped
		// in silence (measured through its own resolvers).
		'documentSoorten' => ['label' => 'Welke documenten zoekt u?', 'widget' => 'choices'],
		'toelichting' => ['label' => 'Wilt u nog iets toelichten?', 'size' => 'large'],
		'verzoekerNaam' => ['label' => 'Uw naam'],
		'verzoekerEmail' => ['label' => 'Uw e-mailadres'],
		'verzoekerType' => ['label' => 'U vraagt dit als', 'widget' => 'choices'],
	];

	/**
	 * Where the two multiple-choice fields get their options. The values are
	 * the ones `WooRequestForm` accepts and the case keeps; the labels are
	 * what the resident reads.
	 */
	private const WOO_OPTIONS = [
		'documentSoorten' => [
			'type' => 'static',
			'options' => [
				['value' => 'besluiten', 'label' => 'Besluiten en vergunningen'],
				['value' => 'rapporten', 'label' => 'Rapporten en adviezen'],
				['value' => 'correspondentie', 'label' => 'E-mails en brieven'],
				['value' => 'alles', 'label' => 'Alles wat de gemeente hierover heeft'],
			],
		],
		'verzoekerType' => [
			'type' => 'static',
			'options' => [
				['value' => 'burger', 'label' => 'Inwoner'],
				['value' => 'journalist', 'label' => 'Journalist'],
				['value' => 'organisatie', 'label' => 'Organisatie'],
			],
		],
	];

	/**
	 * The four steps of the request (site-woo-request-in-steps D1). Every
	 * field of `WOO_FIELDS` sits in exactly one of them, which is what
	 * portaliq requires to keep a step at all, and the last one reviews the
	 * answers instead of asking for more.
	 */
	private const WOO_STEPS = [
		[
			'id' => 'vraag',
			'title' => 'Uw vraag',
			'description' => 'Vertel ons waar uw vraag over gaat.',
			'fields' => ['onderwerp', 'omschrijving'],
		],
		[
			'id' => 'periode',
			'title' => 'Periode en documenten',
			'description' => 'Hoe preciezer uw vraag, hoe sneller u antwoord krijgt.',
			'fields' => ['periodeVan', 'periodeTot', 'documentSoorten', 'toelichting'],
		],
		[
			'id' => 'gegevens',
			'title' => 'Uw gegevens',
			'description' => 'Wij gebruiken deze gegevens alleen voor uw verzoek.',
			'fields' => ['verzoekerNaam', 'verzoekerEmail', 'verzoekerType'],
		],
		['id' => 'controleren', 'title' => 'Controleren en versturen', 'review' => true],
	];

	/**
	 * Save and come back: portaliq keeps the answers per signed-in resident
	 * for this many days and says so in the form. Dossiq stores nothing until
	 * the request is sent, so a draft never becomes a case, never starts a
	 * term and never reaches a handler's queue.
	 */
	private const WOO_DRAFT = ['retentionDays' => 30];

	/**
	 * What the resident reads once the request is in. `{identifier}` and
	 * `{deadline}` come from the answer the route gives, and a sentence whose
	 * placeholder has no value is left out, so a case whose deadline is not
	 * computed yet promises no date.
	 */
	private const WOO_CONFIRMATION = [
		'title' => 'Wij hebben uw Woo-verzoek ontvangen',
		'body' => 'Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord.',
		'next' => 'U vindt uw verzoek onder Zaken. U krijgt ook een ontvangstbevestiging in uw berichten.',
	];
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
	public function collections(): array {
		return [
			$this->caseCollection(),
			$this->questionsCollection(),
			[
				'id' => 'berichten',
				'kind' => 'inbox',
				// A letter from the organisation to the applicant also goes to
				// their government message box. Portaliq holds no BSN, so it
				// asks this method for the recipient per message (dossiq#3192).
				'messageBox' => ['recipientProvider' => 'messageBoxRecipient'],
				'register' => PortalContributionProvider::REGISTER,
				'schema' => 'portaalBericht',
				'scopeField' => 'recipientRef',
				'label' => 'Berichten',
				'listable' => true,
				'minTrust' => 'low',
				...PortalContributionProvider::CITIZEN_INBOX,
			],
			[
				'id' => 'verzoeken',
				'register' => PortalContributionProvider::REGISTER,
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
	}//end collections()

	/**
	 * What the organisation still needs from the resident
	 * (site-resident-portal-design D1).
	 *
	 * Its own method rather than one more literal in
	 * {@see self::collections()}: that method is already at the length
	 * phpmd refuses, and this is the collection a reader comes looking for
	 * when they ask what of an aanvullingsverzoek a resident may see.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-sees-what-the-organisation-still-needs-from-them-req-srpd-001
	 */
	private function questionsCollection(): array {
		return [
			// WHAT THE ORGANISATION STILL NEEDS FROM THE RESIDENT. An
			// aanvullingsverzoek the handler sent, scoped by the portal
			// subject the request copied from its case: a request on a
			// case without one reaches nobody (site-resident-portal-design
			// D1). Only the summary and the missing items are projected.
			// `rationale`, `party`, `recipient`, `requestedBy`,
			// `pauseReason` and the deadline internals are written for a
			// colleague and stay at the desk.
			'id' => 'vragenAanU',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'aanvullingsverzoek',
			'scopeField' => 'portalSubject',
			'label' => 'Wat wij nog van u nodig hebben',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => [
				'case',
				'summary',
				'missingItems',
				'hersteltermijn',
				'state',
				'requestedAt',
			],
			// Answered and withdrawn requests are not tasks. Presentation
			// only (portaliq "Manifest UI configuration is
			// presentation-only"), which is enough: every row is the
			// resident's own, so an answered one showing is untidy rather
			// than a leak.
			'defaultFilters' => ['state' => 'open'],
			'columns' => [
				['field' => 'summary', 'label' => 'Wat wij nodig hebben', 'render' => 'text'],
				['field' => 'hersteltermijn', 'label' => 'Graag voor', 'render' => 'date'],
				['field' => 'requestedAt', 'label' => 'Gevraagd op', 'render' => 'date'],
			],
		];
	}//end questionsCollection()

	/**
	 * The things a citizen may start from the portal.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function actions(): array {
		return [
			$this->complaintAction(),
			$this->objectionAction(),
			[
				'id' => 'replyToMessage',
				'type' => 'create',
				// WHICH FIELD THE OPEN CASE LANDS IN when a resident presses
				// "Bericht sturen" on their case page: portaliq's cta with
				// `withRecord: true` presets this one field to the record it
				// was opened for (site-mijn-omgeving-components REQ-SMO-024).
				// It is the field `crossRefs` below already guards, so the
				// preset is checked like any typed value.
				'recordField' => 'caseId',
				'label' => 'Antwoorden',
				'register' => PortalContributionProvider::REGISTER,
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
						'register' => PortalContributionProvider::REGISTER,
						'schema' => 'case',
						'scopeField' => 'portalSubject',
						'required' => true,
					],
				],
			],
			$this->amendCaseAction(),
			$this->startWooVerzoekAction(),
			// THE SAME REQUEST WITHOUT A DOSSIER: what the home tile and the
			// overview start (site-woo-request-in-steps D2). Same route, same
			// steps, no `attachTo`, so portaliq offers it anywhere a resident
			// is signed in rather than only on a dossier page.
			$this->startWooVerzoekAlgemeenAction(),
		];
	}//end actions()

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
		// THE DOSSIER GOES IN THE FIRST STEP, hidden. Portaliq gathers every
		// whitelisted field that no step names into a loose step of its own
		// ("more", with no title), so leaving `collectionId` out gave the
		// dossier variant a titleless fourth step holding one invisible field.
		// Measured through portaliq's own resolvers.
		$steps = self::WOO_STEPS;
		$steps[0]['fields'] = array_merge(['collectionId'], $steps[0]['fields']);

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
			'fields' => array_merge(['collectionId'], self::WOO_FIELDS),
			// WHICH FIELDS THE FORM REFUSES TO SEND EMPTY. Declared here, not
			// as `required` on the field: portaliq reads a required marker
			// only from the written schema's own `required` list and from an
			// action's `requiredFields` (REQ-SMF-023/024), and this action
			// forwards to dossiq's endpoint rather than writing a schema, so
			// nothing else could mark it. A `fieldConfigs.*.required` is
			// dropped in silence, which is how the marker was lost before.
			'requiredFields' => ['onderwerp'],
			'fieldConfigs' => (['collectionId' => ['visible' => false]] + self::WOO_FIELD_CONFIGS),
			'optionsProviders' => self::WOO_OPTIONS,
			'steps' => $steps,
			'draft' => self::WOO_DRAFT,
			'confirmation' => self::WOO_CONFIRMATION,
			'submitLabel' => 'Verzoek versturen',
			// Still here for a portal that renders no confirmation page: it
			// reads this one line instead (portaliq REQ-SMF-022).
			'successMessage' => 'Uw Woo-verzoek is ontvangen. U vindt het onder Mijn zaken.',
		];
	}//end startWooVerzoekAction()

	/**
	 * The same Woo request, started without a dossier
	 * (site-woo-request-in-steps D2).
	 *
	 * It posts to the same route, declares the same steps and is the action
	 * the home tile names. It carries no `attachTo` and no `collectionId`:
	 * `WooRequestIntake::start()` already opens a case without case objects
	 * when no dossier is given (REQ-WRI-002). The dossier variant keeps its
	 * `attachTo`, because on a dossier page that is what proves the dossier is
	 * the resident's before anything is forwarded.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-woo-request-in-steps/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-without-a-dossier-req-sws-002
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-dossiq-offers-its-start-points-to-the-signed-out-home-req-srpd-006
	 */
	private function startWooVerzoekAlgemeenAction(): array {
		return [
			'id' => 'startWooVerzoekAlgemeen',
			'label' => 'Informatie opvragen (Woo-verzoek)',
			// The sentence the home tile reads. The mockup's "binnen vijf
			// werkdagen belt een medewerker u" is a service promise dossiq
			// cannot know holds for an instance, so an editor adds that on the
			// page instead.
			'summary' => 'Vraag documenten van de gemeente op. Dit kan dankzij de Wet open overheid (Woo).',
			'audiences' => ['citizen', 'client'],
			'endpoint' => '/index.php/apps/dossiq/api/portal/woo-verzoek',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => self::WOO_FIELDS,
			'requiredFields' => ['onderwerp'],
			'fieldConfigs' => self::WOO_FIELD_CONFIGS,
			'optionsProviders' => self::WOO_OPTIONS,
			'steps' => self::WOO_STEPS,
			'draft' => self::WOO_DRAFT,
			'confirmation' => self::WOO_CONFIRMATION,
			'submitLabel' => 'Verzoek versturen',
			'successMessage' => 'Uw Woo-verzoek is ontvangen. U vindt het onder Mijn zaken.',
		];
	}//end startWooVerzoekAlgemeenAction()
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
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'case',
			'scopeField' => 'portalSubject',
			'minTrust' => 'low',
			'fields' => ['description'],
			'citizenWrite' => [
				'typeField' => 'caseType',
				'typeRegister' => PortalContributionProvider::REGISTER,
				'typeSchema' => 'caseType',
			],
		];
	}//end amendCaseAction()
	/**
	 * The resident's own cases: the collection "Mijn zaken" reads, the fields
	 * it projects, who is at turn, where it stands and what a card shows.
	 *
	 * Its own method because {@see self::collections()} is at the length
	 * phpmd refuses, and because this is the declaration a reader opens the
	 * file for.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-says-who-must-act-req-srpd-002
	 */
	private function caseCollection(): array {
		return [
			'id' => 'mijnZaken',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'case',
			'scopeField' => 'portalSubject',
			'label' => 'Mijn zaken',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => PortalContributionProvider::CITIZEN_CASE_FIELDS,
			// WHAT THE RESIDENT READS, labelled and typed. Without these
			// portaliq falls back to every projected field as plain text
			// under its key, uuids included (dossiq#3143). `caseType` and
			// `status` stay in the projection because the portal tells
			// cases and statuses apart by them; they are simply not shown.
			'columns' => PortalContributionProvider::CITIZEN_CASE_COLUMNS,
			'detail' => [
				'layout' => 'card',
				'fields' => PortalContributionProvider::CITIZEN_CASE_DETAIL_FIELDS,
			],
			'fieldConfigs' => PortalContributionProvider::CITIZEN_CASE_DETAIL_LABELS,
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
				'label' => 'Documenten',
				'provider' => 'caseDocuments',
			],
			// WHERE THE CASE STANDS, in the public steps of its own case
			// type. The method answers per case, as the timeline does
			// (site-resident-portal-design D3).
			'steps' => [
				'label' => 'Waar staat uw aanvraag?',
				'provider' => 'caseSteps',
			],
			// WHO IS AT TURN AND BY WHEN, on the case card. Portaliq reads
			// one field for the turn (`turnField`) and one for the date
			// (`dueField`). The WORDS are dossiq's and live here, so a
			// portal administrator can reword them per site rather than
			// waiting for a release (contribution-value-labels).
			'turnField' => 'portalTurn',
			'dueField' => 'deadline',
			'valueLabels' => [
				'portalTurn' => [
					// The Mijn Zuiddrecht boards' words on the case tag, which the
					// `yourTurn` key of the overview's cases block draws in the
					// warning tone (portaliq#1253).
					'applicant' => 'Wacht op u',
					'thirdParty' => 'Wij wachten op informatie van een ander',
					'us' => 'De gemeente is aan zet',
				],
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
				'register' => PortalContributionProvider::REGISTER,
				'schema' => 'caseType',
				'labelField' => 'title',
			],
		];
	}//end caseCollection()

	/**
	 * Making an objection: the start point a resident reads on the home page
	 * and starts from their case.
	 *
	 * Its own method because {@see self::actions()} is at the length phpmd
	 * refuses.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-dossiq-offers-its-start-points-to-the-signed-out-home-req-srpd-006
	 */
	private function objectionAction(): array {
		return [
			'id' => 'createBezwaar',
			'type' => 'create',
			// The case a resident objects to: portaliq's cta with
			// `withRecord: true` on the case page presets it to the case on
			// screen, and `crossRefs` below checks it is theirs.
			'recordField' => 'againstCaseId',
			'label' => 'Bezwaar maken',
			'summary' => 'Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar.',
			'audiences' => ['citizen', 'client'],
			'register' => PortalContributionProvider::REGISTER,
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
			// The case is the one on screen, not a box the resident fills in;
			// the other fields ask in the resident's words.
			'requiredFields' => ['rationale'],
			'fieldConfigs' => [
				'againstCaseId' => ['visible' => false],
				'subject' => ['label' => 'Waar gaat uw bezwaar over?'],
				'rationale' => ['label' => 'Waarom bent u het niet eens met het besluit?', 'size' => 'large'],
				'attachments' => ['label' => 'Stukken die u meestuurt'],
			],
			'crossRefs' => [
				'againstCaseId' => [
					'register' => PortalContributionProvider::REGISTER,
					'schema' => 'case',
					'scopeField' => 'portalSubject',
					'required' => true,
				],
			],
		];
	}//end objectionAction()

	/**
	 * The klacht a citizen files, about one of their cases or about the
	 * municipality in general.
	 *
	 * Pressed on a case page it carries that case (`recordField`), guarded
	 * against the citizen's own cases like the bezwaar's; filed from the
	 * "Klacht indienen" page it carries none, so the reference is not
	 * required. Its own method because {@see self::actions()} is at the
	 * length phpmd refuses.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/case-actions-on-the-case-page/specs/portal-contribution/spec.md#requirement-the-case-page-offers-bezwaar-and-klacht-for-the-case-on-screen
	 */
	private function complaintAction(): array {
		return [
			'id' => 'createKlacht',
			'type' => 'create',
			'recordField' => 'againstCaseId',
			'label' => 'Klacht indienen',
			// WHAT A VISITOR READS ON THE TILE before they sign in. One
			// sentence, from the approved mockup (DossiqHome.dc.html), and
			// the audiences it is offered to (site-resident-portal-design
			// D5). The mockup's service promise is left out: dossiq cannot
			// know it is true for an instance, so an editor adds it on the
			// page instead of the app claiming it.
			'summary' => 'Vertel ons wat er misging.',
			'audiences' => ['citizen', 'client'],
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'portaalVerzoek',
			'scopeField' => 'submitterRef',
			'minTrust' => 'low',
			'fields' => [
				'category',
				'subject',
				'rationale',
				'attachments',
				'againstCaseId',
			],
			// Stamped server-side, like the bezwaar's. Left to the
			// sender, the portal sent 'klacht', which the schema's enum
			// refuses, so every complaint answered 502 write_failed.
			'defaults' => ['kind' => 'klachtschrift'],
			// The case is the one on screen, not a box the resident fills in;
			// the "Klacht indienen" page leaves it empty.
			'requiredFields' => ['rationale'],
			'fieldConfigs' => [
				'againstCaseId' => ['visible' => false],
				'category' => ['label' => 'Waar gaat uw klacht over?'],
				'subject' => ['label' => 'Onderwerp'],
				'rationale' => ['label' => 'Wat ging er mis?', 'size' => 'large'],
				'attachments' => ['label' => 'Stukken die u meestuurt'],
			],
			'crossRefs' => [
				'againstCaseId' => [
					'register' => PortalContributionProvider::REGISTER,
					'schema' => 'case',
					'scopeField' => 'portalSubject',
					'required' => false,
				],
			],
		];
	}//end complaintAction()

}//end class
