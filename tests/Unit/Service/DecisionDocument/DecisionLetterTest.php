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
use OCA\Dossiq\Tests\Support\InMemoryRegister;
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
			static fn (string $caseId, string $purpose): array => $purpose === 'decision'
				? ['templateSlug' => 'woo-besluit', 'documentType' => 'Besluit', 'title' => 'Woo-besluit']
				: []
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

		$this->letters = new DecisionLetter(settingsService: $settings, drafts: $drafts);
	}//end setUp()

	public function testTheLetterIsTheNewestDocumentOfTheDeclaredTypeOnTheCase(): void {
		$found = $this->letters->forCase(caseId: self::CASE_ID, purpose: 'decision');

		$this->assertSame(['title' => 'Woo-besluit', 'documentType' => 'Besluit'], $found['declaration']);
		$this->assertSame('io-by-id', $found['document']['id'], 'matched by id, newer than the one matched by name; another case\'s letter is not seen');
	}//end testTheLetterIsTheNewestDocumentOfTheDeclaredTypeOnTheCase()

	public function testAPurposeTheCaseTypeDoesNotDeclareHasNoLetter(): void {
		$this->assertSame(['declaration' => null, 'document' => null], $this->letters->forCase(caseId: self::CASE_ID, purpose: 'inventory'));
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
}//end class
