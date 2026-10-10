<?php

/**
 * Dossiq repair step: give every assessed Woo document its relevance review.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Marks every document that already carries a Woo verdict as in scope.
 *
 * Since woo-review-triage an unmarked document holds up the decision, and a
 * document without a review reads as unmarked. Every document assessed before
 * this change would then block its case although a reviewer judged it: a
 * verdict on a document is a reviewer's judgement that it is about the
 * request. So each assessed document gets one `in-scope` review, marked by
 * whoever assessed it, when it has none. A document without a verdict is left
 * alone: it is unmarked, which is what nobody having looked at it means.
 *
 * Idempotent: a document that has a review keeps it, whatever it says. It runs
 * without a user, so it sets no grant (REQ-WRT-006): the reviews wait for the
 * administrator reconcile route.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 */
class CreateWooDocumentReviews implements IRepairStep {

	use SearchesObjects;

	/**
	 * Rows per page.
	 */
	private const PAGE = 200;

	/**
	 * The most pages one run reads.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param WooDocumentReviews $reviews The review store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooDocumentReviews $reviews,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function getName(): string {
		return 'Mark every assessed Woo document as in scope';
	}//end getName()

	/**
	 * Create the missing reviews.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $assessmentSchema === '' || $this->reviews->isAvailable() === false) {
			$output->info('Woo document reviews: OpenRegister or a Woo schema is not configured yet, skipped until the next upgrade.');
			return;
		}

		$tally = (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->createMissing(objectService: $objectService, register: $register, schema: $assessmentSchema)
		);
		$output->info('Woo document reviews: '.$tally['created'].' created, '.$tally['failed'].' failed.');
	}//end run()

	/**
	 * Page through the assessments and create a review where a document has none.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register The register.
	 * @param string $schema The assessment schema.
	 *
	 * @return array{created: int, failed: int} The tally.
	 */
	private function createMissing(object $objectService, string $register, string $schema): array {
		$tally = ['created' => 0, 'failed' => 0];
		$known = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);
			foreach ($rows as $assessment) {
				$this->createOne(assessment: $assessment, known: $known, tally: $tally);
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $tally;
	}//end createMissing()

	/**
	 * Create the review of one assessed document, when its case has none for it.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param array<string, array<string, array<string, mixed>>> $known The reviews read so far, by case.
	 * @param array{created: int, failed: int} $tally The tally.
	 *
	 * @return void
	 */
	private function createOne(array $assessment, array &$known, array &$tally): void {
		$caseId = (string)($assessment['caseRef'] ?? '');
		$documentRef = (string)($assessment['documentRef'] ?? '');
		if ($caseId === '' || $documentRef === '') {
			return;
		}

		$known[$caseId] ??= $this->reviews->forCase(caseId: $caseId);
		if (isset($known[$caseId][$documentRef]) === true) {
			return;
		}

		$review = [
			'case' => $caseId,
			'documentRef' => $documentRef,
			'relevance' => WooDocumentReviews::IN_SCOPE,
			'relevanceSource' => 'reviewer',
			'markedBy' => (string)($assessment['assessedBy'] ?? ''),
			'markedAt' => (string)($assessment['assessedAt'] ?? ''),
		];
		try {
			$known[$caseId][$documentRef] = $this->reviews->save(review: array_filter($review, static fn (string $value): bool => $value !== ''));
			$tally['created']++;
		} catch (Throwable) {
			$tally['failed']++;
		}
	}//end createOne()
}//end class
