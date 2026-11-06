<?php

/**
 * CaseCompleteness tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseCompleteness;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use PHPUnit\Framework\TestCase;

/**
 * Ticks the declared items against documents and fields.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseCompleteness
 */
class CaseCompletenessTest extends TestCase {

	/**
	 * The service over mocked readers; these tests drive itemsOf() and check() only.
	 *
	 * @return CaseCompleteness
	 */
	private function service(): CaseCompleteness {
		return new CaseCompleteness(
			cases: $this->createMock(CaseStatusStore::class),
			caseTypes: $this->createMock(CaseTypeResolver::class),
			dossier: $this->createMock(ZaakdossierService::class),
			reader: $this->createMock(InformatieobjectReader::class),
		);
	}//end service()

	/**
	 * The Woo request's five items as the board draws them.
	 *
	 * @return array<string, mixed>
	 */
	private function wooCaseType(): array {
		return [
			'intakeRequirements' => [
				'completenessItems' => [
					['key' => 'verzoek', 'label' => 'Verzoek met omschrijving', 'documentTitleContains' => 'verzoek'],
					['key' => 'contact', 'label' => 'Contactgegevens', 'field' => 'initiatorDisplayName', 'evidence' => 'uit Mijn Zuiddrecht'],
					['key' => 'periode', 'label' => 'Periode', 'field' => 'wooRequest.period'],
					['key' => 'machtiging', 'label' => 'Machtiging', 'documentType' => 'machtiging'],
					['key' => 'precisering', 'label' => 'Precisering', 'documentTitleContains' => 'precisering'],
					['key' => '', 'label' => 'no key, left out'],
				],
			],
		];
	}//end wooCaseType()

	/**
	 * Two of five present: a document by title and a field with its evidence text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testTwoOfFivePresentReadsIncomplete(): void {
		$service = $this->service();
		$items   = $service->itemsOf(caseType: $this->wooCaseType());
		$doc     = ['id' => 'd1', 'titel' => 'Woo-verzoek', 'bestandsnaam' => 'verzoek-2026-0110.pdf', 'informatieobjecttype' => 'aanvraag'];

		$answer = $service->check(
			items: $items,
			case: ['initiatorDisplayName' => 'Sanne de Vries', 'wooRequest' => ['period' => '']],
			documents: [$doc],
			readable: [$doc],
		);

		self::assertCount(5, $answer['items']);
		self::assertSame(['key' => 'verzoek', 'label' => 'Verzoek met omschrijving', 'present' => true, 'evidence' => 'verzoek-2026-0110.pdf'], $answer['items'][0]);
		self::assertSame('uit Mijn Zuiddrecht', $answer['items'][1]['evidence']);
		self::assertFalse($answer['items'][2]['present']);
		self::assertNull($answer['items'][2]['evidence']);
		self::assertSame(2, $answer['present']);
		self::assertSame(5, $answer['total']);
		self::assertSame(3, $answer['missing']);
		self::assertSame('incomplete', $answer['verdict']);
	}//end testTwoOfFivePresentReadsIncomplete()

	/**
	 * A document the reader may not read counts, but its name is not shown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testAnUnreadableDocumentCountsWithoutItsName(): void {
		$service = $this->service();
		$items   = [['key' => 'machtiging', 'label' => 'Machtiging', 'documentType' => 'machtiging']];
		$doc     = ['id' => 'secret', 'bestandsnaam' => 'machtiging-jansen.pdf', 'informatieobjecttype' => 'machtiging'];

		$answer = $service->check(items: $items, case: [], documents: [$doc], readable: []);

		self::assertTrue($answer['items'][0]['present']);
		self::assertNull($answer['items'][0]['evidence']);
		self::assertSame('complete', $answer['verdict']);
	}//end testAnUnreadableDocumentCountsWithoutItsName()

	/**
	 * A case type without a list answers an empty list only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testNoDeclaredListAnswersAnEmptyList(): void {
		$service = $this->service();

		self::assertSame([], $service->itemsOf(caseType: ['intakeRequirements' => ['requiredBeforeComplete' => ['x']]]));
		self::assertSame(['items' => []], $service->check(items: [], case: [], documents: [], readable: []));
	}//end testNoDeclaredListAnswersAnEmptyList()
}//end class
