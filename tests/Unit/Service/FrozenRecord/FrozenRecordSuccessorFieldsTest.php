<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordSuccessor;
use PHPUnit\Framework\TestCase;

/**
 * What `successorFields` refuses first, what it says, and where it finds the predecessor's id.
 */
class FrozenRecordSuccessorFieldsTest extends TestCase {

	/**
	 * A successor service over a numberer that answers `F-2026-000002`.
	 *
	 * @param DateTimeImmutable|null $moment The moment the numberer must be given.
	 *
	 * @return FrozenRecordSuccessor The service.
	 */
	private function successor(?DateTimeImmutable $moment = null): FrozenRecordSuccessor {
		$numberer = $this->createMock(DocumentSeriesNumberer::class);
		$numberer->method('issue')->with('case-1', 'fixture', 'F', $moment)->willReturn('F-2026-000002');

		return new FrozenRecordSuccessor(numberer: $numberer, guard: new FrozenRecordGuard());
	}//end successor()

	/**
	 * The refusal `successorFields` throws, as rule, status and sentence.
	 *
	 * @param array<string, mixed>  $override    The definition override.
	 * @param array<string, mixed>  $predecessor The predecessor.
	 *
	 * @return array{0: string, 1: int, 2: string} The refusal.
	 */
	private function refusal(array $override, array $predecessor): array {
		try {
			$this->successor()->successorFields(
				definition: FrozenRecordFixture::definition(override: $override),
				predecessor: $predecessor,
				caseId: 'case-1',
			);
		} catch (RefusedException $refusal) {
			return [$refusal->getRule(), $refusal->getStatus(), $refusal->getSentence()];
		}

		self::fail('Not refused');
	}//end refusal()

	/**
	 * A missing successor is refused before the frozen check.
	 *
	 * @return void
	 */
	public function testNoSuccessorIsRefusedFirst(): void {
		self::assertSame(
			['record-has-no-successor', 422, 'This kind of record cannot be replaced by a successor.'],
			$this->refusal(override: ['successor' => null], predecessor: FrozenRecordFixture::record(status: 'draft', extra: ['supersededBy' => 'rec-0']))
		);
	}//end testNoSuccessorIsRefusedFirst()

	/**
	 * A record that is not frozen is refused before the replaced check.
	 *
	 * @return void
	 */
	public function testNotFrozenIsRefusedBeforeReplaced(): void {
		self::assertSame(
			['record-not-frozen', 409, 'This record is not frozen yet, so it can still be edited. A successor is only for a frozen record.'],
			$this->refusal(override: [], predecessor: FrozenRecordFixture::record(status: 'draft', extra: ['supersededBy' => 'rec-0']))
		);
	}//end testNotFrozenIsRefusedBeforeReplaced()

	/**
	 * An already replaced record says to correct its successor; blank pointers do not count.
	 *
	 * @return void
	 */
	public function testAlreadyReplaced(): void {
		self::assertSame(
			['record-already-superseded', 409, 'This record has already been replaced. Correct its successor instead.'],
			$this->refusal(override: [], predecessor: FrozenRecordFixture::record(status: 'issued', extra: ['supersededBy' => ' rec-0 ']))
		);

		$fields = $this->successor()->successorFields(
			definition: FrozenRecordFixture::definition(),
			predecessor: FrozenRecordFixture::record(status: 'issued', extra: ['supersededBy' => '  ']),
			caseId: 'case-1',
		);
		self::assertSame(['reference' => 'F-2026-000002', 'supersedes' => 'rec-1'], $fields);
	}//end testAlreadyReplaced()

	/**
	 * The id falls back to `@self.id`, trimmed, then to blank; the moment reaches the numberer.
	 *
	 * @return void
	 */
	public function testThePredecessorIdAndMoment(): void {
		$moment = new DateTimeImmutable('2026-03-01T10:00:00Z');
		$record = FrozenRecordFixture::record(status: 'issued');
		unset($record['id']);
		$record['@self']['id'] = ' rec-9 ';

		$fields = $this->successor(moment: $moment)->successorFields(
			definition: FrozenRecordFixture::definition(),
			predecessor: $record,
			caseId: 'case-1',
			moment: $moment,
		);
		self::assertSame(['reference' => 'F-2026-000002', 'supersedes' => 'rec-9'], $fields);

		unset($record['@self']['id']);
		$fields = $this->successor()->successorFields(
			definition: FrozenRecordFixture::definition(),
			predecessor: $record,
			caseId: 'case-1',
		);
		self::assertSame('', $fields['supersedes']);
	}//end testThePredecessorIdAndMoment()
}//end class
