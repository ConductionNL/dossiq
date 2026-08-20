<?php

/**
 * Procest Bulk Status Transition Service.
 *
 * Bulk operations over the status-transition engine. `StatusTransitionService`
 * remains the ONLY write path for `case.status` — this service never mutates a
 * case directly. `preview()` reuses the engine's read-only
 * `getAvailableTransitions()` (which already evaluates every guard without
 * writing anything) to report per-case readiness; `execute()` loops the
 * engine's `execute()` once per case, isolating each case's guard failure or
 * exception so one bad case never aborts the rest of the batch.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Transitions\GuardFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bulk wrapper around the status-transition engine.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */
class BulkStatusTransitionService {

	/**
	 * Hard cap on the number of case ids accepted per bulk call.
	 */
	public const MAX_CASE_IDS = 100;

	/**
	 * Constructor.
	 *
	 * @param StatusTransitionService $transitionEngine The single write-path engine
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly StatusTransitionService $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Preview a bulk transition: per case, is it available and do its guards
	 * currently pass? Performs NO writes — it only reads the engine's
	 * `getAvailableTransitions()`, which itself never mutates state.
	 *
	 * @param array<int, string> $caseIds Case UUIDs (1..100)
	 * @param string $transitionId Transition id to preview
	 *
	 * @return array{results: array<string, array<string, mixed>>, summary: array<string, int>}
	 *
	 * @throws RuntimeException When the id count is 0, the cap is exceeded, or transitionId is empty
	 *
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 */
	public function preview(array $caseIds, string $transitionId): array {
		$this->validateRequest(caseIds: $caseIds, transitionId: $transitionId);

		$results = [];
		$ready = 0;
		$blocked = 0;
		$errors = 0;

		foreach ($caseIds as $caseId) {
			$caseId = (string)$caseId;

			try {
				$available = $this->transitionEngine->getAvailableTransitions(caseId: $caseId);
				$transition = $this->findTransition(transitions: $available['transitions'], transitionId: $transitionId);

				if ($transition === null) {
					$blocked++;
					$results[$caseId] = [
						'status' => 'blocked',
						'reasons' => [['message' => 'transition_not_available']],
					];
					continue;
				}

				if (($transition['guardsPassed'] ?? false) === true) {
					$ready++;
					$results[$caseId] = ['status' => 'ready', 'reasons' => []];
					continue;
				}

				$blocked++;
				$results[$caseId] = [
					'status' => 'blocked',
					'reasons' => $transition['failedGuards'] ?? [],
				];
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->error(
					'BulkStatusTransitionService: preview failed for case',
					['exception' => $e->getMessage(), 'caseId' => $caseId, 'transitionId' => $transitionId],
				);
				$results[$caseId] = [
					'status' => 'error',
					'reasons' => [['message' => 'preview_failed']],
				];
			}//end try
		}//end foreach

		return [
			'results' => $results,
			'summary' => [
				'total' => count($caseIds),
				'ready' => $ready,
				'blocked' => $blocked,
				'error' => $errors,
			],
		];
	}//end preview()

	/**
	 * Execute a bulk transition: loops `StatusTransitionService::execute()`
	 * once per case. A guard failure or any other per-case throwable is
	 * caught and recorded as that case's outcome — it never aborts the
	 * remaining cases in the batch (partial success is allowed and reported).
	 *
	 * @param array<int, string> $caseIds Case UUIDs (1..100)
	 * @param string $transitionId Transition id to execute
	 * @param string|null $comment Optional free-form comment applied to every case
	 *
	 * @return array{results: array<string, array<string, mixed>>, summary: array<string, int>}
	 *
	 * @throws RuntimeException When the id count is 0, the cap is exceeded, or transitionId is empty
	 *
	 * @spec openspec/specs/case-bulk-status-transition/spec.md
	 */
	public function execute(array $caseIds, string $transitionId, ?string $comment): array {
		$this->validateRequest(caseIds: $caseIds, transitionId: $transitionId);

		$results = [];
		$succeeded = 0;
		$failed = 0;
		$errors = 0;

		foreach ($caseIds as $caseId) {
			$caseId = (string)$caseId;

			try {
				$outcome = $this->transitionEngine->execute(
					caseId: $caseId,
					transitionId: $transitionId,
					comment: $comment,
				);

				$succeeded++;
				$results[$caseId] = [
					'status' => 'succeeded',
					'statusRecord' => $outcome['statusRecord'],
				];
			} catch (GuardFailedException $e) {
				$failed++;
				$results[$caseId] = [
					'status' => 'failed',
					'reasons' => $e->getFailedGuards(),
				];
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->error(
					'BulkStatusTransitionService: execute failed for case',
					['exception' => $e->getMessage(), 'caseId' => $caseId, 'transitionId' => $transitionId],
				);
				$results[$caseId] = [
					'status' => 'error',
					'reasons' => [['message' => 'execute_failed']],
				];
			}//end try
		}//end foreach

		return [
			'results' => $results,
			'summary' => [
				'total' => count($caseIds),
				'succeeded' => $succeeded,
				'failed' => $failed,
				'error' => $errors,
			],
		];
	}//end execute()

	/**
	 * Validate the shared shape of a bulk request: 1..MAX_CASE_IDS case ids
	 * and a non-empty transitionId.
	 *
	 * @param array<int, string> $caseIds Case UUIDs
	 * @param string $transitionId Transition id
	 *
	 * @return void
	 *
	 * @throws RuntimeException When validation fails
	 */
	private function validateRequest(array $caseIds, string $transitionId): void {
		if ($transitionId === '') {
			throw new RuntimeException('transition_id_required');
		}

		$count = count($caseIds);
		if ($count === 0) {
			throw new RuntimeException('case_ids_required');
		}

		if ($count > self::MAX_CASE_IDS) {
			throw new RuntimeException('too_many_case_ids');
		}
	}//end validateRequest()

	/**
	 * Find a transition by id within a `getAvailableTransitions()` result set.
	 *
	 * @param array<int, array<string, mixed>> $transitions Available transitions
	 * @param string $transitionId Transition id to find
	 *
	 * @return array<string, mixed>|null
	 */
	private function findTransition(array $transitions, string $transitionId): ?array {
		foreach ($transitions as $transition) {
			if (($transition['id'] ?? '') === $transitionId) {
				return $transition;
			}
		}

		return null;
	}//end findTransition()
}//end class
