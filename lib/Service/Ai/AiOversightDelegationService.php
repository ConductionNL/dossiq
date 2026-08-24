<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Ai
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delegates human-oversight decisions on AI suggestions to hermiq.
 *
 * Hermiq owns AI oversight (EU AI Act Art. 14); procest owns the moment a
 * handler accepts, rejects or corrects a suggestion. This is how the second
 * tells the first, over the typed event contract hermiq publishes — procest
 * never writes into hermiq's register, exactly as it never writes into
 * decidesk's (ADR-041, and see ContractDecisionDelegationService for the same
 * shape).
 *
 * DOES NOT FAIL CLOSED, unlike the decision delegation. That one gates work:
 * if decidesk is unreachable the decision must not proceed. This one records
 * something that ALREADY HAPPENED — the handler has accepted the suggestion and
 * the case has moved on. Refusing after the fact would turn an audit outage into
 * a functional one, so a failure is reported to the caller and the caller keeps
 * its local copy.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
 */
class AiOversightDelegationService {

	/**
	 * Hermiq's published event class. Resolved by name so procest stays
	 * installable without hermiq.
	 *
	 * @var string
	 */
	private const OVERSIGHT_EVENT = '\\OCA\\Hermiq\\Event\\AiOversightRecordedEvent';

	/**
	 * procest's own userAction vocabulary mapped onto hermiq's.
	 *
	 * `modified` and `overridden` are the same fact under two names; hermiq's
	 * is the one that survives, because the record lives there.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_MAP = [
		'accepted' => 'accepted',
		'rejected' => 'rejected',
		'modified' => 'overridden',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Send one human-oversight decision to hermiq.
	 *
	 * @param array<string, mixed> $entry A procest AI audit entry that carries a `userAction`.
	 *
	 * @return boolean True when hermiq recorded it; false when hermiq is absent or refused.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
	 */
	public function delegate(array $entry): bool {
		$userAction = (string)($entry['userAction'] ?? '');
		if (isset(self::ACTION_MAP[$userAction]) === false) {
			// Not an oversight DECISION. A bare suggestion entry (action=suggestion)
			// records that the model ran, not that a human judged it, and hermiq's
			// advisory Approval is a decision record. Sending it would put a row in
			// the Art. 14 log that nobody decided.
			return false;
		}

		$eventClass = self::OVERSIGHT_EVENT;
		if (class_exists($eventClass) === false) {
			// Hermiq not installed. Not an error: the caller keeps its local copy
			// and the instance simply has no central oversight surface.
			return false;
		}

		$subjectType = 'case';
		$subjectId = (string)($entry['caseId'] ?? '');
		if ($subjectId === '' && (string)($entry['documentId'] ?? '') !== '') {
			$subjectType = 'document';
			$subjectId = (string)$entry['documentId'];
		}

		if ($subjectId === '') {
			$this->logger->warning(
				'AI oversight: entry has neither caseId nor documentId; not delegated',
				['type' => (string)($entry['type'] ?? '')]
			);
			return false;
		}

		try {
			$event = new $eventClass(
				[
					'originApp' => 'procest',
					'subjectType' => $subjectType,
					'subjectId' => $subjectId,
					'humanAction' => self::ACTION_MAP[$userAction],
					'userId' => (string)($entry['userId'] ?? ''),
					'decidedAt' => (string)($entry['timestamp'] ?? ''),
					'suggestionType' => (string)($entry['type'] ?? ''),
					'action' => (string)($entry['action'] ?? ''),
					'model' => (string)($entry['model'] ?? ''),
					'prompt' => (string)($entry['prompt'] ?? ''),
					'suggestion' => $this->flatten(value: ($entry['suggestion'] ?? '')),
					'confidence' => ($entry['confidence'] ?? null),
					'actualValue' => $this->flatten(value: ($entry['actualValue'] ?? '')),
					'reason' => (string)($entry['reason'] ?? ''),
					'responseTimeMs' => ($entry['responseTimeMs'] ?? null),
					// Idempotency: replaying the same entry through the repair
					// step must not double-record it.
					'externalRef' => $this->reference(entry: $entry, subjectId: $subjectId),
				]
			);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'AI oversight: delegation to hermiq failed',
				['error' => $e->getMessage(), 'subjectId' => $subjectId]
			);
			return false;
		}//end try

		return (bool)$event->isHandled();
	}//end delegate()

	/**
	 * Render a suggestion or applied value as a string.
	 *
	 * These fields are `array|string` in procest's schema — a classification is
	 * a scalar, an extraction is a map — and hermiq's advisory context stores
	 * what the human SAW, which is a rendered value either way.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The rendered value.
	 */
	private function flatten(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if (is_array($value) === true && empty($value) === false) {
			return (string)json_encode($value);
		}

		return '';
	}//end flatten()

	/**
	 * Build a stable reference for this decision.
	 *
	 * @param array<string, mixed> $entry The audit entry.
	 * @param string $subjectId The resolved subject id.
	 *
	 * @return string The reference.
	 */
	private function reference(array $entry, string $subjectId): string {
		$uuid = (string)($entry['id'] ?? ($entry['uuid'] ?? ''));
		if ($uuid !== '') {
			return 'procest:aiAuditEntry:' . $uuid;
		}

		return 'procest:aiAuditEntry:' . sha1(
			$subjectId . '|' . (string)($entry['type'] ?? '') . '|' . (string)($entry['timestamp'] ?? '')
		);

	}//end reference()

}//end class
