<?php

/**
 * Tests for the English remedy clause and the publication warnings of a switched-off remedy.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\StoredArray;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The English clause, and a remedy a case type switched off.
 */
#[CoversClass(RemedyClauseDeclaration::class)]
#[UsesClass(LanguageMapText::class)]
#[UsesClass(StoredArray::class)]
class RemedyClauseEnglishTest extends TestCase {

	/**
	 * A case type declaring a remedy.
	 *
	 * @param array<string, mixed> $remedy Fields over a complete objection declaration.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private function caseType(array $remedy = []): array {
		return [
			'title' => 'Omgevingsvergunning',
			RemedyClauseDeclaration::DECLARATION => array_merge(['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'the board'], $remedy),
		];
	}//end caseType()

	/**
	 * The declaration under test.
	 *
	 * @return RemedyClauseDeclaration The declaration.
	 */
	private function declaration(): RemedyClauseDeclaration {
		return new RemedyClauseDeclaration(l10n: new SourceL10n());
	}//end declaration()

	/**
	 * A known kind prints its English verb with the term and the body.
	 *
	 * @return void
	 */
	public function testTheEnglishClauseUsesTheKindsVerb(): void {
		$this->assertSame(
			'You can lodge an objection against this decision. Do that within 42 days, with the board.',
			$this->declaration()->clauseInEnglishFor(caseType: $this->caseType())
		);
		$this->assertSame(
			'You can appeal against this decision. Do that within 6 days, with the court.',
			$this->declaration()->clauseInEnglishFor(caseType: $this->caseType(remedy: ['kind' => 'Beroep', 'termDays' => 6, 'body' => 'the court']))
		);
	}//end testTheEnglishClauseUsesTheKindsVerb()

	/**
	 * An unknown kind is still printed, as a submission of that kind.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsSubmittedByName(): void {
		$this->assertSame(
			'You can submit a herziening against this decision. Do that within 42 days, with the board.',
			$this->declaration()->clauseInEnglishFor(caseType: $this->caseType(remedy: ['kind' => 'herziening']))
		);
	}//end testAnUnknownKindIsSubmittedByName()

	/**
	 * A switched-off or half-filled remedy prints no English clause.
	 *
	 * @return void
	 */
	public function testASwitchedOffOrIncompleteRemedyPrintsNoEnglishClause(): void {
		$this->assertSame('', $this->declaration()->clauseInEnglishFor(caseType: $this->caseType(remedy: ['enabled' => false])));
		$this->assertSame('', $this->declaration()->clauseInEnglishFor(caseType: $this->caseType(remedy: ['body' => ''])));
		$this->assertSame('', $this->declaration()->clauseInEnglishFor(caseType: ['title' => 'Melding']));
	}//end testASwitchedOffOrIncompleteRemedyPrintsNoEnglishClause()

	/**
	 * A remedy the case type switched off on purpose raises no publication warning.
	 *
	 * @return void
	 */
	public function testASwitchedOffRemedyRaisesNoWarning(): void {
		$declaration = $this->declaration();

		$this->assertSame([], $declaration->publicationWarnings(caseType: $this->caseType(remedy: ['enabled' => false, 'termDays' => 0])));
		$this->assertSame(0, $declaration->termDaysFor(caseType: $this->caseType(remedy: ['enabled' => false])));
		$this->assertSame(42, $declaration->termDaysFor(caseType: $this->caseType()));
	}//end testASwitchedOffRemedyRaisesNoWarning()

	/**
	 * A case type without a title is named generically in the warning.
	 *
	 * @return void
	 */
	public function testAnUntitledCaseTypeIsNamedGenerically(): void {
		$warnings = $this->declaration()->publicationWarnings(caseType: [RemedyClauseDeclaration::DECLARATION => ['kind' => 'bezwaar']]);

		$this->assertCount(1, $warnings);
		$this->assertStringStartsWith('The decisions of this case type declare no remedy', $warnings[0]);
	}//end testAnUntitledCaseTypeIsNamedGenerically()
}//end class
