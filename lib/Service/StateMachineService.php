<?php

/**
 * Dossiq Beschikking State Machine Service.
 *
 * Enforces the formal beschikking state-machine and produces immutable
 * stateMachineLog records for every transition. The machine is:
 *
 *   ontwerp -> akkoord-mandaat -> ondertekend -> verzonden
 *           -> ontvangen-bevestiging -> gearchiveerd
 *
 * with a single permitted back-edge (akkoord-mandaat -> ontwerp). Any other
 * transition is rejected. From `ondertekend` onward the beschikking content
 * is immutable (enforced by BeschikkingService).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T16
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guards beschikking state transitions and logs them immutably.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T16
 */
class StateMachineService {
	/**
	 * Allowed forward transitions and the single permitted back-edge.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = [
		'draft' => ['approved-mandate'],
		'approved-mandate' => ['signed', 'draft'],
		'signed' => ['sent'],
		'sent' => ['received-confirmation', 'archived'],
		'received-confirmation' => ['archived'],
		'archived' => [],
	];

	/**
	 * Statuses from which the beschikking content is immutable.
	 *
	 * @var array<int, string>
	 */
	public const IMMUTABLE_STATUSES = [
		'signed',
		'sent',
		'received-confirmation',
		'archived',
	];

	/**
	 * Fields that may NOT be edited once a beschikking is frozen.
	 *
	 * Lives beside IMMUTABLE_STATUSES because the two halves of the same rule
	 * drift when they live apart, and the drift is silent.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [
		'rationale',
		'decision',
		'addressee',
		'decisionType',
		'legalRemediesClause',
		'feeAmount',
		'templateId',
	];

	/**
	 * The exception message a refused mutation carries.
	 *
	 * `BeschikkingController` maps it to HTTP 409.
	 *
	 * @var string
	 */
	public const IMMUTABLE_ERROR = 'immutable';

	/**
	 * What a handler is told when a frozen beschikking refuses a write.
	 *
	 * REQ-BES-008 requires the refusal to name the way forward, so a handler
	 * who is told no is also told what to do instead.
	 *
	 * @var string
	 */
	public const IMMUTABLE_MESSAGE = 'This beschikking has been signed and cannot be edited. '
		. 'Issue a wijzigingsbeschikking or an intrekkingsbeschikking instead.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the given status locks the beschikking content.
	 *
	 * @param string $status The current status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T16
	 */
	public function isImmutable(string $status): bool {
		return in_array($status, self::IMMUTABLE_STATUSES, true);
	}//end isImmutable()

	/**
	 * Validate a transition between two statuses.
	 *
	 * @param string $currentStatus The source status.
	 * @param string $nextStatus The target status.
	 *
	 * @return bool True when the transition is permitted.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T16
	 */
	public function validateTransition(string $currentStatus, string $nextStatus): bool {
		$allowed = (self::TRANSITIONS[$currentStatus] ?? null);
		if ($allowed === null) {
			return false;
		}

		return in_array($nextStatus, $allowed, true);
	}//end validateTransition()

	/**
	 * Persist an immutable stateMachineLog record for a transition.
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $from The source status.
	 * @param string $to The target status.
	 * @param array<string, mixed> $metadata Actor/trigger/evidence metadata.
	 *
	 * @return array<string, mixed> The persisted log record (or an empty array when storage is unavailable).
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T16
	 */
	public function logTransition(string $decisionId, string $from, string $to, array $metadata = []): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning('StateMachineService: storage unavailable, transition not logged');
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$logSchema = $this->settingsService->getConfigValue(key: 'state_machine_log_schema');
		if ($register === '' || $logSchema === '') {
			$this->logger->warning('StateMachineService: log schema not configured');
			return [];
		}

		$record = [
			'decisionId' => $decisionId,
			'overgang' => [
				'van' => $from,
				'to' => $to,
				'moment' => (new DateTimeImmutable())->format('c'),
				'actor' => (string)($metadata['actor'] ?? 'systeem'),
				'actorType' => (string)($metadata['actorType'] ?? 'systeem'),
				'trigger' => (string)($metadata['trigger'] ?? 'automatic'),
				'evidenceMaterial' => ($metadata['evidenceMaterial'] ?? null),
			],
		];

		try {
			$saved = $objectService->saveObject(object: $record, register: $register, schema: $logSchema);
			return $this->toArray(value: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StateMachineService: failed to persist transition log',
				['exception' => $e->getMessage(), 'decisionId' => $decisionId],
			);
			return [];
		}
	}//end logTransition()

	/**
	 * Refuse a content edit on a beschikking that has been signed.
	 *
	 * The STORED status decides. A payload that claims `draft` over a row that
	 * is `sent` must be refused, and it is, because `$changed` is only ever
	 * consulted for which fields the caller means to touch.
	 *
	 * Process events stay allowed at every status: `dispatch`, the bezwaar
	 * link and `archive` are not content, and a beschikking that could not
	 * record its own delivery would be unusable.
	 *
	 * @param array<string, mixed> $stored The beschikking as it is in the store.
	 * @param array<string, mixed> $changed The fields the caller intends to change, keyed by name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException 'immutable' when the stored status is frozen and a content field is touched.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function assertMutable(array $stored, array $changed): void {
		if ($this->isImmutable(status: (string)($stored['currentStatus'] ?? '')) === false) {
			return;
		}

		foreach (array_keys($changed) as $field) {
			if (in_array($field, self::CONTENT_FIELDS, true) === true) {
				throw new RuntimeException(self::IMMUTABLE_ERROR);
			}
		}
	}//end assertMutable()

	/**
	 * Refuse a delete on a beschikking that has been signed.
	 *
	 * A signed beschikking is evidence. Correcting it is a successor
	 * (REQ-BES-012); removing it is never an answer.
	 *
	 * @param array<string, mixed> $stored The beschikking as it is in the store.
	 *
	 * @return void
	 *
	 * @throws RuntimeException 'immutable' when the stored status is frozen.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function assertDeletable(array $stored): void {
		if ($this->isImmutable(status: (string)($stored['currentStatus'] ?? '')) === true) {
			throw new RuntimeException(self::IMMUTABLE_ERROR);
		}
	}//end assertDeletable()

	/**
	 * Normalise an ObjectService return value to an array.
	 *
	 * @param mixed $value The entity, array, or JsonSerializable returned by OpenRegister.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
