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
 * A status record and the next record of the same case, if any.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class StatusVisit {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $record The statusRecord that entered the status.
	 * @param array<string, mixed>|null $next   The case's next statusRecord, or null for its last.
	 */
	public function __construct(
		public readonly array $record,
		public readonly ?array $next,
	) {
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
}//end class
