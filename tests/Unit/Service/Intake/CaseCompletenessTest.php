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
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Ticks the declared items against documents and fields.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseCompleteness
 * @uses \OCA\Dossiq\Service\Support\FilledValue
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
	/**
	 * Items keep only a trimmed key and label plus the non-blank string options, in declared order.
	 *
	 * @return void
	 */
	public function testItemsOfTrimsAndDropsMalformedEntries(): void {
		$service  = $this->service();
		$caseType = [
			'intakeRequirements' => [
				'completenessItems' => [
					'not an item',
					['key' => ' a ', 'label' => ' Alpha ', 'field' => ' x.y ', 'documentType' => '  ', 'evidence' => 7, 'other' => 'z'],
					['key' => 'b', 'label' => '   '],
					['key' => 'c', 'label' => 'Gamma', 'evidence' => ' seen ', 'documentTitleContains' => 'brief', 'documentType' => 't'],
				],
			],
		];

		self::assertSame(
			[
				['key' => 'a', 'label' => 'Alpha', 'field' => 'x.y'],
				['key' => 'c', 'label' => 'Gamma', 'documentType' => 't', 'documentTitleContains' => 'brief', 'evidence' => 'seen'],
			],
			$service->itemsOf(caseType: $caseType)
		);
		self::assertSame([], $service->itemsOf(caseType: ['intakeRequirements' => ['completenessItems' => 'none']]));
	}//end testItemsOfTrimsAndDropsMalformedEntries()

	/**
	 * The first readable answering document names the evidence, even after an unreadable one.
	 *
	 * @return void
	 */
	public function testTheFirstReadableAnsweringDocumentGivesTheEvidence(): void {
		$service  = $this->service();
		$items    = [['key' => 'brief', 'label' => 'Brief', 'documentTitleContains' => 'BRIEF', 'field' => 'filled']];
		$hidden   = ['id' => 'h', 'titel' => 'Brief 1', 'bestandsnaam' => 'hidden.pdf'];
		$other    = ['@self' => ['id' => 'o'], 'titel' => 'Notitie'];
		$readable = ['uuid' => 'r', 'titel' => ' Brief 2 ', 'bestandsnaam' => ' '];

		$answer = $service->check(
			items: $items,
			case: ['filled' => 'yes'],
			documents: [$hidden, $other, $readable],
			readable: [$other, $readable],
		);

		self::assertSame(['key' => 'brief', 'label' => 'Brief', 'present' => true, 'evidence' => 'Brief 2'], $answer['items'][0]);
	}//end testTheFirstReadableAnsweringDocumentGivesTheEvidence()

	/**
	 * A document that answers but cannot be read wins over the field: present, no evidence.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentWinsOverTheField(): void {
		$service = $this->service();
		$items   = [['key' => 'k', 'label' => 'K', 'documentType' => 'm', 'field' => 'f', 'evidence' => 'from field']];
		$doc     = ['id' => 'd', 'informatieobjecttype' => 'm'];

		$answer = $service->check(items: $items, case: ['f' => 'v'], documents: [$doc], readable: []);

		self::assertSame(['key' => 'k', 'label' => 'K', 'present' => true, 'evidence' => null], $answer['items'][0]);
	}//end testAnUnreadableDocumentWinsOverTheField()

	/**
	 * Fields: a scalar value is its own evidence, a list counts without evidence, a missing path or blank stays absent.
	 *
	 * @return void
	 */
	public function testFieldValuesTickWithScalarEvidenceOnly(): void {
		$service = $this->service();
		$items   = [
			['key' => 'n', 'label' => 'N', 'field' => 'a.n'],
			['key' => 'l', 'label' => 'L', 'field' => 'a.l'],
			['key' => 'm', 'label' => 'M', 'field' => 'a.n.deeper'],
			['key' => 'b', 'label' => 'B', 'field' => 'a.b'],
			['key' => 'z', 'label' => 'Z', 'field' => 'a.z'],
			['key' => 'd', 'label' => 'D'],
		];
		$case    = ['a' => ['n' => 42, 'l' => ['x'], 'b' => ' ', 'z' => null]];

		$answer = $service->check(items: $items, case: $case, documents: [['id' => 'q', 'titel' => 'n']], readable: []);

		self::assertSame(['key' => 'n', 'label' => 'N', 'present' => true, 'evidence' => '42'], $answer['items'][0]);
		self::assertSame(['key' => 'l', 'label' => 'L', 'present' => true, 'evidence' => null], $answer['items'][1]);
		self::assertFalse($answer['items'][2]['present']);
		self::assertFalse($answer['items'][3]['present']);
		self::assertFalse($answer['items'][4]['present']);
		self::assertSame(['key' => 'd', 'label' => 'D', 'present' => false, 'evidence' => null], $answer['items'][5]);
		self::assertSame(2, $answer['present']);
	}//end testFieldValuesTickWithScalarEvidenceOnly()

	/**
	 * forCase: null for an unknown case, the case type id read from a nested object, readable documents from the reader.
	 *
	 * @return void
	 */
	public function testForCaseReadsTheCaseTypeAndTheReadableDocuments(): void {
		$cases     = $this->createMock(CaseStatusStore::class);
		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$dossier   = $this->createMock(ZaakdossierService::class);
		$reader    = $this->createMock(InformatieobjectReader::class);
		$user      = $this->createMock(IUser::class);
		$doc       = ['id' => 'd1', 'bestandsnaam' => 'machtiging.pdf', 'informatieobjecttype' => 'machtiging'];

		$cases->method('loadCase')->willReturnMap([
			['missing', null],
			['nested', ['caseType' => ['id' => 'ct-1']]],
			['flat', ['caseType' => 'ct-2']],
		]);
		$caseTypes->method('effectiveCaseType')->willReturnMap([
			['ct-1', $this->wooCaseType()],
			['ct-2', []],
		]);
		$dossier->expects(self::once())->method('getDossierForCase')->with('nested')->willReturn(['informatieobjecten' => [$doc]]);
		$reader->expects(self::once())->method('filterForUser')->with($user, [$doc])->willReturn([$doc]);

		$service = new CaseCompleteness(cases: $cases, caseTypes: $caseTypes, dossier: $dossier, reader: $reader);

		self::assertNull($service->forCase(caseId: 'missing', user: $user));
		self::assertSame(['items' => []], $service->forCase(caseId: 'flat', user: $user));

		$answer = $service->forCase(caseId: 'nested', user: $user);
		self::assertSame('machtiging.pdf', $answer['items'][3]['evidence']);
		self::assertSame(1, $answer['present']);
	}//end testForCaseReadsTheCaseTypeAndTheReadableDocuments()
}//end class
