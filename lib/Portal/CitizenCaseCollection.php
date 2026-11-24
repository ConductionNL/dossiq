<?php

/**
 * Dossiq citizen case collection.
 *
 * The resident's own cases as the portal declares them: the collection
 * "Mijn zaken" reads, the fields it projects, who is at turn, where it stands
 * and what a card shows.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\Transitions\StatusPublicLabels;

/**
 * Declares the citizen case collection; {@see CitizenManifest::caseCollection()} hands it on.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class CitizenCaseCollection {

	/**
	 * Constructor.
	 *
	 * @param string $caseListIntro The text an organisation shows above Mijn zaken; empty shows none.
	 */
	public function __construct(
		private readonly string $caseListIntro = '',
	) {
	}//end __construct()

	/**
	 * The resident's own cases: the collection "Mijn zaken" reads, the fields
	 * it projects, who is at turn, where it stands and what a card shows.
	 *
	 * Its own method because {@see CitizenManifest::collections()} is at the length
	 * phpmd refuses, and because this is the declaration a reader opens the
	 * file for.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-says-who-must-act-req-srpd-002
	 */
	public function declaration(): array {
		$collection = array_merge($this->caseDetailKeys(), $this->caseListKeys());
		// The organisation's own words above the list; portaliq draws a
		// contributed collection's `intro` above it.
		if (trim($this->caseListIntro) !== '') {
			$collection['intro'] = trim($this->caseListIntro);
		}

		return $collection;
	}//end declaration()

	/**
	 * What the case collection projects and what its detail shows.
	 *
	 * @return array<string, mixed> The keys.
	 */
	private function caseDetailKeys(): array {
		return [
			'id' => 'mijnZaken',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'case',
			'scopeField' => 'portalSubject',
			'label' => 'Mijn zaken',
			'listable' => true,
			'minTrust' => 'low',
			// The branch and the party ride along for portaliq's branch and
			// mandate filters only: they are not on CITIZEN_CASE_FIELDS, so the
			// acknowledgement never quotes them.
			'fields' => array_merge(
				PortalContributionProvider::CITIZEN_CASE_FIELDS,
				[CitizenManifest::BRANCH_FIELD, CitizenManifest::PARTY_FIELD]
			),
			// WHAT THE RESIDENT READS, labelled and typed. Without these
			// portaliq falls back to every projected field as plain text
			// under its key, uuids included (dossiq#3143). `caseType` and
			// `status` stay in the projection because the portal tells
			// cases and statuses apart by them; they are simply not shown.
			'columns' => PortalContributionProvider::CITIZEN_CASE_COLUMNS,
			'detail' => [
				'layout' => 'card',
				'fields' => PortalContributionProvider::CITIZEN_CASE_DETAIL_FIELDS,
				// A question about THIS case, started from its detail.
				'actions' => [PortalConversation::ASK_ACTION],
			],
			'fieldConfigs' => PortalContributionProvider::CITIZEN_CASE_DETAIL_LABELS,
			// The case detail carries what has happened on it. The
			// contract names the method rather than embedding the
			// entries, because the manifest is built once per subject
			// and a timeline is read once per case.
			...$this->casePageBlocks(),
			'turnField' => 'portalTurn',
			'dueField' => 'deadline',
			'valueLabels' => [
				'portalTurn' => [
					// The words of boards MijnZaken and MijnZakenTabel on the case
					// tag; `applicant` is drawn in the warning tone (portaliq#1253).
					'applicant' => 'Wacht op u',
					'thirdParty' => 'In behandeling',
					'us' => 'In behandeling',
				],
			],
		];
	}//end caseDetailKeys()

	/**
	 * The blocks a resident's case page shows under its fields: what happened,
	 * the documents, the messages and where the case stands.
	 *
	 * @return array<string, mixed>
	 */
	private function casePageBlocks(): array {
		return [
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
		// The messages about the case, both ways, newest first. Read per
		// case like the timeline, and only the resident's own.
		'messages' => (new PortalConversation())->caseMessagesBlock(),
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
		];
	}//end casePageBlocks()

	/**
	 * How "Mijn zaken" lists a case: what makes it a case, when it is closed,
	 * which branch filed it, its number and its case type.
	 *
	 * @return array<string, mixed> The keys.
	 */
	private function caseListKeys(): array {
		return [
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
			// THE PROPOSE FORM ON THE CASE. Portaliq's site finds the
			// `propose-change` action among the collection's row actions and
			// shows the resident's proposals beside it.
			'rowActions' => [CitizenManifest::PROPOSE_ACTION],
			// THE BRANCH A COMPANY FILED UNDER. A session restricted to one
			// branch reads only the cases whose `portalBranch` is that branch,
			// and a case filed before the field existed is hidden from it,
			// which portaliq calls the safe answer (portal-case-list-declarations D3).
			'branchField' => CitizenManifest::BRANCH_FIELD,
			// THE PARTY A CASE BELONGS TO. Portaliq lists, for a session acting
			// under a mandate, the cases whose `portalParty` is the mandate's
			// `onBehalfOf` (site-business-and-authorisation D2).
			'mandateField' => CitizenManifest::PARTY_FIELD,
			// THE CASE NUMBER, for a session that came in with a case number
			// instead of an account. Portaliq reads it only for a case type
			// whose `portalIdentityKind` admits `reference`, and no case type
			// does until portaliq checks the address against the case (D4).
			'referenceField' => 'identifier',
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
	}//end caseListKeys()
}//end class
