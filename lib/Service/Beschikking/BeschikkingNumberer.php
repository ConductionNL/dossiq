<?php

/**
 * Dossiq Beschikking Numberer.
 *
 * Issues the user-visible number a beschikking carries, `B-2026-000123`: one
 * running number per organisation per calendar year (decision 167,
 * Q-dossiq-L1-2).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One running number per organisation per year, drawn under a lock.
 *
 * WHY OPENREGISTER'S COUNTER AND NOT `x-openregister-generated`. The schema
 * annotation keys a counter on its name and the period, and nothing else, so
 * two organisations on one instance would share one row of numbers. A
 * gemeente numbers its own decisions; a decision of the neighbouring gemeente
 * taking number 124 out of its year is a gap nobody can explain on paper.
 * The same `SequenceService` the annotation uses reserves the number here,
 * with the organisation in the scope key, so the reservation keeps the
 * annotation's guarantee: a number is never handed out twice, a rolled-back
 * compose spends one and leaves a gap, and a gap is allowed where a repeat is
 * not.
 *
 * WHICH ORGANISATION. The case's own, as OpenRegister stored it. A case that
 * names none (a single-tenant instance, or a case filed before organisations
 * existed) numbers in the instance-wide row, which on such an instance is the
 * only organisation there is.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingNumberer {

	/**
	 * The number as it is printed: year, then the six-digit running number.
	 *
	 * @var string
	 */
	public const FORMAT = 'B-%s-%06d';

	/**
	 * OpenRegister's atomic counter, resolved lazily: dossiq runs without it.
	 *
	 * @var string
	 */
	private const SEQUENCE_SERVICE = 'OCA\OpenRegister\Service\SequenceService';

	/**
	 * Prefix of the scope key, so no other counter at register 0 / schema 0
	 * (OpenRegister's named `gen:` counters live there) can ever share a row.
	 *
	 * @var string
	 */
	private const SCOPE_PREFIX = 'dq-beschikking|';

	/**
	 * The width of OpenRegister's `scope_key` column.
	 *
	 * @var int
	 */
	private const SCOPE_KEY_MAX = 64;

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore    $cases     Reads the case, for its organisation.
	 * @param ContainerInterface $container Resolves OpenRegister's SequenceService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reserve the next beschikking number for this case's organisation.
	 *
	 * @param string                 $caseId The case the beschikking belongs to.
	 * @param DateTimeImmutable|null $moment The moment of composing; now when null.
	 *
	 * @return string The number, e.g. `B-2026-000123`.
	 *
	 * @throws RefusedException 503 when no number can be reserved. A beschikking
	 *                          without a number would reach the Berichtenbox and
	 *                          its audit packet with an empty reference, so it is
	 *                          not composed at all.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function issue(string $caseId, ?DateTimeImmutable $moment = null): string {
		$year = ($moment ?? new DateTimeImmutable())->format('Y');
		$scopeKey = $this->scopeKey(organisation: $this->organisationOf(caseId: $caseId), year: $year);

		try {
			$sequences = $this->container->get(self::SEQUENCE_SERVICE);
			$next = (int)$sequences->reserveNext(0, 0, $scopeKey);
		} catch (Throwable $failure) {
			$this->logger->error(
				'Dossiq beschikking: no number could be reserved, so nothing was composed',
				['exception' => $failure->getMessage(), 'caseId' => $caseId],
			);

			throw $this->unavailable(previous: $failure);
		}

		if ($next < 1) {
			throw $this->unavailable(previous: null);
		}

		return sprintf(self::FORMAT, $year, $next);
	}//end issue()

	/**
	 * The refusal for a number that could not be reserved: indeterminate, so a retry is right.
	 *
	 * @param Throwable|null $previous The failure underneath, when there was one.
	 *
	 * @return RefusedException A 503-carrying refusal.
	 */
	private function unavailable(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'beschikking-number-unavailable',
			sentence: 'The beschikking could not be given a number, so it was not created. Try again in a moment.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unavailable()

	/**
	 * The organisation OpenRegister stored on the case, or '' when it names none.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The organisation id.
	 */
	private function organisationOf(string $caseId): string {
		$case = $this->cases->loadCase(caseId: $caseId);
		if ($case === null) {
			return '';
		}

		$self = ($case['@self'] ?? []);
		if (is_array($self) === false) {
			return '';
		}

		return trim((string)($self['organisation'] ?? ''));
	}//end organisationOf()

	/**
	 * The counter row this organisation's year draws from.
	 *
	 * An organisation id too long for the column is hashed rather than cut:
	 * two ids sharing a prefix would otherwise share a counter.
	 *
	 * @param string $organisation The organisation id.
	 * @param string $year         The four-digit year.
	 *
	 * @return string The scope key, at most 64 characters.
	 */
	private function scopeKey(string $organisation, string $year): string {
		$key = self::SCOPE_PREFIX.$organisation.'|'.$year;
		if (strlen($key) <= self::SCOPE_KEY_MAX) {
			return $key;
		}

		return self::SCOPE_PREFIX.substr(hash('sha256', $organisation), 0, 40).'|'.$year;
	}//end scopeKey()
}//end class
