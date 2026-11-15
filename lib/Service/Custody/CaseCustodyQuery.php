<?php

/**
 * Reading the chain of custody back: who held a case, and what a unit held.
 *
 * The two questions this record exists for. Both are answered from the holdings
 * themselves and never from the audit diff, which is the distinction D-1 draws:
 * the audit says what changed, the chain says who held it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseCustodyQueryInterface;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Who held this case on a date, and which cases a unit held in a period.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseCustodyQuery implements CaseCustodyQueryInterface {

	/**
	 * How many holdings one unit read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param CaseCustodyChain $chain           The chain the answers are read from.
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface  $logger          Records a read that could not be answered.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseCustodyChain $chain,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The holding that covered a case on a moment, or null.
	 *
	 * Exactly one holding covers any moment the case existed, because a move
	 * closes one holding and opens the next at the SAME moment. The boundary is
	 * therefore half-open: the holding that began at that instant owns it, and
	 * the one that ended does not, so a transfer date never returns two units.
	 *
	 * @param string $caseId The case.
	 * @param string $asOf   The moment, in anything DateTimeImmutable reads.
	 *
	 * @return array<string, mixed>|null The holding.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holderOn(string $caseId, string $asOf): ?array {
		$moment = (new StoredInstant())->parse(value: $asOf);
		if ($moment === null) {
			return null;
		}

		$found = null;
		foreach ($this->chain->holdings(caseId: $caseId) as $holding) {
			$from = (new StoredInstant())->parse(value: (string)($holding['from'] ?? ''));
			if ($from === null || $from > $moment) {
				continue;
			}

			$until = (new StoredInstant())->parse(value: (string)($holding['until'] ?? ''));
			if ($until !== null && $until <= $moment) {
				continue;
			}

			$found = $holding;
		}

		return $found;
	}//end holderOn()

	/**
	 * The holdings a unit had that overlap a period.
	 *
	 * A holding counts when it started before the window ended and had not
	 * ended before the window began. An open holding has no end, so it counts
	 * whenever it started in time.
	 *
	 * @param string $organisationUnit The unit.
	 * @param string $from             The start of the window.
	 * @param string $to               The end of the window.
	 *
	 * @return array<int, array<string, mixed>> The holdings, oldest first.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function heldBy(string $organisationUnit, string $from, string $to): array {
		$organisationUnit = trim($organisationUnit);
		$windowStart = (new StoredInstant())->parse(value: $from);
		$windowEnd = (new StoredInstant())->parse(value: $to);
		if ($organisationUnit === '' || $windowStart === null || $windowEnd === null) {
			return [];
		}

		$overlapping = array_values(
			array_filter(
				$this->unitHoldings(organisationUnit: $organisationUnit),
				fn (array $row): bool => $this->overlaps(holding: $row, windowStart: $windowStart, windowEnd: $windowEnd) === true,
			)
		);

		return $this->oldestFirst(holdings: $overlapping);
	}//end heldBy()

	/**
	 * Every holding a unit had, or an empty list when the register cannot be read.
	 *
	 * @param string $organisationUnit The unit, trimmed.
	 *
	 * @return array<int, array<string, mixed>> The holdings.
	 */
	private function unitHoldings(string $organisationUnit): array {
		return (new CustodyRows(settingsService: $this->settingsService, logger: $this->logger))->find(
			filters: ['organisationUnit' => $organisationUnit, '_limit' => self::PAGE_SIZE],
			failure: 'Dossiq custody: the holdings of a unit could not be read',
		);
	}//end unitHoldings()

	/**
	 * Holdings ordered by the moment they began, oldest first.
	 *
	 * @param array<int, array<string, mixed>> $holdings The holdings.
	 *
	 * @return array<int, array<string, mixed>> The same holdings, sorted.
	 */
	private function oldestFirst(array $holdings): array {
		usort(
			$holdings,
			static function (array $left, array $right): int {
				return (((string)($left['from'] ?? '')) <=> ((string)($right['from'] ?? '')));
			},
		);

		return $holdings;
	}//end oldestFirst()

	/**
	 * Whether one holding overlaps the window that was asked about.
	 *
	 * An open holding, the one with no `until`, reaches into the window from
	 * whenever it started, which is why an absent end is not read as "ended".
	 *
	 * @param array<string, mixed> $holding     The holding as it was read.
	 * @param DateTimeImmutable    $windowStart The start of the window.
	 * @param DateTimeImmutable    $windowEnd   The end of the window.
	 *
	 * @return bool True when the holding falls inside the window.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	private function overlaps(array $holding, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): bool {
		$holdingStart = (new StoredInstant())->parse(value: (string)($holding['from'] ?? ''));
		if ($holdingStart === null || $holdingStart > $windowEnd) {
			return false;
		}

		$holdingEnd = (new StoredInstant())->parse(value: (string)($holding['until'] ?? ''));

		return ($holdingEnd === null || $holdingEnd >= $windowStart);
	}//end overlaps()
}//end class
