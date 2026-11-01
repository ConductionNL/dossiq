<?php

/**
 * One stay of a case in a status: the status record that opened it and the one that followed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

/**
 * A status record and the next record of the same case, if any, read from the case's records.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class StatusVisit {

	/**
	 * The statusRecord that entered the status.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $record;

	/**
	 * The case's next statusRecord, or null for its last.
	 *
	 * @var array<string, mixed>|null
	 */
	public readonly ?array $next;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $records The case's chronologically sorted statusRecords.
	 * @param int                              $index   The position of the record that opens the visit.
	 */
	public function __construct(array $records, int $index) {
		$this->record = $records[$index];
		$next = null;
		if (($index + 1) < count($records)) {
			$next = $records[$index + 1];
		}

		$this->next = $next;
	}//end __construct()

	/**
	 * The status this visit is in, or '' when the record names none.
	 *
	 * @return string The statusType id.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function statusId(): string {
		return (string)($this->record['statusType'] ?? '');
	}//end statusId()

	/**
	 * Who entered the status, or '' when the record names nobody.
	 *
	 * `actor` is the statusRecord schema's own field, and the only one: a
	 * second spelling guessed at here would be dropped by OpenRegister in
	 * silence and read as nobody. Empty rather than absent, because a
	 * by-handler table that quietly omitted unattributed time would show less
	 * work than was done.
	 *
	 * @return string The actor.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function actor(): string {
		return trim((string)($this->record['actor'] ?? ''));
	}//end actor()

	/**
	 * The raw moment the status was entered, as the record holds it.
	 *
	 * @return mixed The timestamp value, or null.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function enteredMoment(): mixed {
		return $this->momentOf(record: $this->record);
	}//end enteredMoment()

	/**
	 * The raw moment of the next record, or null when this is the last visit.
	 *
	 * @return mixed The timestamp value, or null.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function nextMoment(): mixed {
		if ($this->next === null) {
			return null;
		}

		return $this->momentOf(record: $this->next);
	}//end nextMoment()

	/**
	 * A statusRecord's own timestamp, falling back to the object's metadata.
	 *
	 * @param array<string, mixed> $record The statusRecord.
	 *
	 * @return mixed The timestamp value, or null.
	 */
	private function momentOf(array $record): mixed {
		return ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
	}//end momentOf()
}//end class
