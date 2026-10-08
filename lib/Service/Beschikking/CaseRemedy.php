<?php

/**
 * The remedy one case's decisions carry, resolved from its case type.
 *
 * {@see RemedyClauseDeclaration} reads a case type. This resolves the case type
 * from a case, so the decision surface asks one collaborator one question
 * instead of carrying a store, a resolver and a declaration reader of its own.
 *
 * 🔑 THE CLAUSE AND THE CLOCK COME FROM ONE READ. The sentence printed on the
 * besluit and the term instance bound when it is sent are the same declaration,
 * so a decision cannot say forty-two days and start a clock of six weeks.
 *
 * 🔴 A CASE TYPE THAT DECLARES NO REMEDY GETS NO CLAUSE AND NO CLOCK. Printing
 * a default clause would put a term nobody chose onto a decision somebody has
 * to act on, and starting a default clock would make the case answer "still
 * open to bezwaar" on a term the case type never declared. A case type that
 * could not be READ is a different answer, and it is thrown, not swallowed.
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Answers the clause a case's decisions print and binds the clock they start.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */
class CaseRemedy {

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore         $store       Reads the case the decision is about.
	 * @param CaseTypeResolver        $caseTypes   The effective case type, parents included.
	 * @param RemedyClauseDeclaration $declaration What that case type declares.
	 * @param CaseTermsService        $terms       Binds the clock, on the administered calendar.
	 * @param LoggerInterface         $logger      Says why a decision carries no clause.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseTypeResolver $caseTypes,
		private readonly RemedyClauseDeclaration $declaration,
		private readonly CaseTermsService $terms,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The clause this case's decisions print, or ''.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The clause, '' when the case type declares no remedy.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function clauseFor(string $caseId): string {
		return $this->declaration->clauseFor(caseType: $this->caseTypeOf(caseId: $caseId));
	}//end clauseFor()

	/**
	 * How many days this case's remedy term runs, or 0.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return int The term in days.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-sending-a-decision-starts-the-remedy-term-req-dec-04
	 */
	public function termDaysFor(string $caseId): int {
		return $this->declaration->termDaysFor(caseType: $this->caseTypeOf(caseId: $caseId));
	}//end termDaysFor()

	/**
	 * Bind the remedy clock, started by the decision going out.
	 *
	 * A term instance of its own kind, so the case answers "is this decision
	 * still open to bezwaar" from a clock rather than from arithmetic somebody
	 * does in their head against a date on a document.
	 *
	 * @param string                 $caseId     The case UUID.
	 * @param string                 $decisionId The decision that was sent.
	 * @param DateTimeImmutable|null $sentOn     When it went out (default now).
	 *
	 * @return array<string, mixed>|null The bound instance, or null when nothing is declared.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-sending-a-decision-starts-the-remedy-term-req-dec-04
	 */
	public function bindTerm(string $caseId, string $decisionId, ?DateTimeImmutable $sentOn = null): ?array {
		$days = $this->termDaysFor(caseId: $caseId);
		if ($caseId === '' || $days <= 0) {
			return null;
		}

		return $this->terms->bindLeadTime(
			caseId: $caseId,
			kind: TermKind::REMEDY,
			days: $days,
			start: ($sentOn ?? new DateTimeImmutable()),
			// The decision the clock belongs to. A case can carry several, and
			// a remedy term that named none would answer "still open" without
			// saying open against what.
			extra: ['decision' => $decisionId],
		);
	}//end bindTerm()

	/**
	 * The effective case type of a case, or an empty array.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The effective case type.
	 */
	private function caseTypeOf(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		$case = $this->store->loadCase(caseId: $caseId);
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			$this->logger->warning(
				'Dossiq remedy: the case names no case type, so its decisions carry no clause',
				['caseId' => $caseId],
			);

			return [];
		}

		// NOT CAUGHT. A case type that exists and could not be read is not a
		// case type that declares no remedy: swallowing it would print a
		// besluit with no bezwaarclausule, or send one whose clock nobody
		// bound, on the strength of a failed read. The failure travels to the
		// caller, which reads the remedy BEFORE it composes or sends anything.
		return $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
	}//end caseTypeOf()
}//end class
