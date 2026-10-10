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
use OCA\Dossiq\Service\Support\SearchesObjects;
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

	use SearchesObjects;

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param WOODocumentAssessmentService $assessmentService Document assessment service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param PagesSeen|null $pagesSeen The pages seen per document; the decision waits for the required ones.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?PagesSeen $pagesSeen = null,
	) {
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
	 *
	 * @return array<string, mixed> Created decision with ID and assessment summary
	 *
	 * @throws \RuntimeException If OpenRegister unavailable or case not found
	 * @throws \InvalidArgumentException If any document has not been assessed
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function assembleDecision(string $caseId, array $decisionData = []): array {
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
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

		if (empty($register) === true || empty($decisionSchema) === true) {
			throw new RuntimeException('Decision schema not configured');
		}

		// Collect all assessments for the case.
		$assessments = $this->collectAssessments(
			objectService: $objectService,
			register: $register,
			assessmentSchema: $assessmentSchema,
			caseId: $caseId,
		);

		// Summarise assessments by classification.
		$summarised = $this->summariseAssessments(assessments: $assessments);
		$summary = $summarised['summary'];
		$weigeringsgronden = $summarised['weigeringsgronden'];

		$userId = $this->resolveDecidedBy();

		$besluitData = array_merge(
			[
				'case' => $caseId,
				'decisionType' => self::DECISION_TYPE_ID,
				'decisionDate' => date('Y-m-d'),
				'description' => self::publicationSummary(
					caseTitle: $this->readCaseTitle(objectService: $objectService, register: $register, caseId: $caseId),
				),
				'wooSummary' => $summary,
				'weigeringsgronden' => $weigeringsgronden,
				'groundsListVersion' => WooRefusalGrounds::LIST_VERSION,
				'assessmentCount' => count($assessments),
				'decidedBy' => $userId,
			],
			$decisionData,
		);

		$decision = $objectService->saveObject(object: $besluitData, register: $register, schema: $decisionSchema);

		$this->markCaseReady(objectService: $objectService, register: $register, caseId: $caseId);

		$this->logger->info(
			'WOO besluit assembled for case ' . $caseId . ': decision ' . $decision->getUuid(),
			['app' => Application::APP_ID],
		);

		return [
			'decisionId' => $decision->getUuid(),
			'caseId' => $caseId,
			'summary' => $summary,
			'weigeringsgronden' => $weigeringsgronden,
			'assessmentCount' => count($assessments),
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
	 * The title of the case, or '' when it cannot be read.
	 *
	 * A case that cannot be read costs the summary its title, not the decision.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register.
	 * @param string $caseId The case UUID.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function readCaseTitle(object $objectService, string $register, string $caseId): string {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($caseSchema === '') {
			return '';
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				id: $caseId,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WOODecisionService: the case title could not be read for the decision summary',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
			return '';
		}

		$title = $case['title'] ?? '';
		if (is_string($title) === false) {
			return '';
		}

		return $title;
	}//end readCaseTitle()

	/**
	 * The case can now be published: it reads `wooPublicationStatus: ready`
	 * (woo-publish-decision-from-the-case design D-2), which is what shows the
	 * Publish (Woo) header action. A decision that was published before keeps
	 * the case's `published` state; re-assembling does not unpublish.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register.
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	private function markCaseReady(object $objectService, string $register, string $caseId): void {
		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
			);
			if ($case === null || ($case['wooPublicationStatus'] ?? '') === 'published') {
				return;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
				changes: ['wooPublicationStatus' => 'ready'],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WOODecisionService: the case could not show that its decision is ready to publish',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}
	}//end markCaseReady()

	/**
	 * The in-scope documents of a case whose required pages are not all seen, with those pages.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, list<int>> The unseen pages by document; empty without the review.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
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
	 * Fetch all assessment objects belonging to a case.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed $register Configured register identifier
	 * @param mixed $assessmentSchema Configured assessment schema identifier
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> Assessment rows, empty when the schema is not configured
	 */
	private function collectAssessments(object $objectService, mixed $register, mixed $assessmentSchema, string $caseId): array {
		if (empty($assessmentSchema) === true) {
			return [];
		}

		// The is_array() guard the inline version carried here is dead code:
		// searchObjectsAsArrays() is declared `: array`. Dropped rather than
		// inverted, because phpstan rejects it in either direction.
		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, '_limit' => 500],
		);
	}//end collectAssessments()

	/**
	 * Tally assessments by classification and collect the distinct weigeringsgronden.
	 *
	 * @param array<int, array<string, mixed>> $assessments Assessment rows
	 *
	 * @return array{summary: array<string, int>, weigeringsgronden: array<int, mixed>} Counts per classification plus distinct grounds
	 */
	private function summariseAssessments(array $assessments): array {
		$summary = [
			'openbaar' => 0,
			'deels_openbaar' => 0,
			'niet_openbaar' => 0,
		];

		$weigeringsgronden = [];
		foreach ($assessments as $assessment) {
			$classification = $assessment['classification'] ?? null;
			if ($classification !== null && isset($summary[$classification]) === true) {
				$summary[$classification]++;
			}

			foreach (($assessment['weigeringsgronden'] ?? []) as $code) {
				if (in_array($code, $weigeringsgronden, true) === false) {
					$weigeringsgronden[] = $code;
				}
			}
		}

		return [
			'summary' => $summary,
			'weigeringsgronden' => $weigeringsgronden,
		];
	}//end summariseAssessments()

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
