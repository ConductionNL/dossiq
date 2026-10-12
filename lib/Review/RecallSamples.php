<?php

/**
 * Dossiq recall samples: an elusion sample drawn from the null set, judged, and estimated.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\RecallEstimator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Draws a simple random sample from the documents a review set aside or has not
 * marked, records the judgements apart from the review marking, and estimates the
 * recall once every sampled document is judged.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */
class RecallSamples {

	use SearchesObjects;

	/**
	 * The app config key of the recall sample schema.
	 */
	public const SCHEMA_KEY = 'recall_sample_schema';

	/**
	 * The confidence of a bound when the case declared no stopping rule.
	 */
	public const DEFAULT_CONFIDENCE = 0.95;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param DocumentRelevance $reviews The relevance of each document.
	 * @param WooCaseDocuments $caseDocuments The documents on a case.
	 * @param ReviewBatches $batches The batch the sample is judged in.
	 * @param ReviewDepth $depth The seeded draw.
	 * @param StoppingRules $rules The case's stopping rule.
	 * @param RecallEstimator $estimator The estimate and its bound.
	 * @param StoppingRuleMet $met The met record.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRelevance $reviews,
		private readonly WooCaseDocuments $caseDocuments,
		private readonly ReviewBatches $batches,
		private readonly ReviewDepth $depth,
		private readonly StoppingRules $rules,
		private readonly RecallEstimator $estimator,
		private readonly StoppingRuleMet $met,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Draw a sample from the case's null set, store it and hand it to a reviewer as a sample batch.
	 *
	 * @param string $caseId The case UUID.
	 * @param int $size The sample size.
	 * @param string $assignee The reviewer of the sample.
	 * @param string $userId Who draws it.
	 * @param int|null $seed The seed, or null for a fresh one.
	 *
	 * @return array<string, mixed> The stored sample.
	 *
	 * @throws RefusedException When the size does not fit the null set, or the sample cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function draw(string $caseId, int $size, string $assignee, string $userId, ?int $seed = null): array {
		if ($assignee === '') {
			throw new RefusedException(
				rule: 'review-recall-assignee-unknown',
				sentence: 'Choose the reviewer of the sample.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$reviews = $this->reviews->forCase(caseId: $caseId);
		$nullSet = [];
		$found = 0;
		foreach ($this->caseDocuments->idsFor(caseId: $caseId) as $ref) {
			if ((string)(($reviews[$ref] ?? [])['relevance'] ?? '') === DocumentRelevance::IN_SCOPE) {
				$found++;
				continue;
			}

			$nullSet[] = $ref;
		}

		if ($size < 1 || $size > count($nullSet)) {
			throw new RefusedException(
				rule: 'review-recall-size',
				sentence: 'Choose a sample size between 1 and the number of documents set aside or not yet marked.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$seed = ($seed ?? random_int(1, PHP_INT_MAX));
		$documents = $this->depth->drawSample(items: $nullSet, size: $size, seed: $seed);
		$sample = $this->save(
			sample: [
				'case' => $caseId,
				'seed' => $seed,
				'drawnBy' => $userId,
				'drawnAt' => date(DATE_ATOM),
				'nullSetSize' => count($nullSet),
				'found' => $found,
				'documents' => $documents,
				'judgements' => [],
			]
		);

		$sampleId = self::idOf(row: $sample);
		$batch = $this->batches->createSampleBatch(
			caseId: $caseId,
			sampleId: $sampleId,
			assignee: $assignee,
			documents: $documents,
			userId: $userId
		);
		$sample['batch'] = self::idOf(row: $batch);

		return $this->save(sample: $sample);
	}//end draw()

	/**
	 * Judge one sampled document in or out of scope, in the sample only; estimate when it was the last.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $sampleId The recall sample.
	 * @param string $documentRef The sampled document.
	 * @param bool $inScope Whether the document is about the request after all.
	 * @param string $userId Who judges.
	 *
	 * @return array<string, mixed> The sample, with its progress and, once complete, its estimate.
	 *
	 * @throws RefusedException When the sample or document is unknown, the sample is closed, or it cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function judge(string $caseId, string $sampleId, string $documentRef, bool $inScope, string $userId): array {
		$sample = $this->find(caseId: $caseId, sampleId: $sampleId);
		$documents = array_map('strval', (array)($sample['documents'] ?? []));
		if (in_array($documentRef, $documents, true) === false) {
			throw new RefusedException(
				rule: 'review-recall-document-unknown',
				sentence: 'Only a sampled document can be judged in the sample.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (isset($sample['estimatedAt']) === true && $sample['estimatedAt'] !== '') {
			throw new RefusedException(
				rule: 'review-recall-sample-closed',
				sentence: 'Every document of this sample is judged and its estimate is recorded.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$judgements = (array)($sample['judgements'] ?? []);
		$judgements[$documentRef] = DocumentRelevance::OUT_OF_SCOPE;
		if ($inScope === true) {
			$judgements[$documentRef] = DocumentRelevance::IN_SCOPE;
		}

		$sample['judgements'] = $judgements;
		if (count(array_intersect($documents, array_map('strval', array_keys($judgements)))) === count($documents)) {
			$sample = $this->complete(caseId: $caseId, sample: $sample, userId: $userId);
		}

		return $this->withProgress(sample: $this->save(sample: $sample));
	}//end judge()

	/**
	 * The case's samples, newest first, each with its progress.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The samples.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function forCase(string $caseId): array {
		$samples = array_map(fn (array $sample): array => $this->withProgress(sample: $sample), $this->rows(caseId: $caseId));
		usort(
			$samples,
			static fn (array $left, array $right): int => strcmp((string)($right['drawnAt'] ?? ''), (string)($left['drawnAt'] ?? ''))
		);

		return $samples;
	}//end forCase()

	/**
	 * A sample with `progress` {judged, total}; an incomplete sample carries no estimate.
	 *
	 * @param array<string, mixed> $sample The sample.
	 *
	 * @return array<string, mixed> The sample with its progress.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function withProgress(array $sample): array {
		$documents = array_map('strval', (array)($sample['documents'] ?? []));
		$judged = count(array_intersect($documents, array_map('strval', array_keys((array)($sample['judgements'] ?? [])))));
		$sample['progress'] = ['judged' => $judged, 'total' => count($documents)];
		if ($judged < count($documents)) {
			foreach (['k', 'elusionRate', 'elusionUpper', 'recallEstimate', 'recallLowerBound', 'estimatedAt'] as $estimateField) {
				unset($sample[$estimateField]);
			}
		}

		return $sample;
	}//end withProgress()

	/**
	 * Compute and store the estimate, and record the stopping rule as met when it is.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $sample The fully judged sample.
	 * @param string $userId Who judged last.
	 *
	 * @return array<string, mixed> The sample with its estimate.
	 *
	 * @throws RefusedException When the met record cannot be written.
	 */
	private function complete(string $caseId, array $sample, string $userId): array {
		$rule = $this->rules->forCase(caseId: $caseId);
		$confidence = self::DEFAULT_CONFIDENCE;
		if ($rule !== null) {
			$confidence = (float)$rule['confidence'];
		}

		$inScope = array_filter((array)$sample['judgements'], static fn (mixed $judgement): bool => $judgement === DocumentRelevance::IN_SCOPE);
		$k = count($inScope);
		$estimate = $this->estimator->estimate(
			found: (int)($sample['found'] ?? 0),
			nullSetSize: (int)($sample['nullSetSize'] ?? 0),
			k: $k,
			n: count((array)$sample['documents']),
			confidence: $confidence,
		);

		$sample['k'] = $k;
		$sample['confidence'] = $confidence;
		$sample['elusionRate'] = round((float)$estimate['elusion'], 4);
		$sample['elusionUpper'] = round((float)$estimate['elusionUpper'], 4);
		$sample['recallEstimate'] = round((float)$estimate['estimate'], 4);
		$sample['recallLowerBound'] = round((float)$estimate['lowerBound'], 4);
		$sample['estimatedAt'] = date(DATE_ATOM);

		$metAt = $this->met->record(caseId: $caseId, rule: $rule, sample: $sample, userId: $userId);
		if ($metAt !== null) {
			$sample['stoppingRuleMetAt'] = $metAt;
		}

		return $sample;
	}//end complete()

	/**
	 * The sample of this case, or a refusal.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $sampleId The sample.
	 *
	 * @return array<string, mixed> The sample.
	 *
	 * @throws RefusedException When the sample is not one of this case.
	 */
	private function find(string $caseId, string $sampleId): array {
		foreach ($this->rows(caseId: $caseId) as $sample) {
			if (self::idOf(row: $sample) === $sampleId) {
				return $sample;
			}
		}

		throw new RefusedException(
			rule: 'review-recall-sample-unknown',
			sentence: 'This sample is not one of this case.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end find()

	/**
	 * The case's stored samples.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The samples.
	 */
	private function rows(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($objectService === null || $schema === '' || $caseId === '') {
			return [];
		}

		return array_values(
			$this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => 100],
			)
		);
	}//end rows()

	/**
	 * The id of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()

	/**
	 * Store the sample.
	 *
	 * @param array<string, mixed> $sample The sample.
	 *
	 * @return array<string, mixed> The stored sample.
	 *
	 * @throws RefusedException When it cannot be stored.
	 */
	private function save(array $sample): array {
		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		$uuid = self::idOf(row: $sample);
		if ($uuid === '') {
			$uuid = null;
		}

		unset($sample['@self'], $sample['id'], $sample['uuid'], $sample['progress']);
		if ($objectService === null || $schema === '') {
			throw $this->unstored(previous: null);
		}

		try {
			$saved = $objectService->saveObject(
				object: $sample,
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: a Recall sample could not be stored', ['exception' => $e->getMessage()]);
			throw $this->unstored(previous: $e);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * The refusal when the sample cannot be stored.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unstored(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'review-recall-sample-unavailable',
			sentence: 'The Recall sample cannot be stored, so nothing was drawn or judged.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unstored()
}//end class
