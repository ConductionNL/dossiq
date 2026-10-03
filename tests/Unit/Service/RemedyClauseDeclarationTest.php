<?php

/**
 * A besluit carries its bezwaarclausule, from the case type and not a template.
 *
 * 🔴 THE FAILURE THIS GUARDS IS A CLAUSE THAT IS QUIETLY WRONG. A decision that
 * prints six weeks because that is what the template said, on a case type whose
 * term is four, reads correct to everyone who did not check the case type. The
 * person it is wrong for is the applicant, who finds out when their bezwaar is
 * refused as too late. So the assertions are that the printed sentence carries
 * the DECLARED kind, the DECLARED term and the DECLARED body, and that two case
 * types sharing a template print different ones.
 *
 * 🔑 AN ABSENT DECLARATION PRINTS NOTHING AND WARNS. Filling in a default
 * clause would put a term nobody chose onto a decision somebody has to act on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use PHPUnit\Framework\TestCase;

class RemedyClauseDeclarationTest extends TestCase {

	/**
	 * A case type declaring one remedy.
	 *
	 * @param string $kind The remedy kind.
	 * @param int $days The term in days.
	 * @param string $body Who it is lodged with.
	 *
	 * @return array<string, mixed> The case type row.
	 */
	private function caseType(string $kind = 'bezwaar', int $days = 42, string $body = 'het college'): array {
		return [
			'title' => 'Omgevingsvergunning',
			RemedyClauseDeclaration::DECLARATION => [
				'kind' => $kind,
				'termDays' => $days,
				'body' => $body,
			],
		];
	}//end caseType()

	/**
	 * The clause prints the declared kind, term and body.
	 *
	 * @return void
	 */
	public function testTheClauseCarriesTheDeclaredKindTermAndBody(): void {
		$clause = (new RemedyClauseDeclaration())->clauseFor(caseType: $this->caseType());

		self::assertStringContainsString(needle: 'bezwaar', haystack: $clause);
		self::assertStringContainsString(needle: '42', haystack: $clause);
		self::assertStringContainsString(needle: 'het college', haystack: $clause);
	}//end testTheClauseCarriesTheDeclaredKindTermAndBody()

	/**
	 * Two case types sharing a template print their own terms.
	 *
	 * This is the whole point of the declaration living on the case type: a
	 * change in the law is one configuration change and not forty templates.
	 *
	 * @return void
	 */
	public function testTwoCaseTypesSharingATemplatePrintTheirOwnTerm(): void {
		$declaration = new RemedyClauseDeclaration();

		$six = $declaration->clauseFor(caseType: $this->caseType(days: 42));
		$four = $declaration->clauseFor(caseType: $this->caseType(days: 28));

		self::assertStringContainsString(needle: '42 dagen', haystack: $six);
		self::assertStringContainsString(needle: '28 dagen', haystack: $four);
		self::assertNotSame(expected: $six, actual: $four);
	}//end testTwoCaseTypesSharingATemplatePrintTheirOwnTerm()

	/**
	 * A remedy this app has no sentence for is printed, not refused.
	 *
	 * A bestuursorgaan with a remedy nobody here anticipated should still get
	 * its clause onto the decision. Refusing would take the whole decision down
	 * over a word.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsStillPrinted(): void {
		$clause = (new RemedyClauseDeclaration())->clauseFor(
			caseType: $this->caseType(kind: 'administratief beroep'),
		);

		self::assertStringContainsString(needle: 'administratief beroep', haystack: $clause);
	}//end testAnUnknownKindIsStillPrinted()

	/**
	 * A case type declaring nothing prints nothing, and warns at publication.
	 *
	 * @return void
	 */
	public function testAnAbsentDeclarationPrintsNothingAndWarns(): void {
		$declaration = new RemedyClauseDeclaration();
		$bare = ['title' => 'Melding openbare ruimte'];

		self::assertFalse(condition: $declaration->isDeclared(caseType: $bare));
		// Not a default clause. A term nobody chose, printed on a decision
		// somebody has to act on, would be worse than no clause at all.
		self::assertSame(expected: '', actual: $declaration->clauseFor(caseType: $bare));
		$warnings = $declaration->publicationWarnings(caseType: $bare);
		self::assertCount(expectedCount: 1, haystack: $warnings);
		self::assertStringContainsString(needle: 'Melding openbare ruimte', haystack: $warnings[0]);
	}//end testAnAbsentDeclarationPrintsNothingAndWarns()

	/**
	 * A half-filled declaration is not a declaration.
	 *
	 * A kind with no term would print "within 0 days", which is a clause that
	 * is worse than the missing one it replaced.
	 *
	 * @return void
	 */
	public function testAHalfFilledDeclarationWarnsAndPrintsNothing(): void {
		$declaration = new RemedyClauseDeclaration();
		$half = $this->caseType(days: 0);

		self::assertFalse(condition: $declaration->isDeclared(caseType: $half));
		self::assertSame(expected: '', actual: $declaration->clauseFor(caseType: $half));
		self::assertNotSame(expected: [], actual: $declaration->publicationWarnings(caseType: $half));
	}//end testAHalfFilledDeclarationWarnsAndPrintsNothing()

	/**
	 * A case type that declares no remedy on purpose is not warned about.
	 *
	 * @return void
	 */
	public function testARemedyTurnedOffOnPurposeDoesNotWarn(): void {
		$declaration = new RemedyClauseDeclaration();
		$off = ['title' => 'Interne notitie', RemedyClauseDeclaration::DECLARATION => ['enabled' => false]];

		self::assertSame(expected: [], actual: $declaration->publicationWarnings(caseType: $off));
		self::assertSame(expected: 0, actual: $declaration->termDaysFor(caseType: $off));
	}//end testARemedyTurnedOffOnPurposeDoesNotWarn()

	/**
	 * The term the clause names is the term the clock is bound from.
	 *
	 * One reader for both, so the printed sentence and the bound term cannot
	 * disagree about the same decision.
	 *
	 * @return void
	 */
	public function testTheTermIsReadFromTheSameDeclarationTheClauseIs(): void {
		$declaration = new RemedyClauseDeclaration();

		self::assertSame(expected: 42, actual: $declaration->termDaysFor(caseType: $this->caseType()));
	}//end testTheTermIsReadFromTheSameDeclarationTheClauseIs()

	/**
	 * A declaration stored as a JSON string reads the same as an array.
	 *
	 * @return void
	 */
	public function testAJsonEncodedDeclarationReadsTheSame(): void {
		$encoded = [
			RemedyClauseDeclaration::DECLARATION => json_encode(
				['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college']
			),
		];

		self::assertTrue(condition: (new RemedyClauseDeclaration())->isDeclared(caseType: $encoded));
	}//end testAJsonEncodedDeclarationReadsTheSame()

	/**
	 * The English clause says the same three things.
	 *
	 * @return void
	 */
	public function testTheEnglishClauseCarriesTheSameThreeFacts(): void {
		$clause = (new RemedyClauseDeclaration())->clauseInEnglishFor(caseType: $this->caseType());

		self::assertStringContainsString(needle: 'objection', haystack: $clause);
		self::assertStringContainsString(needle: '42', haystack: $clause);
		self::assertStringContainsString(needle: 'het college', haystack: $clause);
	}//end testTheEnglishClauseCarriesTheSameThreeFacts()
}//end class
