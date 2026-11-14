<?php

/**
 * The start date and the receipt-date answer a received case is given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use DateTimeInterface;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\CaseReceiptDates;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\CaseReceiptDates
 * @uses   \OCA\Dossiq\Service\Intake\IntakeTermStart
 */
class CaseReceiptDatesTest extends TestCase {

	/**
	 * The case type store, answering one case type's property definitions.
	 *
	 * @var CaseTypeStore&MockObject
	 */
	private CaseTypeStore&MockObject $caseTypes;

	/**
	 * The service under test; calendar days are the moment's own date.
	 *
	 * @param array<int, array<string, mixed>> $definitions The property definitions of case type `ct-1`.
	 *
	 * @return CaseReceiptDates
	 */
	private function dates(array $definitions = []): CaseReceiptDates {
		$this->caseTypes = $this->createMock(CaseTypeStore::class);
		$this->caseTypes->method('referenceId')->willReturnCallback(static fn (mixed $value): string => is_string($value) === true ? $value : '');
		$this->caseTypes->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$this->caseTypes->method('rowsOfType')->willReturnCallback(
			static fn (string $schemaKey, string $caseTypeId): array => ($schemaKey === 'property_definition_schema' && $caseTypeId === 'ct-1') ? $definitions : []
		);

		$normaliser = $this->createMock(CaseDateNormaliser::class);
		$normaliser->method('formatCalendarDate')->willReturnCallback(static fn (DateTimeInterface $moment): string => $moment->format('Y-m-d'));

		return new CaseReceiptDates(
			intake: new IntakeTermStart(calendar: $this->createMock(WorkingDayRoll::class)),
			caseTypes: $this->caseTypes,
			dates: $normaliser
		);
	}//end dates()

	/**
	 * A term start that does not read as a date leaves the start date on the receipt day.
	 *
	 * @return void
	 */
	public function testAnUnreadableTermStartLeavesTheStartOnTheReceiptDay(): void {
		$dates = $this->dates();

		self::assertSame(
			['startDate' => '2026-03-08'],
			$dates->changesFor(case: ['receivedAt' => '2026-03-08T20:14:00+01:00'], termStartsAt: 'not a date')
		);
		self::assertSame('2026-03-09', $dates->startDateFor(case: ['receivedAt' => '2026-03-08T20:14:00+01:00'], termStartsAt: '2026-03-09T09:00:00+01:00'));
	}//end testAnUnreadableTermStartLeavesTheStartOnTheReceiptDay()

	/**
	 * A case without a case type gets no receipt-date answer, and the store is not asked for definitions.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeGetsNoReceiptAnswer(): void {
		$dates = $this->dates(definitions: [['id' => 'pd-1', 'name' => 'receiptDate']]);
		$this->caseTypes->expects(self::never())->method('rowsOfType');

		self::assertSame(
			['startDate' => '2026-03-08'],
			$dates->changesFor(case: ['receivedAt' => '2026-03-08T20:14:00+01:00', 'caseType' => ['not' => 'an id']])
		);
	}//end testACaseWithoutACaseTypeGetsNoReceiptAnswer()

	/**
	 * Properties stored as JSON are read, and the receipt-date answer is added after them.
	 *
	 * @return void
	 */
	public function testPropertiesStoredAsJsonAreKeptAndTheAnswerAdded(): void {
		$dates = $this->dates(definitions: [['id' => 'pd-other', 'name' => 'other'], ['id' => 'pd-1', 'name' => ' receiptDate ']]);

		$changes = $dates->changesFor(
			case: [
				'receivedAt' => '2026-03-08T20:14:00+01:00',
				'startDate' => '2026-03-08',
				'caseType' => 'ct-1',
				'properties' => '{"a":{"name":"colour","value":"red"}}',
			]
		);

		self::assertSame(
			[
				'properties' => [
					['name' => 'colour', 'value' => 'red'],
					['propertyDefinition' => 'pd-1', 'name' => 'receiptDate', 'value' => '2026-03-08'],
				],
			],
			$changes
		);
	}//end testPropertiesStoredAsJsonAreKeptAndTheAnswerAdded()

	/**
	 * Properties that do not read as a list are replaced by the receipt-date answer alone.
	 *
	 * @return void
	 */
	public function testUnreadablePropertiesGiveWayToTheAnswer(): void {
		$dates = $this->dates(definitions: [['id' => 'pd-1', 'name' => 'receiptDate']]);

		$changes = $dates->changesFor(
			case: ['receivedAt' => '2026-03-08T20:14:00+01:00', 'startDate' => '2026-03-08', 'caseType' => 'ct-1', 'properties' => 'not json']
		);

		self::assertSame(
			['properties' => [['propertyDefinition' => 'pd-1', 'name' => 'receiptDate', 'value' => '2026-03-08']]],
			$changes
		);
	}//end testUnreadablePropertiesGiveWayToTheAnswer()
}//end class
