<?php

/**
 * Dossiq WOO Decision Service
 *
 * Service for assembling the formal WOO besluit. Aggregates all document
 * assessments and writes a decision object linked to the case. Guards besluit
 * creation until every document has a classification with (where needed)
 * weigeringsgrond per WOO Art. 5.1/5.2.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\PagesSeenInterface;
use OCA\Dossiq\Kernel\Contracts\WOODecisionServiceInterface;
use OCA\Dossiq\Service\DecisionDocument\DecisionRaiser;
use OCA\Dossiq\Service\DecisionDocument\LocalDecisionRecord;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\Support\NamesTheSessionUser;
use OCA\Dossiq\Woo\WooPublicationSummary;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for assembling the formal WOO besluit.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class WOODecisionService implements WOODecisionServiceInterface {

	use NamesTheSessionUser {
		sessionActor as private resolveDecidedBy;
	}

	/**
	 * The seeded WOO-besluit decision type (register.d/81-woo-verzoek.json),
	 * which the Woo request case type lists in its `decisionTypes`.
	 *
	 * 🔴 THE UUID, NOT THE NAME. `decision.decisionType` is a uuid reference
	 * to a decisionType row; the name `WOO-besluit` was refused by
	 * OpenRegister on the first real save (e2e Woo journey, portaliq#1001),
	 * so assembling a decision answered 500.
	 *
	 * @var string
	 */
	public const DECISION_TYPE_ID = LocalDecisionRecord::DECISION_TYPE_ID;

	/**
	 * The `documentDrafts` purpose the decision document is drafted under.
	 */
	public const DRAFT_PURPOSE = 'decision';

	/**
	 * The `documentDrafts` purpose the inventory is drafted under.
	 */
	public const INVENTORY_PURPOSE = 'inventory';


	/**
	 * Composes and saves the decision record on the case.
	 *
	 * @var LocalDecisionRecord
	 */
	private readonly LocalDecisionRecord $localRecord;

	/**
	 * Raises the decision in decidiq.
	 *
	 * @var DecisionRaiser
	 */
	private readonly DecisionRaiser $raiser;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param WOODocumentAssessmentService $assessmentService Document assessment service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param CaseDocumentDrafts|null $drafts Drafts the decision document from the case type's
	 *        `documentDrafts.decision` template through filinq (case-document-drafts).
	 * @param PagesSeenInterface|null $pagesSeen The pages seen per document; the decision waits for the required ones.
	 * @param ContractDecisionDelegationService|null $decisionDelegation Raises the decision in decidiq;
	 *        without it no decision is assembled (fail closed, REQ-DDTD-001).
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		SettingsService $settingsService,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly IUserSession $userSession,
		LoggerInterface $logger,
		private readonly ?CaseDocumentDrafts $drafts = null,
		private readonly ?PagesSeenInterface $pagesSeen = null,
		?ContractDecisionDelegationService $decisionDelegation = null,
	) {
		$this->localRecord = new LocalDecisionRecord(settingsService: $settingsService, logger: $logger);
		$this->raiser      = new DecisionRaiser(settingsService: $settingsService, logger: $logger, decisionDelegation: $decisionDelegation);
	}//end __construct()

	/**
	 * Assemble the formal WOO besluit for a case.
	 *
	 * Validates that all documents are assessed and every required page of an
	 * in-scope document was seen (refused with 409 otherwise, woo-review-triage),
	 * then writes a decision object
	 * linked to the case referencing all assessments and weigeringsgronden.
	 *
	 * @param string $caseId The case UUID
	 * @param array<string, mixed> $decisionData Optional override data (besluitdatum, samenvatting)
	 * @param string $letter The letter the caller chose for the decision; empty drafts one
	 *                       from the case type's `documentDrafts.decision`.
	 *
	 * @return array<string, mixed> Created decision with ID and assessment summary
	 *
	 * @throws \RuntimeException If OpenRegister unavailable or case not found
	 * @throws \InvalidArgumentException If any document has not been assessed
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function assembleDecision(string $caseId, array $decisionData = [], string $letter = ''): array {
		// Guard: all documents must be assessed before a besluit can be created.
		$this->assertAllDocumentsAssessed(caseId: $caseId);

		// Guard: every required page of every in-scope document has been seen (REQ-WRT-005).
		$this->pagesSeen?->assertAllSeen(caseId: $caseId);

		// The case's title and its assessments, tallied by classification.
		$composed = $this->localRecord->compose(caseId: $caseId);
		$subject  = new WooDecisionSubject(
			caseId: $caseId,
			register: $composed['register'],
			record: $composed['record'],
			label: self::publicationSummary(caseTitle: (string)$composed['record']['caseTitle'])
		);

		$userId = $this->resolveDecidedBy();

		// The decision itself is raised in decidiq; nothing is recorded locally when that fails.
		$decisionRef = $this->raiser->raise(subject: $subject, actorId: $userId);

		$decisionId = $this->localRecord->record(subject: $subject, userId: $userId, decisionData: $decisionData, decisionRef: $decisionRef);

		return $this->assembledResult(subject: $subject, decisionRef: $decisionRef, decisionId: $decisionId, letter: $letter);
	}//end assembleDecision()

	/**
	 * The assembled decision as answered to the caller, with the drafts it asked for.
	 *
	 * @param WooDecisionSubject $subject     The case and the composed decision record
	 * @param string             $decisionRef The decidiq decision reference
	 * @param string             $decisionId  The saved decision's uuid
	 * @param string             $letter      The letter the caller chose; empty drafts one
	 *
	 * @return array<string, mixed> Created decision with ID and assessment summary
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function assembledResult(WooDecisionSubject $subject, string $decisionRef, string $decisionId, string $letter): array {
		$caseId = $subject->caseId;
		$record = $subject->record;
		$draft = null;
		if ($letter === '') {
			$draft = $this->drafts?->draft(caseId: $caseId, purpose: self::DRAFT_PURPOSE);
		}

		return [
			'decisionId' => $decisionId,
			'decisionRef' => $decisionRef,
			'caseId' => $caseId,
			'summary' => $record['summary'],
			'weigeringsgronden' => $record['weigeringsgronden'],
			'assessmentCount' => $record['assessmentCount'],
			'draft' => $draft,
			'inventoryDraft' => $this->drafts?->draft(caseId: $caseId, purpose: self::INVENTORY_PURPOSE),
		];
	}//end assembledResult()

	/**
	 * The line a resident reads under the title of the published decision.
	 *
	 * OpenCatalogi shows a publication's `summary` under its title on the
	 * public site and in search results, and the publication takes it from the
	 * decision's `description` (OutcomePublisher::buildPayload()). It
	 * used to read "WOO besluit voor zaak <uuid>", so a resident read a code.
	 * It names the request by the case's title instead, and never prints an id.
	 *
	 * @param string $caseTitle The case's title, or '' when it has none.
	 *
	 * @return string The summary.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WooPublicationSummary::forTitle() is where the wording lives.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public static function publicationSummary(string $caseTitle): string {
		return WooPublicationSummary::forTitle(caseTitle: $caseTitle);
	}//end publicationSummary()

	/**
	 * The in-scope documents of a case whose required pages are not all seen, with those pages.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, list<int>> The unseen pages by document; empty without the review.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenPages(string $caseId): array {
		return ($this->pagesSeen?->unseenInCase(caseId: $caseId) ?? []);
	}//end unseenPages()

	/**
	 * Guard that every document of a case carries an assessment.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If any document has not been assessed
	 */
	private function assertAllDocumentsAssessed(string $caseId): void {
		$outstanding = $this->assessmentService->getOutstanding(caseId: $caseId);
		if ($outstanding['count'] > 0) {
			throw new InvalidArgumentException(
				'Cannot create besluit: ' . $outstanding['count'] . ' document(s) still need assessment. '
				. 'Document IDs: ' . implode(', ', $outstanding['documents'])
			);
		}
	}//end assertAllDocumentsAssessed()
}//end class
