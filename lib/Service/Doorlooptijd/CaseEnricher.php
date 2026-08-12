<?php

/**
 * Procest CaseEnricher.
 *
 * Derives the `_`-prefixed working fields every throughput-time metric reads
 * from a raw `case` row: normalised start/end dates, whether the case is
 * still open, the effective deadline (the case's own, or one derived from
 * the caseType's ISO 8601 `processingDeadline`), the countdown in days, the
 * realised throughput in days, and the caseType display title.
 *
 * Split out of DoorlooptijdService so that service keeps only the load +
 * orchestrate role: the rules for what a case's dates *mean* — including the
 * caseType lookup that must resolve whether the case stores a UUID or a slug
 * — live here and nowhere else.
 *
 * @category Service
 * @package  OCA\Procest\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Doorlooptijd;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Adds the derived throughput-time fields to raw case rows.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class CaseEnricher {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger, for unparseable caseType durations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enrich each raw case with derived fields used by the metric helpers.
	 *
	 * @param array<int, array<string, mixed>> $cases Raw cases.
	 * @param array<int, array<string, mixed>> $caseTypes Raw case-types.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function enrichCases(array $cases, array $caseTypes): array {
		$today = new DateTimeImmutable('today');
		$caseTypeByKey = $this->indexCaseTypesByKey(caseTypes: $caseTypes);

		$enriched = [];
		foreach ($cases as $caseData) {
			$enriched[] = $this->enrichCase(
				caseData: $caseData,
				caseTypeByKey: $caseTypeByKey,
				today: $today,
			);
		}

		return $enriched;
	}//end enrichCases()

	/**
	 * Index case-types by both their id and their slug.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes Raw case-types.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function indexCaseTypesByKey(array $caseTypes): array {
		$caseTypeByKey = [];
		foreach ($caseTypes as $caseType) {
			$id = (string)($caseType['id'] ?? '');
			$slug = (string)($caseType['slug'] ?? '');
			if ($id !== '') {
				$caseTypeByKey[$id] = $caseType;
			}

			if ($slug !== '') {
				$caseTypeByKey[$slug] = $caseType;
			}
		}//end foreach

		return $caseTypeByKey;
	}//end indexCaseTypesByKey()

	/**
	 * Add the derived `_`-prefixed fields to a single raw case.
	 *
	 * @param array<string, mixed> $caseData Raw case.
	 * @param array<string, array<string, mixed>> $caseTypeByKey Case-types by id and slug.
	 * @param DateTimeImmutable $today Reference date for the countdown.
	 *
	 * @return array<string, mixed> The enriched case.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function enrichCase(array $caseData, array $caseTypeByKey, DateTimeImmutable $today): array {
		$endDate = $this->normaliseDate(value: $caseData['endDate'] ?? null);
		$startDate = $this->normaliseDate(value: $caseData['startDate'] ?? null);
		$isOpen = ($endDate === null);

		$caseType = null;
		$caseTypeTitle = '';
		$caseTypeKey = (string)($caseData['caseType'] ?? '');
		if ($caseTypeKey !== '' && isset($caseTypeByKey[$caseTypeKey]) === true) {
			$caseType = $caseTypeByKey[$caseTypeKey];
			$caseTypeTitle = (string)($caseType['title'] ?? '');
		}

		$deadline = $this->resolveCaseDeadline(
			rawDeadline: $caseData['deadline'] ?? null,
			startDate: $startDate,
			caseType: $caseType,
		);

		$daysRemaining = null;
		if ($isOpen === true && $deadline !== null) {
			$deadlineDate = new DateTimeImmutable($deadline);
			$daysRemaining = (int)$today->diff($deadlineDate)->format('%R%a');
		}

		$throughputDays = $this->computeThroughputDays(
			isOpen: $isOpen,
			startDate: $startDate,
			endDate: $endDate,
		);

		$caseData['_isOpen'] = $isOpen;
		$caseData['_startDate'] = $startDate;
		$caseData['_endDate'] = $endDate;
		$caseData['_deadline'] = $deadline;
		$caseData['_daysRemaining'] = $daysRemaining;
		$caseData['_throughputDays'] = $throughputDays;
		$caseData['_caseTypeTitle'] = $caseTypeTitle;

		return $caseData;
	}//end enrichCase()

	/**
	 * Resolve a case deadline, deriving it from the case-type when the case
	 * itself carries none.
	 *
	 * @param mixed $rawDeadline Raw deadline value on the case.
	 * @param string|null $startDate Normalised start date.
	 * @param array<string, mixed>|null $caseType Resolved case-type, if any.
	 *
	 * @return string|null The `Y-m-d` deadline, or null when unresolvable.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function resolveCaseDeadline(mixed $rawDeadline, ?string $startDate, ?array $caseType): ?string {
		$deadline = $this->normaliseDate(value: $rawDeadline);
		if ($deadline === null && $startDate !== null && $caseType !== null) {
			$deadline = $this->deriveDeadline(
				startDate: $startDate,
				processingDeadline: (string)($caseType['processingDeadline'] ?? '')
			);
		}

		return $deadline;
	}//end resolveCaseDeadline()

	/**
	 * Throughput days for a closed case, floored at zero.
	 *
	 * @param bool $isOpen Whether the case is still open.
	 * @param string|null $startDate Normalised start date.
	 * @param string|null $endDate Normalised end date.
	 *
	 * @return int|null Days between start and end, or null when not closed.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function computeThroughputDays(bool $isOpen, ?string $startDate, ?string $endDate): ?int {
		if ($isOpen === true || $startDate === null || $endDate === null) {
			return null;
		}

		$throughputDays = (int)(new DateTimeImmutable($startDate))
			->diff(new DateTimeImmutable($endDate))->format('%R%a');
		if ($throughputDays < 0) {
			return 0;
		}

		return $throughputDays;
	}//end computeThroughputDays()

	/**
	 * Compute a deadline from a start-date + ISO 8601 duration (e.g. `P8W`).
	 *
	 * Falls back to null when the duration can't be parsed.
	 *
	 * @param string $startDate Y-m-d date.
	 * @param string $processingDeadline ISO 8601 duration spec.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function deriveDeadline(string $startDate, string $processingDeadline): ?string {
		if ($processingDeadline === '') {
			return null;
		}

		try {
			$start = new DateTimeImmutable($startDate);
			return $start->add(new DateInterval($processingDeadline))->format('Y-m-d');
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Could not derive deadline from processingDeadline',
				['duration' => $processingDeadline, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end deriveDeadline()

	/**
	 * Trim a date or datetime field to `Y-m-d`; return null for empty/invalid input.
	 *
	 * @param mixed $value Raw date value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function normaliseDate(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}
	}//end normaliseDate()
}//end class
