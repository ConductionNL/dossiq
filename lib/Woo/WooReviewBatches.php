<?php

/**
 * Dossiq Woo review batches: a named part of a Woo case's documents, assigned to one reviewer.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and lists the `wooReviewBatch` objects of a Woo case.
 *
 * A batch names its documents, writes itself on each document's review, and
 * hands its reviewer a task through the engine task seam, so the reviewer
 * finds the work in their own task list before any verdict is made. A
 * document is in at most one open batch.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
 */
class WooReviewBatches {

	use SearchesObjects;

	/**
	 * The app config key of the batch schema.
	 */
	public const SCHEMA_KEY = 'woo_review_batch_schema';

	/**
	 * The app config key of the Woo request configuration schema, which carries `reviewDepth`.
	 */
	public const CONFIGURATION_KEY = 'woo_request_configuration_schema';

	/**
	 * The kind dossiq stamps on the reviewer's task, read back from `metadata.dossiq.kind`.
	 */
	public const TASK_KIND = 'woo-review-batch';

	/**
	 * The filter fields a batch can take its documents by, as the review carries them.
	 *
	 * Custodian and source system join once the collected documents carry
	 * them (woo-request-corpus-collection); a filter on a field no review
	 * holds would answer an empty batch, so it is refused instead.
	 */
	public const FILTER_FIELDS = ['rule'];

	/**
	 * The sentence of a batch that cannot be stored.
	 */
	private const UNSTORED = 'The Woo review batch cannot be stored, so nothing was assigned.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param WooDocumentReviews $reviews The reviews the batch is written on.
	 * @param WooCaseDocuments $caseDocuments Where a case's documents are.
	 * @param WooReviewDepth $depth The review depth per document type.
	 * @param EngineTaskGateway $tasks The task seam that hands the reviewer the batch.
	 * @param IUserManager $userManager Whether the reviewer exists.
	 * @param IL10N $l10n The translations, for the task's title.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooDocumentReviews $reviews,
		private readonly WooCaseDocuments $caseDocuments,
		private readonly WooReviewDepth $depth,
		private readonly EngineTaskGateway $tasks,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The documents a batch takes: the listed ones, or those whose review matches the filter.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param list<string> $documents The listed documents; used when no filter is given.
	 * @param array<string, string> $filter The filter, by field of the review.
	 *
	 * @return list<string> The documents.
	 *
	 * @throws RefusedException When the filter names a field the reviews do not carry.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function select(string $caseId, array $documents, array $filter): array {
		if ($filter === []) {
			return array_values(array_unique(array_map('strval', $documents)));
		}

		$unknown = array_diff(array_keys($filter), self::FILTER_FIELDS);
		if ($unknown !== []) {
			throw new RefusedException(
				rule: 'woo-batch-filter-unknown',
				sentence: 'A batch can be taken by the rule that marked its documents, or by a list of documents.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$selected = [];
		foreach ($this->reviews->forCase(caseId: $caseId) as $ref => $review) {
			if ($this->matches(review: $review, filter: $filter) === true) {
				$selected[] = (string)$ref;
			}
		}

		return $selected;
	}//end select()

	/**
	 * The documents among these that are already in an open batch of the case, with that batch's name.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param list<string> $documents The documents.
	 *
	 * @return array<string, string> The batch name by document.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function taken(string $caseId, array $documents): array {
		$open = [];
		foreach ($this->rows(caseId: $caseId) as $batch) {
			if (($batch['status'] ?? 'open') === 'open' && isset($batch['filter']['recallSample']) === false) {
				foreach ((array)($batch['documents'] ?? []) as $ref) {
					$open[(string)$ref] = (string)($batch['name'] ?? '');
				}
			}
		}

		$taken = [];
		foreach ($documents as $ref) {
			if (isset($open[$ref]) === true) {
				$taken[$ref] = $open[$ref];
			}
		}

		return $taken;
	}//end taken()

	/**
	 * Create a batch: write it, write it on each review, and give its reviewer a task.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $name The batch's name.
	 * @param string $assignee The reviewer, a Nextcloud user.
	 * @param list<string> $documents The documents, as `select()` answered them.
	 * @param string $userId Who creates the batch.
	 * @param array<string, string> $filter The filter the documents were taken by, recorded on the batch.
	 *
	 * @return array{batch: array<string, mixed>, taskCreated: bool, progress: array{assessed: int, total: int}} The batch.
	 *
	 * @throws RefusedException When the batch is incomplete, a document is not on the case or in another open batch, or nothing can be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function create(string $caseId, string $name, string $assignee, array $documents, string $userId, array $filter = []): array {
		$name = trim($name);
		$this->validate(caseId: $caseId, name: $name, assignee: $assignee, documents: $documents);

		$batch = $this->save(
			batch: [
				'case' => $caseId,
				'name' => $name,
				'documents' => $documents,
				'filter' => $filter,
				'assignee' => $assignee,
				'status' => 'open',
			]
		);
		$batchId = (string)($batch['id'] ?? ($batch['uuid'] ?? ''));

		$reviews = $this->reviews->forCase(caseId: $caseId);
		$reviewDepth = $this->reviewDepth(caseId: $caseId);
		foreach ($documents as $ref) {
			$review = ($reviews[$ref] ?? ['case' => $caseId, 'documentRef' => $ref, 'relevance' => WooDocumentReviews::UNMARKED]);
			$review['batch'] = $batchId;
			$review['depth'] = $this->depth->depthFor(
				reviewDepth: $reviewDepth,
				type: $this->depth->typeOf(document: ($this->caseDocuments->meta(documentId: $ref) ?? [])),
			);
			$pageCount = null;
			if (isset($review['pageCount']) === true && is_numeric($review['pageCount']) === true) {
				$pageCount = (int)$review['pageCount'];
			}

			$review['pagesRequired'] = $this->depth->pagesRequired(depth: $review['depth'], pageCount: $pageCount);
			$this->reviews->save(review: $review);
		}

		$taskId = $this->handTask(caseId: $caseId, batchId: $batchId, name: $name, assignee: $assignee, count: count($documents), userId: $userId);
		if ($taskId !== '') {
			$batch['task'] = $taskId;
			$batch = $this->save(batch: $batch);
		}

		return [
			'batch' => $batch,
			'taskCreated' => $taskId !== '',
			'progress' => ['assessed' => 0, 'total' => count($documents)],
		];
	}//end create()

	/**
	 * Create the batch a recall sample is judged in, and give its reviewer a task.
	 *
	 * A sample batch takes documents that may sit in another batch or carry
	 * a marking: it judges them apart from the review, so it writes nothing
	 * on the reviews and holds no document against the other batches.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $sampleId The recall sample.
	 * @param string $assignee The reviewer.
	 * @param list<string> $documents The sampled documents.
	 * @param string $userId Who drew the sample.
	 *
	 * @return array<string, mixed> The batch.
	 *
	 * @throws RefusedException When the batch cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function createSampleBatch(string $caseId, string $sampleId, string $assignee, array $documents, string $userId): array {
		$name = $this->l10n->t('Recall sample of %d documents', [count($documents)]);
		$batch = $this->save(
			batch: [
				'case' => $caseId,
				'name' => $name,
				'documents' => $documents,
				'filter' => ['recallSample' => $sampleId],
				'assignee' => $assignee,
				'status' => 'open',
			]
		);
		$batchId = (string)($batch['id'] ?? ($batch['uuid'] ?? ''));
		$taskId = $this->handTask(caseId: $caseId, batchId: $batchId, name: $name, assignee: $assignee, count: count($documents), userId: $userId);
		if ($taskId !== '') {
			$batch['task'] = $taskId;
			$batch = $this->save(batch: $batch);
		}

		return $batch;
	}//end createSampleBatch()

	/**
	 * The case's batches, each with its progress: assessed of total.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param array<string, bool> $assessed The assessed documents as keys.
	 *
	 * @return list<array<string, mixed>> The batches.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-batches-are-assigned-to-named-reviewers-before-any-verdict-req-wrt-003
	 */
	public function forCase(string $caseId, array $assessed): array {
		$listed = [];
		foreach ($this->rows(caseId: $caseId) as $batch) {
			$documents = array_map('strval', (array)($batch['documents'] ?? []));
			$done = count(array_filter($documents, static fn (string $ref): bool => isset($assessed[$ref])));
			$batch['progress'] = ['assessed' => $done, 'total' => count($documents)];
			$listed[] = $batch;
		}

		return $listed;
	}//end forCase()

	/**
	 * The case's `reviewDepth`, from its Woo request configuration; empty means every page for every type.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array<string, mixed> The depth by document type.
	 */
	private function reviewDepth(string $caseId): array {
		$schema = $this->settingsService->getConfigValue(self::CONFIGURATION_KEY);
		if ($schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $this->settingsService->getObjectService(),
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => 1],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the Woo request configuration could not be read, so every page is required',
				['case' => $caseId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$first = reset($rows);
		$depth = [];
		if (is_array($first) === true) {
			$depth = ($first['reviewDepth'] ?? []);
		}

		if (is_array($depth) === false) {
			return [];
		}

		return $depth;
	}//end reviewDepth()

	/**
	 * Hand the reviewer a task for the batch through the engine; '' when the engine did not take it.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $batchId The batch.
	 * @param string $name The batch's name.
	 * @param string $assignee The reviewer.
	 * @param int $count How many documents the batch holds.
	 * @param string $userId Who made the batch.
	 *
	 * @return string The engine task uuid, or ''.
	 */
	private function handTask(string $caseId, string $batchId, string $name, string $assignee, int $count, string $userId): string {
		$taskId = $this->tasks->mirrorImport(
			task: [
				'id' => self::TASK_KIND.':'.$batchId,
				'title' => $this->l10n->t('Review the Woo batch "%s"', [$name]),
				'description' => $this->l10n->t('%1$d documents to review in the batch "%2$s".', [$count, $name]),
				'status' => 'available',
				'assignee' => $assignee,
				'priority' => 'normal',
				'metadata' => ['dossiq' => ['kind' => self::TASK_KIND, 'batch' => $batchId]],
			],
			caseId: $caseId,
			actor: $userId,
		);
		if ($taskId === '') {
			$this->logger->warning(
				'Dossiq: a Woo review batch was created but the engine did not take its task',
				['app' => Application::APP_ID, 'case' => $caseId, 'batch' => $batchId]
			);
		}

		return $taskId;
	}//end handTask()

	/**
	 * Refuse an incomplete batch before anything is written.
	 *
	 * @param string $caseId The Woo case UUID.
	 * @param string $name The trimmed name.
	 * @param string $assignee The reviewer.
	 * @param list<string> $documents The documents.
	 *
	 * @return void
	 *
	 * @throws RefusedException When something is missing, unknown or taken.
	 */
	private function validate(string $caseId, string $name, string $assignee, array $documents): void {
		if ($this->isAvailable() === false) {
			throw $this->refusal(rule: 'woo-batch-unavailable', sentence: self::UNSTORED, status: RefusedException::STATUS_INDETERMINATE);
		}

		if ($name === '') {
			throw $this->refusal(rule: 'woo-batch-name-required', sentence: 'Give the batch a name.');
		}

		if ($assignee === '' || $this->userManager->userExists($assignee) === false) {
			throw $this->refusal(rule: 'woo-batch-assignee-unknown', sentence: 'Choose the reviewer of the batch.');
		}

		if ($documents === []) {
			throw $this->refusal(rule: 'woo-batch-empty', sentence: 'Put at least one document in the batch.');
		}

		if (array_diff($documents, $this->caseDocuments->idsFor(caseId: $caseId)) !== []) {
			throw $this->refusal(rule: 'woo-batch-document-unknown', sentence: 'A batch only holds documents of this case.');
		}

		if ($this->taken(caseId: $caseId, documents: $documents) !== []) {
			throw $this->refusal(
				rule: 'woo-batch-document-taken',
				sentence: 'A document is already in another open batch.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end validate()

	/**
	 * Whether a review matches every field of the filter.
	 *
	 * @param array<string, mixed> $review The review.
	 * @param array<string, string> $filter The filter.
	 *
	 * @return bool True on a match.
	 */
	private function matches(array $review, array $filter): bool {
		foreach ($filter as $field => $value) {
			if ((string)($review[$field] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * Whether the batch schema can be written.
	 *
	 * @return bool True when OpenRegister, the batch schema and the review schema are configured.
	 */
	private function isAvailable(): bool {
		return $this->reviews->isAvailable() === true
			&& $this->settingsService->getConfigValue(self::SCHEMA_KEY) !== '';
	}//end isAvailable()

	/**
	 * Every batch of a case, as stored.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return list<array<string, mixed>> The batches.
	 */
	private function rows(string $caseId): array {
		if ($this->isAvailable() === false || $caseId === '') {
			return [];
		}

		return array_values(
			$this->searchObjectsAsArrays(
				objectService: $this->settingsService->getObjectService(),
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
				filters: ['case' => $caseId, '_limit' => 500],
			)
		);
	}//end rows()

	/**
	 * Save a batch, creating or replacing it.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, mixed> The saved batch.
	 *
	 * @throws RefusedException When the batch cannot be written.
	 */
	private function save(array $batch): array {
		$uuid = ($batch['id'] ?? ($batch['uuid'] ?? null));
		if ($uuid !== null) {
			$uuid = (string)$uuid;
		}

		unset($batch['@self'], $batch['id'], $batch['uuid']);
		try {
			$saved = $this->settingsService->getObjectService()->saveObject(
				object: $batch,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a Woo review batch could not be written',
				['app' => Application::APP_ID, 'case' => ($batch['case'] ?? ''), 'exception' => $e->getMessage()]
			);
			throw $this->refusal(
				rule: 'woo-batch-unavailable',
				sentence: self::UNSTORED,
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * A refusal, 422 unless said otherwise.
	 *
	 * @param string $rule The rule.
	 * @param string $sentence The static sentence.
	 * @param int $status The status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function refusal(
		string $rule,
		string $sentence,
		int $status = RefusedException::STATUS_UNPROCESSABLE,
		?Throwable $previous = null,
	): RefusedException {
		return new RefusedException(rule: $rule, sentence: $sentence, status: $status, previous: $previous);
	}//end refusal()
}//end class
