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
