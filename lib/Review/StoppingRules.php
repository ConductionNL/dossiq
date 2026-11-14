<?php

/**
 * Dossiq stopping rule: how much a review must find, declared before review.
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
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\StoppingRulesInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and declares the one `stoppingRule` of a case.
 *
 * The rule is only worth something when nobody could tune it to the result,
 * so it can be declared or replaced only while every document of the case is
 * still unmarked.
 *
 * The rule is private to its case (REQ-WRT-006): a declared rule reconciles
 * the case's reviewer access in the same request, so the assignee the case
 * type grants it to can read and update it.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 */
class StoppingRules implements StoppingRulesInterface {

	use SearchesObjects;

	/**
	 * The confidences a rule may name.
	 */
	public const CONFIDENCES = [0.9, 0.95, 0.99];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService The settings and OpenRegister access.
	 * @param DocumentRelevance $reviews Whether any document is marked yet.
	 * @param LoggerInterface $logger The logger.
	 * @param ReviewWrites $writes Grants the case's private records to the people on it once the rule is stored.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly DocumentRelevance $reviews,
		private readonly LoggerInterface $logger,
		private readonly ReviewWrites $writes,
	) {
	}//end __construct()

	/**
	 * The case's stopping rule, or null when none was declared.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The rule.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function forCase(string $caseId): ?array {
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		$objectService = $this->settingsService->getObjectService();
		if ($schema === '' || $objectService === null || $caseId === '') {
			return null;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $this->settingsService->getConfigValue('register'),
			schema: $schema,
			filters: ['case' => $caseId, '_limit' => 1],
		);
		$first = reset($rows);
		if (is_array($first) === false) {
			return null;
		}

		return $first;
	}//end forCase()

	/**
	 * Whether any document of the case is marked in or out of scope.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return bool True once review started.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function reviewStarted(string $caseId): bool {
		foreach ($this->reviews->forCase(caseId: $caseId) as $review) {
			if (($review['relevance'] ?? DocumentRelevance::UNMARKED) !== DocumentRelevance::UNMARKED) {
				return true;
			}
		}

		return false;
	}//end reviewStarted()

	/**
	 * Declare the rule, or replace it while review has not started.
	 *
	 * @param string $caseId The case UUID.
	 * @param float $targetRecall The share to find, 0.5 to 0.99.
	 * @param float $confidence 0.90, 0.95 or 0.99.
	 * @param string $userId Who declares it.
	 *
	 * @return array<string, mixed> The stored rule.
	 *
	 * @throws RefusedException When out of range, after review started, or when it cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function declare(string $caseId, float $targetRecall, float $confidence, string $userId): array {
		$this->requireInRange(targetRecall: $targetRecall, confidence: $confidence);

		if ($this->reviewStarted(caseId: $caseId) === true) {
			throw new RefusedException(rule: 'review-stopping-rule-locked', sentence: self::LOCKED, status: RefusedException::STATUS_REFUSED);
		}

		$uuid = $this->existingUuid(caseId: $caseId);

		$rule = $this->ruleOf(caseId: $caseId, targetRecall: $targetRecall, confidence: $confidence, userId: $userId);

		$saved = $this->save(rule: $rule, uuid: $uuid);
		$this->reconcile(caseId: $caseId);

		return $saved;
	}//end declare()

	/**
	 * Refuse a target recall or confidence outside the allowed range.
	 *
	 * @param float $targetRecall The target recall.
	 * @param float $confidence   The confidence.
	 *
	 * @return void
	 *
	 * @throws RefusedException When either is out of range.
	 */
	private function requireInRange(float $targetRecall, float $confidence): void {
		if ($targetRecall < 0.5 || $targetRecall > 0.99 || in_array($confidence, self::CONFIDENCES, true) === false) {
			throw new RefusedException(
				rule: 'review-stopping-rule-out-of-range',
				sentence: 'Choose a target between 0.5 and 0.99 and a confidence of 0.90, 0.95 or 0.99.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end requireInRange()

	/**
	 * The uuid of the rule the case already declared, or null.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string|null
	 */
	private function existingUuid(string $caseId): ?string {
		$existing = $this->forCase(caseId: $caseId);
		$uuid = null;
		if ($existing !== null) {
			$uuid = (string)($existing['id'] ?? ($existing['uuid'] ?? ''));
		}

		return $uuid;
	}//end existingUuid()

	/**
	 * The stopping rule as declared now.
	 *
	 * @param string $caseId       The case uuid.
	 * @param float  $targetRecall The target recall.
	 * @param float  $confidence   The confidence.
	 * @param string $userId       Who declares it.
	 *
	 * @return array<string, mixed>
	 */
	private function ruleOf(string $caseId, float $targetRecall, float $confidence, string $userId): array {
		return [
			'case' => $caseId,
			'targetRecall' => $targetRecall,
			'confidence' => $confidence,
			'declaredBy' => $userId,
			'declaredAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end ruleOf()

	/**
	 * Reconcile the case's reviewer access after the rule was stored.
	 *
	 * The rule is already stored, so a reconcile that fails is logged rather
	 * than undoing it; the administrator's reconcile route repairs the grants
	 * (case-reviewer-access REQ-CRA-004).
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/tasks.md#1-the-stopping-rule
	 */
	private function reconcile(string $caseId): void {
		try {
			$this->writes->ruleStored(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a stopping rule was stored but the reviewer access of its case could not be reconciled',
				['app' => AppIdentity::APP_ID, 'case' => $caseId, 'exception' => $e->getMessage()]
			);
		}
	}//end reconcile()

	/**
	 * Store the rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param string|null $uuid The rule to replace, or null.
	 *
	 * @return array<string, mixed> The stored rule.
	 *
	 * @throws RefusedException When it cannot be stored.
	 */
	private function save(array $rule, ?string $uuid): array {
		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue(self::SCHEMA_KEY);
		if ($objectService === null || $schema === '') {
			throw $this->unstored(previous: null);
		}

		try {
			$saved = $objectService->saveObject(
				object: $rule,
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: a Stopping rule could not be stored', ['app' => AppIdentity::APP_ID, 'exception' => $e->getMessage()]);
			throw $this->unstored(previous: $e);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * The refusal when the rule cannot be stored.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unstored(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'review-stopping-rule-unavailable',
			sentence: 'The stopping rule cannot be stored, so nothing was declared.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unstored()
}//end class
