<?php

/**
 * The two clocks a case runs on, kept apart.
 *
 * The AVG says delete personal data when its lawful purpose ends. The
 * Archiefwet says keep the record for the period the selectielijst sets. The
 * two rules disagree on purpose, and a product with one date field answers
 * whichever question it was built for and gets the other one wrong in silence.
 *
 * So a case carries both dates, each labelled and each naming the rule that
 * produced it, and neither is derived from the other.
 * {@see \OCA\Dossiq\Service\Archival\ArchivalNominationDeriver} owns the
 * archive side and writes `archiveActionDate`; this class never writes it. The
 * lawful-purpose side counts from the case type's own retention and is written
 * to `lawfulPurposeEndDate`, which nothing else reads.
 *
 * Where the two disagree, the case is not destroyed and the disagreement is
 * reported for a person to decide. That mirrors OpenRegister's
 * `RetentionClockService`, which refuses the destroy on the same condition
 * (openregister#3724, `delete-window-and-recorded-destruction`).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the lawful-purpose clock and the archive clock as two separate values.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
class RetentionClocks {

	/**
	 * The case-type property that states the lawful-purpose retention.
	 *
	 * In whole months, counted from the day the case closed, because the AVG
	 * period runs from the end of the processing and not from the intake.
	 *
	 * @var string
	 */
	public const CASE_TYPE_RETENTION = 'lawfulPurposeRetention';

	/**
	 * The case property carrying the lawful-purpose end date.
	 *
	 * @var string
	 */
	public const CASE_FIELD = 'lawfulPurposeEndDate';

	/**
	 * The case property carrying the archive action date.
	 *
	 * Named here so the separation is checkable: this class reads the key and
	 * never writes it.
	 *
	 * @var string
	 */
	public const ARCHIVE_FIELD = 'archiveActionDate';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $caseTypes Resolves a case type with its parents merged in.
	 * @param IL10N $l10n Translation service, for the labels and the rules.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly CaseTypeResolver $caseTypes,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Both clocks of one case, each labelled and each naming its rule.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array{lawfulPurpose: array<string, mixed>, archive: array<string, mixed>, disagree: bool}
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function clocksFor(array $case): array {
		$lawful = $this->lawfulPurposeClock(case: $case);
		$archive = $this->archiveClock(case: $case);

		return [
			'lawfulPurpose' => $lawful,
			'archive' => $archive,
			'disagree' => $this->disagree(lawful: $lawful, archive: $archive),
		];
	}//end clocksFor()

	/**
	 * The lawful-purpose end date this case type asks for, or null.
	 *
	 * Counted from the case's own end date, never from the archive date. A
	 * case still in hand has no lawful-purpose end date, because the purpose
	 * has not ended yet.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string|null The date as `Y-m-d`, or null when no rule applies.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function lawfulPurposeEndDate(array $case): ?string {
		$closed = $this->date(value: (string)($case['endDate'] ?? ''));
		if ($closed === null) {
			return null;
		}

		$months = $this->retentionMonths(case: $case);
		if ($months === null) {
			return null;
		}

		try {
			$end = $closed->add(new DateInterval('P' . (string)$months . 'M'));

			// A month is not thirty days. Six months from 31 March is 30
			// September, and PHP's own addition rolls it forward to 1 October
			// because September has no thirty-first. A retention date that
			// silently lands in the next month is the kind of error nobody
			// looks for, so the roll-over is clamped back to the month end.
			if ((int)$end->format('d') !== (int)$closed->format('d')) {
				$end = $end->modify('last day of previous month');
			}

			return $end->format('Y-m-d');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not count the lawful-purpose clock: ' . $e->getMessage()
			);
			return null;
		}
	}//end lawfulPurposeEndDate()

	/**
	 * The lawful-purpose clock, as the API publishes it.
	 *
	 * The stored value wins over the derivation, so an FG who set the date by
	 * hand keeps it. The derivation fills the gap for a case nobody has looked
	 * at yet.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array<string, mixed> The clock.
	 */
	private function lawfulPurposeClock(array $case): array {
		$stored = trim((string)($case[self::CASE_FIELD] ?? ''));
		$date = ($stored !== '' ? substr($stored, 0, 10) : $this->lawfulPurposeEndDate(case: $case));

		if ($date === null) {
			return [
				'date' => null,
				'label' => $this->l10n->t('Lawful purpose ends'),
				'rule' => $this->l10n->t('This case type states no retention for personal data.'),
			];
		}

		return [
			'date' => $date,
			'label' => $this->l10n->t('Lawful purpose ends'),
			'rule' => $this->l10n->t('The case type keeps personal data for %1$s months after the case closes.', [(string)$this->retentionMonths(case: $case)]),
		];
	}//end lawfulPurposeClock()

	/**
	 * The archive clock, as the API publishes it.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array<string, mixed> The clock.
	 */
	private function archiveClock(array $case): array {
		$raw = trim((string)($case[self::ARCHIVE_FIELD] ?? ''));
		if ($raw === '') {
			return [
				'date' => null,
				'label' => $this->l10n->t('Archive action due'),
				'rule' => $this->l10n->t('No archive action date has been derived for this case.'),
			];
		}

		return [
			'date' => substr($raw, 0, 10),
			'label' => $this->l10n->t('Archive action due'),
			'rule' => $this->l10n->t('The selectielijst sets this date through the result type of the case.'),
		];
	}//end archiveClock()

	/**
	 * Whether the two clocks disagree about destroying this case now.
	 *
	 * They disagree when the lawful purpose has already ended and the archive
	 * period has not. Nothing is destroyed on a disagreement.
	 *
	 * @param array<string, mixed> $lawful The lawful-purpose clock.
	 * @param array<string, mixed> $archive The archive clock.
	 *
	 * @return bool True when a person has to decide.
	 */
	private function disagree(array $lawful, array $archive): bool {
		$lawfulDate = $this->date(value: (string)($lawful['date'] ?? ''));
		$archiveDate = $this->date(value: (string)($archive['date'] ?? ''));
		if ($lawfulDate === null || $archiveDate === null) {
			return false;
		}

		$today = new DateTimeImmutable('today');

		return ($lawfulDate <= $today && $archiveDate > $today);
	}//end disagree()

	/**
	 * The retention this case type states, in whole months.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return int|null The months, or null when the case type states none.
	 */
	private function retentionMonths(array $case): ?int {
		$caseTypeId = $this->caseTypeId(case: $case);
		if ($caseTypeId === '') {
			return null;
		}

		try {
			$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: could not read the case type for the lawful-purpose clock: ' . $e->getMessage()
			);
			return null;
		}

		$months = ($caseType[self::CASE_TYPE_RETENTION] ?? null);
		if (is_numeric($months) === false || (int)$months < 1) {
			return null;
		}

		return (int)$months;
	}//end retentionMonths()

	/**
	 * The case type reference of a case, flattened to an identifier.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The case type id, or an empty string.
	 */
	private function caseTypeId(array $case): string {
		$value = ($case['caseType'] ?? '');
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return trim((string)$value);
	}//end caseTypeId()

	/**
	 * One date at midnight, or null when the string carries none.
	 *
	 * @param string $value The raw value.
	 *
	 * @return DateTimeImmutable|null The date, or null.
	 */
	private function date(string $value): ?DateTimeImmutable {
		$raw = trim($value);
		if ($raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($raw, 0, 10));
		} catch (Throwable $e) {
			return null;
		}
	}//end date()
}//end class
