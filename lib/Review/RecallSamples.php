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
use OCA\Dossiq\Kernel\Contracts\RecallSamplesInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Draws a simple random sample from the documents a review set aside or has not
 * marked, records the judgements apart from the review marking, and estimates the
 * recall once every sampled document is judged.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */
class RecallSamples implements RecallSamplesInterface {

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
	 * @param SettingsServiceInterface $settingsService The settings and OpenRegister access.
	 * @param DocumentRelevance $reviews The relevance of each document.
	 * @param WooCaseDocumentsInterface $caseDocuments The documents on a case.
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
		private readonly SettingsServiceInterface $settingsService,
		private readonly DocumentRelevance $reviews,
		private readonly WooCaseDocumentsInterface $caseDocuments,
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
		$this->assertAssigneeChosen(assignee: $assignee);
		[$nullSet, $found] = $this->nullSetOf(caseId: $caseId);
		$this->assertSampleSizeFits(size: $size, nullSetSize: count($nullSet));

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
	 * Refuse a sample without a reviewer, or with one not admitted to review.
	 *
	 * @param string $assignee The reviewer of the sample.
	 *
	 * @return void
	 *
	 * @throws RefusedException When no reviewer is chosen or the reviewer is not admitted.
	 */
	private function assertAssigneeChosen(string $assignee): void {
		if ($assignee === '') {
			throw new RefusedException(
				rule: 'review-recall-assignee-unknown',
				sentence: 'Choose the reviewer of the sample.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$this->batches->assertReviewerAdmitted(assignee: $assignee);
	}//end assertAssigneeChosen()

	/**
	 * The case's null set (documents not marked in scope) and the count found in scope.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{0: list<mixed>, 1: int} The null set and the in-scope count.
	 */
	private function nullSetOf(string $caseId): array {
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

		return [$nullSet, $found];
	}//end nullSetOf()

	/**
	 * Refuse a sample size that does not fit the null set.
	 *
	 * @param int $size        The sample size.
	 * @param int $nullSetSize The number of documents in the null set.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the size is below 1 or above the null set.
	 */
	private function assertSampleSizeFits(int $size, int $nullSetSize): void {
		if ($size < 1 || $size > $nullSetSize) {
			throw new RefusedException(
				rule: 'review-recall-size',
				sentence: 'Choose a sample size between 1 and the number of documents set aside or not yet marked.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertSampleSizeFits()

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
		$confidence = $this->confidenceOf(rule: $rule);

		$sample = $this->withEstimate(sample: $sample, confidence: $confidence);

		$metAt = $this->met->record(caseId: $caseId, rule: $rule, sample: $sample, userId: $userId);
		if ($metAt !== null) {
			$sample['stoppingRuleMetAt'] = $metAt;
		}

		return $sample;
	}//end complete()

	/**
	 * The confidence the stopping rule asks for, or the default without one.
	 *
	 * @param array<string, mixed>|null $rule The case's stopping rule.
	 *
	 * @return float The confidence.
	 */
	private function confidenceOf(?array $rule): float {
		$confidence = self::DEFAULT_CONFIDENCE;
		if ($rule !== null) {
			$confidence = (float)$rule['confidence'];
		}

		return $confidence;
	}//end confidenceOf()

	/**
	 * The sample with its recall estimate.
	 *
	 * @param array<string, mixed> $sample     The fully judged sample.
	 * @param float                $confidence The confidence.
	 *
	 * @return array<string, mixed> The sample with its estimate.
	 */
	private function withEstimate(array $sample, float $confidence): array {
		$inScope = array_filter((array)$sample['judgements'], static fn (mixed $judgement): bool => $judgement === DocumentRelevance::IN_SCOPE);
		$k = count($inScope);
		$estimate = $this->estimateOf(sample: $sample, k: $k, confidence: $confidence);

		$sample['k'] = $k;
		$sample['confidence'] = $confidence;
		$sample['elusionRate'] = round((float)$estimate['elusion'], 4);
		$sample['elusionUpper'] = round((float)$estimate['elusionUpper'], 4);
		$sample['recallEstimate'] = round((float)$estimate['estimate'], 4);
		$sample['recallLowerBound'] = round((float)$estimate['lowerBound'], 4);
		$sample['estimatedAt'] = date(DATE_ATOM);

		return $sample;
	}//end withEstimate()

	/**
	 * The estimator's answer for a judged sample.
	 *
	 * @param array<string, mixed> $sample     The fully judged sample.
	 * @param int                  $k          How many sampled documents were in scope.
	 * @param float                $confidence The confidence.
	 *
	 * @return array<string, mixed> The estimate.
	 */
	private function estimateOf(array $sample, int $k, float $confidence): array {
		return $this->estimator->estimate(
			found: (int)($sample['found'] ?? 0),
			nullSetSize: (int)($sample['nullSetSize'] ?? 0),
			k: $k,
			n: count((array)$sample['documents']),
			confidence: $confidence,
		);
	}//end estimateOf()

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

		$saved = $this->storeSample(objectService: $objectService, sample: $sample, schema: $schema, uuid: $uuid);

		return $this->savedRow(saved: $saved);
	}//end save()

	/**
	 * Write the sample, refusing when the write fails.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $sample        The sample, without its metadata.
	 * @param string               $schema        The sample schema.
	 * @param string|null          $uuid          The sample's uuid, null for a new one.
	 *
	 * @return mixed What the ObjectService answered.
	 *
	 * @throws RefusedException When it cannot be stored.
	 */
	private function storeSample($objectService, array $sample, string $schema, ?string $uuid): mixed {
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

		return $saved;
	}//end storeSample()

	/**
	 * The stored sample as an array.
	 *
	 * @param mixed $saved What the ObjectService answered.
	 *
	 * @return array<string, mixed> The stored sample.
	 */
	private function savedRow(mixed $saved): array {
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end savedRow()

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
