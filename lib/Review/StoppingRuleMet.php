<?php

/**
 * Dossiq stopping rule met: records once that a recall estimate reached the declared rule.
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
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
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
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compares a finished recall sample with the case's stopping rule and, when the
 * lower bound reaches the target, writes one entry on OpenRegister's audit trail
 * of the case and one internal timeline entry.
 *
 * Nothing stops the review: the record says the rule was met, the handler decides.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
 */
class StoppingRuleMet {

	/**
	 * The audit action of a met stopping rule.
	 */
	public const ACTION = 'dossiq.review.stopping-rule-met';

	/**
	 * OpenRegister's audit trail mapper.
	 */
	private const AUDIT_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 * @param CaseTimeline $timeline The case timeline.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTimeline $timeline,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a finished sample meets the rule: a rule exists, the sample was estimated
	 * at the rule's confidence, and its lower bound is at least the target.
	 *
	 * @param array<string, mixed>|null $rule The case's stopping rule, or null.
	 * @param array<string, mixed> $sample The recall sample.
	 *
	 * @return bool True when met.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
	 */
	public static function isMet(?array $rule, array $sample): bool {
		if ($rule === null || isset($sample['recallLowerBound']) === false) {
			return false;
		}

		if (abs((float)($sample['confidence'] ?? 0) - (float)($rule['confidence'] ?? -1)) > 0.0001) {
			return false;
		}

		return (float)$sample['recallLowerBound'] >= (float)($rule['targetRecall'] ?? 2);
	}//end isMet()

	/**
	 * Record the rule as met for this sample, once.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed>|null $rule The case's stopping rule, or null.
	 * @param array<string, mixed> $sample The finished recall sample.
	 * @param string $userId Who judged the last sampled document.
	 *
	 * @return string|null The moment it was recorded, or null when nothing was recorded.
	 *
	 * @throws RefusedException When the audit trail cannot take the entry.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
	 */
	public function record(string $caseId, ?array $rule, array $sample, string $userId): ?string {
		if (($sample['stoppingRuleMetAt'] ?? '') !== '' || self::isMet(rule: $rule, sample: $sample) === false) {
			return null;
		}

		$sampleId = (string)($sample['id'] ?? ($sample['uuid'] ?? ''));
		$metAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$numbers = [
			'sample' => $sampleId,
			'targetRecall' => (float)$rule['targetRecall'],
			'confidence' => (float)$rule['confidence'],
			'recallEstimate' => (float)($sample['recallEstimate'] ?? 0),
			'recallLowerBound' => (float)$sample['recallLowerBound'],
			'n' => count((array)($sample['documents'] ?? [])),
			'k' => (int)($sample['k'] ?? 0),
		];

		$this->audit(caseId: $caseId, context: $numbers + ['rule' => (string)($rule['id'] ?? ($rule['uuid'] ?? '')), 'at' => $metAt], userId: $userId);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::STOPPING_RULE_MET,
			message: sprintf(
				'The stopping rule was met: recall at least %s at %s confidence (estimate %s, target %s, sample of %d)',
				number_format($numbers['recallLowerBound'], 4, '.', ''),
				number_format($numbers['confidence'], 2, '.', ''),
				number_format($numbers['recallEstimate'], 4, '.', ''),
				number_format($numbers['targetRecall'], 2, '.', ''),
				$numbers['n']
			),
			fields: $numbers,
			visibility: CaseTimeline::INTERNAL,
		);

		return $metAt;
	}//end record()

	/**
	 * Write the entry on the case's audit trail.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $context The rule, the sample and the numbers.
	 * @param string $userId The actor.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the entry cannot be written.
	 */
	private function audit(string $caseId, array $context, string $userId): void {
		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::AUDIT_MAPPER);
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $mapper === null || $register === '' || $schema === '') {
			throw $this->notRecorded(previous: null);
		}

		try {
			$case = $objectService->find($caseId, register: $register, schema: $schema);
			$mapper->createAuditTrailEntry(object: $case, action: self::ACTION, context: $context, actorId: $userId, actorName: $userId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a met stopping rule could not be recorded on the audit trail',
				['app' => Application::APP_ID, 'case' => $caseId, 'exception' => $e->getMessage()]
			);
			throw $this->notRecorded(previous: $e);
		}
	}//end audit()

	/**
	 * The refusal when the audit trail cannot take the entry.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function notRecorded(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'review-stopping-rule-met-not-recorded',
			sentence: 'That the stopping rule was met cannot be recorded, so the last judgement was not stored.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end notRecorded()
}//end class
