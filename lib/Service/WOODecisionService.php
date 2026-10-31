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
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DecisionDocument\DecisionRecordComposer;
use OCA\Dossiq\Service\DecisionDocument\PublicationReadiness;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for assembling the formal WOO besluit.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */
class WOODecisionService {

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
	public const DECISION_TYPE_ID = '3c0f5a00-0000-4000-a000-00000000d001';

	/**
	 * The `documentDrafts` purpose the decision document is drafted under.
	 */
	public const DRAFT_PURPOSE = 'decision';

	/**
	 * The `documentDrafts` purpose the inventory is drafted under.
	 */
	public const INVENTORY_PURPOSE = 'inventory';


	/**
	 * Composes the case's title and the assessment tally for the decision record.
	 *
	 * @var DecisionRecordComposer
	 */
	private readonly DecisionRecordComposer $composer;

	/**
	 * Marks the case ready to publish once the decision is assembled.
	 *
	 * @var PublicationReadiness
	 */
	private readonly PublicationReadiness $readiness;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param WOODocumentAssessmentService $assessmentService Document assessment service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param CaseDocumentDrafts|null $drafts Drafts the decision document from the case type's
	 *        `documentDrafts.decision` template through filinq (case-document-drafts).
	 * @param PagesSeen|null $pagesSeen The pages seen per document; the decision waits for the required ones.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?CaseDocumentDrafts $drafts = null,
		private readonly ?PagesSeen $pagesSeen = null,
	) {
		$this->composer  = new DecisionRecordComposer(settingsService: $settingsService, logger: $logger);
		$this->readiness = new PublicationReadiness(settingsService: $settingsService, logger: $logger);
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
	 * @spec openspec/changes/woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function assembleDecision(string $caseId, array $decisionData = [], string $letter = ''): array {
		// Guard: all documents must be assessed before a besluit can be created.
		$this->assertAllDocumentsAssessed(caseId: $caseId);

		// Guard: every required page of every in-scope document has been seen (REQ-WRT-005).
		$this->pagesSeen?->assertAllSeen(caseId: $caseId);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		if (empty($register) === true || empty($decisionSchema) === true) {
			throw new RuntimeException('Decision schema not configured');
		}

		// The case's title and its assessments, tallied by classification.
		$record = $this->composer->compose(objectService: $objectService, register: $register, caseId: $caseId);
		$summary = $record['summary'];
		$weigeringsgronden = $record['weigeringsgronden'];

		$userId = $this->resolveDecidedBy();

		$besluitData = array_merge(
			[
				'case' => $caseId,
				'decisionType' => self::DECISION_TYPE_ID,
				'decisionDate' => date('Y-m-d'),
				'description' => self::publicationSummary(caseTitle: $record['caseTitle']),
				'wooSummary' => $summary,
				'weigeringsgronden' => $weigeringsgronden,
				'groundsListVersion' => WooRefusalGrounds::LIST_VERSION,
				'assessmentCount' => $record['assessmentCount'],
				'decidedBy' => $userId,
			],
			$decisionData,
		);

		$decision = $objectService->saveObject(object: $besluitData, register: $register, schema: $decisionSchema);

		$this->readiness->markCaseReady(
			objectService: $objectService,
			register: $register,
			caseId: $caseId,
			decisionId: (string)$decision->getUuid(),
		);

		$this->logger->info(
			'WOO besluit assembled for case ' . $caseId . ': decision ' . $decision->getUuid(),
			['app' => Application::APP_ID],
		);

		$draft = null;
		if ($letter === '') {
			$draft = $this->drafts?->draft(caseId: $caseId, purpose: self::DRAFT_PURPOSE);
		}

		return [
			'decisionId' => $decision->getUuid(),
			'caseId' => $caseId,
			'summary' => $summary,
			'weigeringsgronden' => $weigeringsgronden,
			'assessmentCount' => $record['assessmentCount'],
			'draft' => $draft,
			'inventoryDraft' => $this->drafts?->draft(caseId: $caseId, purpose: self::INVENTORY_PURPOSE),
		];
	}//end assembleDecision()

	/**
	 * The line a resident reads under the title of the published decision.
	 *
	 * OpenCatalogi shows a publication's `summary` under its title on the
	 * public site and in search results, and the publication takes it from the
	 * decision's `description` (WooPublicationService::buildPayload()). It
	 * used to read "WOO besluit voor zaak <uuid>", so a resident read a code.
	 * It names the request by the case's title instead, and never prints an id.
	 *
	 * @param string $caseTitle The case's title, or '' when it has none.
	 *
	 * @return string The summary.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public static function publicationSummary(string $caseTitle): string {
		$title = trim($caseTitle);
		if ($title === '') {
			return 'Besluit op een Woo-verzoek';
		}

		return 'Besluit op het Woo-verzoek "' . $title . '"';
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

	/**
	 * Resolve the user id credited with the besluit.
	 *
	 * @return string The current user id, or `system` when there is no session user
	 */
	private function resolveDecidedBy(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveDecidedBy()
}//end class
