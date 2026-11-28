<?php

/**
 * Dossiq Inherited Case Deadline.
 *
 * The deadline a case of a child case type takes from its parent's term,
 * once `CaseInheritedDeadlineListener` has told the write is a case: the
 * processing term the type inherits and does not set itself, and the start
 * date plus that term, rolled onto a day the administered calendar works.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The term a child case type inherits, and the deadline it implies.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class InheritedCaseDeadline {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolverInterface         $resolver     The effective blueprint of a case type.
	 * @param LoggerInterface                   $logger       Structured logger.
	 * @param TermijnTimerServiceInterface|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseTypeResolverInterface $resolver,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerServiceInterface $timerService = null,
	) {
	}//end __construct()

	/**
	 * The processing deadline a case type inherits and does not set itself.
	 *
	 * Empty when the type sets its own term (the declarative calculation
	 * already wrote the right deadline), when it has no parent, or when no
	 * ancestor sets one either.
	 *
	 * @param string $caseTypeId The case's case type.
	 *
	 * @return string The inherited ISO 8601 duration, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function inheritedTerm(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return '';
		}

		$chain = $this->resolver->chainFor(caseTypeId: $caseTypeId);
		if (count($chain) < 2) {
			return '';
		}

		if (trim((string)($chain[0]['processingDeadline'] ?? '')) !== '') {
			return '';
		}

		$effective = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);

		return trim((string)($effective['processingDeadline'] ?? ''));
	}//end inheritedTerm()

	/**
	 * The start date plus the term, as a date.
	 *
	 * An empty start date means today, which is what the declarative
	 * `startDate` calculation fills in on create.
	 *
	 * @param string $startDate The case's start date, or the empty string.
	 * @param string $term      An ISO 8601 duration such as P12W.
	 *
	 * @return string|null The `Y-m-d` deadline, or null when either is unusable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function deadlineFrom(string $startDate, string $term): ?string {
		try {
			$start = new DateTimeImmutable('today');
			if (trim($startDate) !== '') {
				$start = new DateTimeImmutable(substr(trim($startDate), 0, 10));
			}

			// A deelzaak inherits the parent case type's term, so it inherits
			// the Awt roll on the day that term ends.
			$end = $start->add(new DateInterval($term));

			return ($this->timerService?->rollTermEndFor(date: $end) ?? $end)->format('Y-m-d');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not derive an inherited case deadline',
				['startDate' => $startDate, 'term' => $term, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end deadlineFrom()
}//end class
