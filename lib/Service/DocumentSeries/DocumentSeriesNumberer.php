<?php

/**
 * Dossiq Document Series Numberer.
 *
 * Issues the next number of a numbered document series: one running number
 * per series, per organisation, per calendar year, printed as
 * `<prefix>-<year>-<six digits>` (decision 167, made generic by decision 182).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DocumentSeries
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
 * @spec openspec/specs/numbered-document-series/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DocumentSeries;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One running number per series, organisation and year, drawn under a lock.
 *
 * WHAT IT KNOWS, AND WHAT IT DOES NOT. It knows series, organisations, years
 * and prefixes. It does not know what a beschikking or any other document
 * is: the caller names the series, and the CASE TYPE configures the prefix
 * under `documentSeries.<series>.prefix`. A case type that configures none
 * gets the caller's default, so a new procedure is a case-type setting and
 * never a new class.
 *
 * WHY OPENREGISTER'S COUNTER AND NOT `x-openregister-generated`. The schema
 * annotation keys a counter on its name and the period only, so two
 * organisations on one instance would share one row of numbers. The same
 * `SequenceService` reserves the number here with the organisation in the
 * scope key: a number is never handed out twice, a rolled-back save spends
 * one and leaves a gap, and a gap is allowed where a repeat is not.
 *
 * WHICH ORGANISATION. The case's own, as OpenRegister stored it. A case that
 * names none numbers in the instance-wide row, which on a single-tenant
 * instance is the only organisation there is.
 *
 * @spec openspec/specs/numbered-document-series/spec.md
 */
class DocumentSeriesNumberer {

	/**
	 * The number as it is printed: prefix, year, six-digit running number.
	 *
	 * @var string
	 */
	public const FORMAT = '%s-%s-%06d';

	/**
	 * The case-type key a series is configured under.
	 *
	 * @var string
	 */
	public const CASE_TYPE_KEY = 'documentSeries';

	/**
	 * OpenRegister's atomic counter, resolved lazily: dossiq runs without it.
	 *
	 * @var string
	 */
	private const SEQUENCE_SERVICE = 'OCA\OpenRegister\Service\SequenceService';

	/**
	 * Prefix of the scope key, so no other counter at register 0 / schema 0
	 * (OpenRegister's named `gen:` counters live there) can share a row.
	 *
	 * @var string
	 */
	private const SCOPE_PREFIX = 'dq-series|';

	/**
	 * The width of OpenRegister's `scope_key` column.
	 *
	 * @var int
	 */
	private const SCOPE_KEY_MAX = 64;

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore    $cases     Reads the case, for its organisation and case type.
	 * @param CaseTypeResolver   $caseTypes Reads the case type, for the series' prefix.
	 * @param ContainerInterface $container Resolves OpenRegister's SequenceService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly CaseTypeResolver $caseTypes,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reserve the next number of a series for this case's organisation.
	 *
	 * @param string                 $caseId        The case the document belongs to.
	 * @param string                 $series        The series, e.g. the document kind's slug.
	 * @param string                 $defaultPrefix The prefix when the case type configures none.
	 * @param DateTimeImmutable|null $moment        The moment of issuing; now when null.
	 *
	 * @return string The number, e.g. `B-2026-000123`.
	 *
	 * @throws RefusedException 503 when no number can be reserved, so the caller
	 *                          does not save a document with an empty number.
	 *
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public function issue(string $caseId, string $series, string $defaultPrefix, ?DateTimeImmutable $moment = null): string {
		$year = ($moment ?? new DateTimeImmutable())->format('Y');
		$case = ($this->cases->loadCase(caseId: $caseId) ?? []);
		$scopeKey = $this->scopeKey(series: $series, organisation: $this->organisationOf(case: $case), year: $year);

		try {
			$sequences = $this->container->get(self::SEQUENCE_SERVICE);
			$next = (int)$sequences->reserveNext(0, 0, $scopeKey);
		} catch (Throwable $failure) {
			$this->logger->error(
				'Dossiq document series: no number could be reserved',
				['exception' => $failure->getMessage(), 'caseId' => $caseId, 'series' => $series],
			);

			throw $this->unavailable(previous: $failure);
		}

		if ($next < 1) {
			throw $this->unavailable(previous: null);
		}

		return sprintf(self::FORMAT, $this->prefixFor(case: $case, series: $series, default: $defaultPrefix), $year, $next);
	}//end issue()

	/**
	 * The prefix the case type configures for this series, or the default.
	 *
	 * A case type that cannot be read falls back to the default rather than
	 * refusing: the number itself is what must be unique, and the counter
	 * already guarantees that whatever is printed in front of it.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param string               $series  The series.
	 * @param string               $default The caller's default.
	 *
	 * @return string The prefix.
	 */
	private function prefixFor(array $case, string $series, string $default): string {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return $default;
		}

		try {
			$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'Dossiq document series: the case type could not be read, so the default prefix is used',
				['exception' => $failure->getMessage(), 'series' => $series],
			);

			return $default;
		}

		$configured = ($caseType[self::CASE_TYPE_KEY][$series]['prefix'] ?? null);
		if (is_string($configured) === false || trim($configured) === '') {
			return $default;
		}

		return trim($configured);
	}//end prefixFor()

	/**
	 * The refusal for a number that could not be reserved: indeterminate, so a retry is right.
	 *
	 * @param Throwable|null $previous The failure underneath, when there was one.
	 *
	 * @return RefusedException A 503-carrying refusal.
	 */
	private function unavailable(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'document-number-unavailable',
			sentence: 'The document could not be given a number, so it was not created. Try again in a moment.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unavailable()

	/**
	 * The organisation OpenRegister stored on the case, or '' when it names none.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The organisation id.
	 */
	private function organisationOf(array $case): string {
		$self = ($case['@self'] ?? []);
		if (is_array($self) === false) {
			return '';
		}

		return trim((string)($self['organisation'] ?? ''));
	}//end organisationOf()

	/**
	 * The counter row this series, organisation and year draws from.
	 *
	 * Too long for the column, the series and organisation are hashed
	 * together rather than cut: two ids sharing a prefix would otherwise
	 * share a counter.
	 *
	 * @param string $series       The series.
	 * @param string $organisation The organisation id.
	 * @param string $year         The four-digit year.
	 *
	 * @return string The scope key, at most 64 characters.
	 */
	private function scopeKey(string $series, string $organisation, string $year): string {
		$key = self::SCOPE_PREFIX.$series.'|'.$organisation.'|'.$year;
		if (strlen($key) <= self::SCOPE_KEY_MAX) {
			return $key;
		}

		return self::SCOPE_PREFIX.substr(hash('sha256', $series.'|'.$organisation), 0, 40).'|'.$year;
	}//end scopeKey()
}//end class
