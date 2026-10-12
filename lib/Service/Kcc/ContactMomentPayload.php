<?php

/**
 * Dossiq KCC contact moment payload
 *
 * What a contact moment may hold when it is created and when it is updated:
 * the allowed channel, direction and outcome, the optional fields that pass
 * through, the timestamps and the duration between them. It reads nothing from
 * storage, so the service that persists the moment stays a short sequence of
 * lookups and saves.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Shapes and validates the stored form of a contact moment.
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
 */
class ContactMomentPayload {
	/**
	 * Allowed channels for a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const CHANNELS = ['phone', 'email', 'web_form', 'chat', 'social', 'in_person', 'letter'];

	/**
	 * Allowed directions for a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const DIRECTIONS = ['inbound', 'outbound'];

	/**
	 * Allowed outcomes for a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const OUTCOMES = ['open', 'resolved', 'transferred', 'callback_scheduled', 'escalated'];

	/**
	 * Optional string fields a create copies when they are set and not empty.
	 *
	 * @var array<int, string>
	 */
	private const PASSTHROUGH = [
		'customerRef',
		'customerName',
		'customerPhone',
		'customerEmail',
		'assignedTeam',
		'assignedDomain',
		'case',
		'linkedContactMoment',
	];

	/**
	 * Fields an update may overwrite, as strings.
	 *
	 * @var array<int, string>
	 */
	private const MUTABLE = ['subject', 'summary', 'assignedTeam', 'assignedDomain', 'outcome', 'customerName'];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date write path.
	 */
	public function __construct(
		private CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The stored form of a new contact moment.
	 *
	 * The agent is supplied by the caller and never read from the request body.
	 *
	 * @param array<string,mixed> $data The request data.
	 * @param string $agentId The authenticated agent's user id.
	 *
	 * @return array<string,mixed> The contact moment to store.
	 *
	 * @throws OCSBadRequestException When channel, direction or outcome is not allowed.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function forCreate(array $data, string $agentId): array {
		$payload = [
			'channel' => $this->oneOf(value: (string)($data['channel'] ?? ''), allowed: self::CHANNELS, label: 'channel'),
			'direction' => $this->oneOf(value: (string)($data['direction'] ?? 'inbound'), allowed: self::DIRECTIONS, label: 'direction'),
			'outcome' => $this->oneOf(value: (string)($data['outcome'] ?? 'open'), allowed: self::OUTCOMES, label: 'outcome'),
			'kccAgentRef' => $agentId,
			'subject' => trim((string)($data['subject'] ?? '')),
			'summary' => trim((string)($data['summary'] ?? '')),
		];

		foreach (self::PASSTHROUGH as $field) {
			if ($this->isFilled(data: $data, field: $field) === true) {
				$payload[$field] = (string)$data[$field];
			}
		}

		$payload = $this->withTags(payload: $payload, data: $data);

		return $this->withCreateTimestamps(payload: $payload, data: $data);
	}//end forCreate()

	/**
	 * The stored form of an existing contact moment after an update.
	 *
	 * Only the mutable fields, the end and the tags are taken from the request;
	 * everything else stays as stored.
	 *
	 * @param array<string,mixed> $existing The stored contact moment.
	 * @param array<string,mixed> $data The fields to update.
	 *
	 * @return array<string,mixed> The contact moment to store.
	 *
	 * @throws OCSBadRequestException When the resulting outcome is not allowed.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-02
	 */
	public function merged(array $existing, array $data): array {
		$update = $existing;
		foreach (self::MUTABLE as $field) {
			if (array_key_exists($field, $data) === true) {
				$update[$field] = (string)$data[$field];
			}
		}

		if (isset($update['outcome']) === true) {
			$this->oneOf(value: (string)$update['outcome'], allowed: self::OUTCOMES, label: 'outcome');
		}

		$update = $this->withUpdatedEnd(update: $update, data: $data);

		return $this->withTags(payload: $update, data: $data);
	}//end merged()

	/**
	 * A value that must be one of a fixed list.
	 *
	 * @param string $value The value to check.
	 * @param array<int,string> $allowed The allowed values.
	 * @param string $label The field name for the refusal.
	 *
	 * @return string The value.
	 *
	 * @throws OCSBadRequestException When the value is not allowed.
	 */
	private function oneOf(string $value, array $allowed, string $label): string {
		if (in_array($value, $allowed, true) === false) {
			throw new OCSBadRequestException('Invalid ' . $label);
		}

		return $value;
	}//end oneOf()

	/**
	 * Whether the request carries a non-empty value for a field.
	 *
	 * @param array<string,mixed> $data The request data.
	 * @param string $field The field name.
	 *
	 * @return bool True when set and not the empty string.
	 */
	private function isFilled(array $data, string $field): bool {
		return isset($data[$field]) === true && $data[$field] !== '';
	}//end isFilled()

	/**
	 * The payload with the request's tags as a list of strings, when it sends a list.
	 *
	 * @param array<string,mixed> $payload The payload to extend.
	 * @param array<string,mixed> $data The request data.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function withTags(array $payload, array $data): array {
		if (isset($data['tags']) === true && is_array($data['tags']) === true) {
			$payload['tags'] = array_values(array_map('strval', $data['tags']));
		}

		return $payload;
	}//end withTags()

	/**
	 * The payload with its start (now unless given), and its end and duration when given.
	 *
	 * @param array<string,mixed> $payload The payload to extend.
	 * @param array<string,mixed> $data The request data.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function withCreateTimestamps(array $payload, array $data): array {
		$payload['startedAt'] = $this->dates->nowAsMoment();
		if ($this->isFilled(data: $data, field: 'startedAt') === true) {
			$payload['startedAt'] = $this->dates->toMoment($data['startedAt'], 'startedAt');
		}

		if ($this->isFilled(data: $data, field: 'endedAt') === true) {
			$payload['endedAt'] = $this->dates->toMoment($data['endedAt'], 'endedAt');
			$payload['durationSeconds'] = $this->duration(start: $payload['startedAt'], end: $payload['endedAt']);
		}

		return $payload;
	}//end withCreateTimestamps()

	/**
	 * The update with the request's end and the duration it gives.
	 *
	 * An end is only taken when the stored moment has a start to measure from.
	 * It is stored as sent, which is what the update has always done.
	 *
	 * @param array<string,mixed> $update The moment being updated.
	 * @param array<string,mixed> $data The request data.
	 *
	 * @return array<string,mixed> The update.
	 */
	private function withUpdatedEnd(array $update, array $data): array {
		if ($this->isFilled(data: $data, field: 'endedAt') === false || isset($update['startedAt']) === false) {
			return $update;
		}

		$update['endedAt'] = (string)$data['endedAt'];
		$update['durationSeconds'] = $this->duration(start: (string)$update['startedAt'], end: $update['endedAt']);

		return $update;
	}//end withUpdatedEnd()

	/**
	 * Seconds between two timestamps.
	 *
	 * @param string $start The start timestamp.
	 * @param string $end The end timestamp.
	 *
	 * @return int The duration, 0 when either is unreadable or the end is earlier.
	 */
	private function duration(string $start, string $end): int {
		try {
			$startTs = $this->dates->parse($start, 'startedAt')->getTimestamp();
			$endTs = $this->dates->parse($end, 'endedAt')->getTimestamp();
		} catch (\Throwable $e) {
			return 0;
		}

		return max(0, ($endTs - $startTs));
	}//end duration()
}//end class
