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
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
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
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 */
class StoppingRules {

	use SearchesObjects;

	/**
	 * The app config key of the stopping rule schema.
	 */
	public const SCHEMA_KEY = 'stopping_rule_schema';

	/**
	 * The confidences a rule may name.
	 */
	public const CONFIDENCES = [0.9, 0.95, 0.99];

	/**
	 * The refusal sentence once review started.
	 */
	public const LOCKED = 'A stopping rule is declared before review. Once a document is marked, it cannot change.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param DocumentRelevance $reviews Whether any document is marked yet.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRelevance $reviews,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case's stopping rule, or null when none was declared.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The rule.
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
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
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
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
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function declare(string $caseId, float $targetRecall, float $confidence, string $userId): array {
		if ($targetRecall < 0.5 || $targetRecall > 0.99 || in_array($confidence, self::CONFIDENCES, true) === false) {
			throw new RefusedException(
				rule: 'review-stopping-rule-out-of-range',
				sentence: 'Choose a target between 0.5 and 0.99 and a confidence of 0.90, 0.95 or 0.99.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($this->reviewStarted(caseId: $caseId) === true) {
			throw new RefusedException(rule: 'review-stopping-rule-locked', sentence: self::LOCKED, status: RefusedException::STATUS_REFUSED);
		}

		$existing = $this->forCase(caseId: $caseId);
		$uuid = null;
		if ($existing !== null) {
			$uuid = (string)($existing['id'] ?? ($existing['uuid'] ?? ''));
		}

		$rule = [
			'case' => $caseId,
			'targetRecall' => $targetRecall,
			'confidence' => $confidence,
			'declaredBy' => $userId,
			'declaredAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		return $this->save(rule: $rule, uuid: $uuid);
	}//end declare()

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
			$this->logger->error('Dossiq: a Stopping rule could not be stored', ['app' => Application::APP_ID, 'exception' => $e->getMessage()]);
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
