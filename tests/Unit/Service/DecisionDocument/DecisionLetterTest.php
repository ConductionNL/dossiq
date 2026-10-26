<?php

/**
 * Decision Letter Test
 *
 * The letter of a decision is the case's newest document of the type its case
 * type declares for the purpose, matched by catalogue id or by name. It is
 * linked to a decision only when it is a document of the same case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use InvalidArgumentException;
use OCA\Dossiq\Service\DecisionDocument\DecisionLetter;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionLetter
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentTypeResolver
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DecisionLetterTest extends TestCase {

	private const CASE_ID = 'case-a';

	private InMemoryRegister $register;

	private DecisionLetter $letters;

	/**
	 * The settings the letters read the register through.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * The case type's draft declarations.
	 *
	 * @var CaseDocumentDrafts
	 */
	private CaseDocumentDrafts $drafts;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
				'dossier_besluitinformatieobject_schema' => 'besluitinformatieobject',
				'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
			][$key] ?? $default
		);

		$drafts = $this->createMock(CaseDocumentDrafts::class);
		$drafts->method('declarationForCase')->willReturnCallback(
			static function (string $caseId, string $purpose): array {
				if ($purpose !== 'decision') {
					return [];
				}

				return [
					'templateSlug' => 'woo-besluit',
					'documentType' => 'Besluit',
					'title' => 'Woo-besluit',
					'signing' => ['nl' => 'Via LibreSign, na de paraaf', 'en' => 'Through LibreSign, after the initials'],
					'delivery' => 'Berichtenbox van MijnOverheid',
				];
			}
		);

		$this->register->seed('informatieobjecttype', 'iot-besluit', ['description' => 'Besluit']);
		$this->register->seed('informatieobject', 'io-by-name', ['title' => 'Besluit (oud)', 'informatieobjecttype' => 'Besluit', 'creatiedatum' => '2026-10-01']);
		$this->register->seed('informatieobject', 'io-by-id', ['title' => 'Besluit Woo-verzoek.docx', 'informatieobjecttype' => 'iot-besluit', 'creatiedatum' => '2026-10-28']);
		$this->register->seed('informatieobject', 'io-other-type', ['title' => 'Inventarislijst', 'informatieobjecttype' => 'Inventarislijst', 'creatiedatum' => '2026-10-30']);
		$this->register->seed('informatieobject', 'io-foreign', ['title' => 'Besluit van een andere zaak', 'informatieobjecttype' => 'iot-besluit', 'creatiedatum' => '2026-10-31']);
		$this->register->seed('zaakinformatieobject', 'j-1', ['case' => self::CASE_ID, 'informatieobject' => 'io-by-name']);
		$this->register->seed('zaakinformatieobject', 'j-2', ['case' => self::CASE_ID, 'informatieobject' => 'io-by-id']);
		$this->register->seed('zaakinformatieobject', 'j-3', ['case' => self::CASE_ID, 'informatieobject' => 'io-other-type']);
		$this->register->seed('zaakinformatieobject', 'j-4', ['case' => 'case-b', 'informatieobject' => 'io-foreign']);

		$this->settings = $settings;
		$this->drafts = $drafts;
		$this->letters = new DecisionLetter(settingsService: $settings, drafts: $drafts);
	}//end setUp()

	public function testTheLetterIsTheNewestDocumentOfTheDeclaredTypeOnTheCase(): void {
		$found = $this->letters->forCase(caseId: self::CASE_ID, purpose: 'decision');

		$this->assertSame(
			expected: [
				'title' => 'Woo-besluit',
				'documentType' => 'Besluit',
				'templateSlug' => 'woo-besluit',
				'format' => '',
				'signing' => 'Via LibreSign, na de paraaf',
				'delivery' => 'Berichtenbox van MijnOverheid',
			],
			actual: $found['declaration']
		);
		$this->assertFalse(condition: $found['empty']);
		$this->assertSame('io-by-id', $found['document']['id'], 'matched by id, newer than the one matched by name; another case\'s letter is not seen');
	}//end testTheLetterIsTheNewestDocumentOfTheDeclaredTypeOnTheCase()

	/**
	 * The declared signing route reads in the reader's language.
	 *
	 * @return void
	 */
	public function testTheSigningRouteReadsInTheReadersLanguage(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$english = new DecisionLetter(
			settingsService: $this->settings,
			drafts: $this->drafts,
			text: new TranslatedText(l10n: $l10n)
		);

		$declaration = $english->forCase(caseId: self::CASE_ID, purpose: 'decision')['declaration'];

		$this->assertSame(expected: 'Through LibreSign, after the initials', actual: $declaration['signing']);
		$this->assertSame(expected: 'Berichtenbox van MijnOverheid', actual: $declaration['delivery'], message: 'a plain string stays as declared');
	}//end testTheSigningRouteReadsInTheReadersLanguage()

	/**
	 * A letter the case type declares but the case does not hold yet reads empty.
	 *
	 * @return void
	 */
	public function testADeclaredLetterNotYetDraftedReadsEmpty(): void {
		$found = $this->letters->forCase(caseId: 'case-without-documents', purpose: 'decision');

		$this->assertNull(actual: $found['document']);
		$this->assertTrue(condition: $found['empty']);
		$this->assertSame(expected: 'woo-besluit', actual: $found['declaration']['templateSlug']);
	}//end testADeclaredLetterNotYetDraftedReadsEmpty()

	public function testAPurposeTheCaseTypeDoesNotDeclareHasNoLetter(): void {
		$this->assertSame(
			expected: ['declaration' => null, 'document' => null, 'empty' => true],
			actual: $this->letters->forCase(caseId: self::CASE_ID, purpose: 'inventory')
		);
	}//end testAPurposeTheCaseTypeDoesNotDeclareHasNoLetter()

	public function testADocumentOfAnotherCaseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The letter is not a document of this case.');

		$this->letters->assertOnCase(caseId: self::CASE_ID, documentId: 'io-foreign');
	}//end testADocumentOfAnotherCaseIsRefused()

	public function testADocumentOfTheCaseIsLinkedToTheDecision(): void {
		$this->letters->assertOnCase(caseId: self::CASE_ID, documentId: 'io-by-id');
		$this->letters->link(decisionId: 'decision-1', documentId: 'io-by-id');

		$links = array_values($this->register->all('besluitinformatieobject'));
		$this->assertCount(1, $links);
		$this->assertSame('decision-1', $links[0]['decision']);
		$this->assertSame('io-by-id', $links[0]['informatieobject']);
		$this->assertNotSame('', $links[0]['registrationDate']);
	}//end testADocumentOfTheCaseIsLinkedToTheDecision()

	public function testWithoutAChosenLetterTheDraftedOneIsLinked(): void {
		$this->letters->assertOnCase(caseId: self::CASE_ID, documentId: '');

		$linked = $this->letters->linkDecision(result: ['decisionId' => 'decision-2', 'draft' => ['status' => 'drafted', 'informatieobject' => 'io-by-id']], letter: '');

		$this->assertSame('io-by-id', $linked);
		$this->assertSame('decision-2', array_values($this->register->all('besluitinformatieobject'))[0]['decision']);
	}//end testWithoutAChosenLetterTheDraftedOneIsLinked()
}//end class
