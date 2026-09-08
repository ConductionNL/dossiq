<?php

/**
 * Dossiq Bulk Status Transition Service.
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
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Transitions\GuardFailedException;
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
	 * @param CaseLifecycleService $lifecycle The suspend/resume/extend gestures
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly StatusTransitionService $transitionEngine,
		private readonly CaseLifecycleService $lifecycle,
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
	 * The lifecycle gestures a bulk call may ask for, and the `state()` flag
	 * that says whether a given case allows each one.
	 *
	 * A transition is deliberately NOT in this map: it goes through the
	 * status engine, which is the only write path for `case.status`.
	 */
	private const LIFECYCLE_GESTURES = [
		'suspend' => 'canSuspend',
		'resume' => 'canResume',
		'extend' => 'canExtend',
	];

	/**
	 * Preview a bulk lifecycle gesture: per case, does the case type allow it
	 * and is the case in a state that permits it right now? Performs NO
	 * writes — it reads `CaseLifecycleService::state()`, which is what the
	 * case page already reads to decide which affordances are honest to show.
	 *
	 * This exists so the four bulk actions report the same way the transition
	 * one does. A gesture the case type forbids (`suspensionAllowed: false`)
	 * or the case's own state forbids (already suspended, not suspended, a
	 * closed case) is BLOCKED here with its reason, before anything is
	 * written, rather than surfacing as a per-case exception afterwards.
	 *
	 * @param array<int, string> $caseIds Case UUIDs (1..100)
	 * @param string $gesture One of suspend, resume, extend
	 *
	 * @return array{results: array<string, array<string, mixed>>, summary: array<string, int>}
	 *
	 * @throws RuntimeException When the id count is 0, the cap is exceeded, or the gesture is unknown
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function previewLifecycle(array $caseIds, string $gesture): array {
		$flag = $this->validateLifecycleRequest(caseIds: $caseIds, gesture: $gesture);

		$results = [];
		$ready = 0;
		$blocked = 0;
		$errors = 0;

		foreach ($caseIds as $caseId) {
			$caseId = (string)$caseId;

			try {
				$state = $this->lifecycle->state(caseId: $caseId);

				if (($state[$flag] ?? false) === true) {
					$ready++;
					$results[$caseId] = ['status' => 'ready', 'reasons' => []];
					continue;
				}

				$blocked++;
				$results[$caseId] = [
					'status' => 'blocked',
					'reasons' => [['message' => $this->refusalCode(gesture: $gesture, state: $state)]],
				];
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->error(
					'BulkStatusTransitionService: lifecycle preview failed for case',
					['exception' => $e->getMessage(), 'caseId' => $caseId, 'gesture' => $gesture],
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
	}//end previewLifecycle()

	/**
	 * Execute a bulk lifecycle gesture: loops `CaseLifecycleService`'s
	 * `suspend()` / `resume()` / `extend()` once per case, which are the same
	 * single write paths the case page's own Actions menu uses. Every guard
	 * the single-case gesture applies — the case type's `suspensionAllowed` /
	 * `extensionAllowed`, the already-suspended and not-suspended checks, the
	 * required reason — applies here unchanged, because this loops those
	 * methods rather than reimplementing them.
	 *
	 * A per-case refusal is caught and recorded as that case's outcome and
	 * never aborts the batch: partial success is allowed and reported, so
	 * eight suspended cases and two refused ones read as exactly that.
	 *
	 * @param array<int, string> $caseIds Case UUIDs (1..100)
	 * @param string $gesture One of suspend, resume, extend
	 * @param string $reason The statutory reason, applied to every case
	 * @param int $days Suspension days (suspend only; the service defaults it when <= 0)
	 * @param string $newEndDate Explicit new end date (extend only; the case type's period when empty)
	 *
	 * @return array{results: array<string, array<string, mixed>>, summary: array<string, int>}
	 *
	 * @throws RuntimeException When the id count is 0, the cap is exceeded, the
	 *                          gesture is unknown, or the reason is empty
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function executeLifecycle(
		array $caseIds,
		string $gesture,
		string $reason,
		int $days = 0,
		string $newEndDate = '',
	): array {
		$this->validateLifecycleRequest(caseIds: $caseIds, gesture: $gesture);

		// The reason is required for the BATCH, not per case: a bulk gesture
		// with no reason is a mistake to refuse outright, not a hundred
		// identical per-case failures to read through.
		if (trim($reason) === '') {
			throw new RuntimeException('reason_required');
		}

		$results = [];
		$succeeded = 0;
		$failed = 0;
		$errors = 0;

		foreach ($caseIds as $caseId) {
			$caseId = (string)$caseId;

			try {
				$state = match ($gesture) {
					'suspend' => $this->lifecycle->suspend(caseId: $caseId, reason: $reason, days: $days),
					'resume' => $this->lifecycle->resume(caseId: $caseId, reason: $reason),
					default => $this->lifecycle->extend(
						caseId: $caseId,
						reason: $reason,
						newEndDate: $newEndDate,
					),
				};

				$succeeded++;
				$results[$caseId] = ['status' => 'succeeded', 'state' => $state];
			} catch (RuntimeException $e) {
				// A refusal, not a failure of ours: the case type or the
				// case's own state said no, and the code says which.
				$failed++;
				$results[$caseId] = [
					'status' => 'failed',
					'reasons' => [['message' => $e->getMessage()]],
				];
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->error(
					'BulkStatusTransitionService: lifecycle execute failed for case',
					['exception' => $e->getMessage(), 'caseId' => $caseId, 'gesture' => $gesture],
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
	}//end executeLifecycle()

	/**
	 * Validate the shared shape of a bulk LIFECYCLE request and resolve the
	 * `state()` flag the gesture is gated on.
	 *
	 * @param array<int, string> $caseIds Case UUIDs
	 * @param string $gesture The requested gesture
	 *
	 * @return string The `state()` key that says whether a case allows it
	 *
	 * @throws RuntimeException When validation fails
	 */
	private function validateLifecycleRequest(array $caseIds, string $gesture): string {
		if (isset(self::LIFECYCLE_GESTURES[$gesture]) === false) {
			throw new RuntimeException('unknown_gesture');
		}

		$count = count($caseIds);
		if ($count === 0) {
			throw new RuntimeException('case_ids_required');
		}

		if ($count > self::MAX_CASE_IDS) {
			throw new RuntimeException('too_many_case_ids');
		}

		return self::LIFECYCLE_GESTURES[$gesture];
	}//end validateLifecycleRequest()

	/**
	 * Why a case refuses a gesture, as a code the dialog can render.
	 *
	 * The flag alone says only "no". These codes are the same ones the
	 * single-case gestures throw, so a bulk refusal and a single-case
	 * refusal read identically.
	 *
	 * @param string $gesture The requested gesture
	 * @param array<string, mixed> $state The case's lifecycle state
	 *
	 * @return string The refusal code
	 */
	private function refusalCode(string $gesture, array $state): string {
		if (($state['isFinalStatus'] ?? false) === true && $gesture !== 'resume') {
			return 'case_closed';
		}

		if ($gesture === 'suspend' && ($state['suspended'] ?? false) === true) {
			return 'already_suspended';
		}

		return match ($gesture) {
			'suspend' => 'suspension_not_allowed',
			'resume' => 'not_suspended',
			default => 'extension_not_allowed',
		};
	}//end refusalCode()

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
