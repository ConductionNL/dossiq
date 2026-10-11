<?php

/**
 * FrozenRecordGuard and FreezeDefinition unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
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
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use InvalidArgumentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use PHPUnit\Framework\TestCase;

/**
 * The freeze rule on the neutral fixture record kind.
 *
 * @covers \OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard
 * @covers \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 */
class FrozenRecordGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var FrozenRecordGuard
	 */
	private FrozenRecordGuard $guard;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = new FrozenRecordGuard();
	}//end setUp()

	/**
	 * Assert that a closure is refused with the given rule and 409.
	 *
	 * @param callable $write The write.
	 * @param string $rule The expected rule.
	 *
	 * @return void
	 */
	private function assertRefused(callable $write, string $rule = FreezeDefinition::DEFAULT_RULE): void {
		try {
			$write();
		} catch (RefusedException $refusal) {
			self::assertSame($rule, $refusal->getRule());
			self::assertSame(RefusedException::STATUS_REFUSED, $refusal->getStatus());
			return;
		}

		self::fail('The write was not refused.');
	}//end assertRefused()

	/**
	 * Before it freezes, every field moves.
	 *
	 * @return void
	 */
	public function testDraftMovesFreely(): void {
		$this->guard->assertUpdatable(
			definition: FrozenRecordFixture::definition(),
			stored: FrozenRecordFixture::record(status: 'draft'),
			incoming: FrozenRecordFixture::record(status: 'draft', extra: ['body' => 'New text', 'amount' => 20]),
		);
		$this->addToAssertionCount(1);
	}//end testDraftMovesFreely()

	/**
	 * A frozen field may not move once the record is frozen.
	 *
	 * @return void
	 */
	public function testFrozenFieldIsRefused(): void {
		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: FrozenRecordFixture::definition(),
				stored: FrozenRecordFixture::record(status: 'issued'),
				incoming: FrozenRecordFixture::record(status: 'issued', extra: ['amount' => 11]),
			)
		);
	}//end testFrozenFieldIsRefused()

	/**
	 * A frozen record answers with the rule and sentence its entry names.
	 *
	 * @return void
	 */
	public function testEntryNamesItsRefusal(): void {
		$definition = FrozenRecordFixture::definition(override: ['rule' => 'fixture-frozen', 'message' => 'Fixture is frozen.']);

		$this->assertRefused(
			fn () => $this->guard->assertDeletable(definition: $definition, stored: FrozenRecordFixture::record(status: 'issued')),
			'fixture-frozen'
		);
		self::assertSame('Fixture is frozen.', $this->guard->refusal(definition: $definition)->getSentence());
	}//end testEntryNamesItsRefusal()

	/**
	 * The successor's number and backward pointer are frozen too, even when not listed.
	 *
	 * @return void
	 */
	public function testSuccessorFieldsAreFrozen(): void {
		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: FrozenRecordFixture::definition(),
				stored: FrozenRecordFixture::record(status: 'issued'),
				incoming: FrozenRecordFixture::record(status: 'issued', extra: ['reference' => 'F-2026-000099']),
			)
		);
	}//end testSuccessorFieldsAreFrozen()

	/**
	 * A field the entry does not freeze still moves, and a repeated value changes nothing.
	 *
	 * @return void
	 */
	public function testUnfrozenFieldAndRepeatedValuesPass(): void {
		$this->guard->assertUpdatable(
			definition: FrozenRecordFixture::definition(),
			stored: FrozenRecordFixture::record(status: 'issued', extra: ['meta' => ['a' => 1, 'b' => 2]]),
			incoming: FrozenRecordFixture::record(status: 'archived', extra: ['note' => 'moved', 'meta' => ['b' => 2, 'a' => 1]]),
		);
		$this->addToAssertionCount(1);
	}//end testUnfrozenFieldAndRepeatedValuesPass()

	/**
	 * Without a field list, everything but the mutable fields is frozen.
	 *
	 * @return void
	 */
	public function testEverythingButMutableFieldsWhenNoListIsGiven(): void {
		$definition = FrozenRecordFixture::definition(override: ['fields' => [], 'mutableFields' => ['note']]);
		$stored = FrozenRecordFixture::record(status: 'issued');

		$this->guard->assertUpdatable(definition: $definition, stored: $stored, incoming: FrozenRecordFixture::record(status: 'issued', extra: ['note' => 'x']));
		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: $definition,
				stored: $stored,
				incoming: FrozenRecordFixture::record(status: 'issued', extra: ['newField' => 'x']),
			)
		);
	}//end testEverythingButMutableFieldsWhenNoListIsGiven()

	/**
	 * A frozen record does not thaw, whatever status the payload claims.
	 *
	 * @return void
	 */
	public function testThawIsRefused(): void {
		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: FrozenRecordFixture::definition(),
				stored: FrozenRecordFixture::record(status: 'issued'),
				incoming: FrozenRecordFixture::record(status: 'draft'),
			)
		);
	}//end testThawIsRefused()

	/**
	 * A soft delete arrives as an update and is refused on a frozen record.
	 *
	 * @return void
	 */
	public function testSoftDeleteIsRefused(): void {
		$incoming = FrozenRecordFixture::record(status: 'issued');
		$incoming['@self']['deleted'] = ['deletedBy' => 'someone'];

		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: FrozenRecordFixture::definition(),
				stored: FrozenRecordFixture::record(status: 'issued'),
				incoming: $incoming,
			)
		);
	}//end testSoftDeleteIsRefused()

	/**
	 * A write-once field is written once, at any status, and never rewritten.
	 *
	 * @return void
	 */
	public function testWriteOnceFields(): void {
		$definition = FrozenRecordFixture::definition();

		$this->guard->assertUpdatable(
			definition: $definition,
			stored: FrozenRecordFixture::record(status: 'issued'),
			incoming: FrozenRecordFixture::record(status: 'issued', extra: ['supersededBy' => 'rec-2']),
		);
		$this->assertRefused(
			fn () => $this->guard->assertUpdatable(
				definition: $definition,
				stored: FrozenRecordFixture::record(status: 'draft', extra: ['supersededBy' => 'rec-2']),
				incoming: FrozenRecordFixture::record(status: 'draft', extra: ['supersededBy' => 'rec-3']),
			)
		);
	}//end testWriteOnceFields()

	/**
	 * A frozen record is not deleted; a draft is.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$definition = FrozenRecordFixture::definition();

		$this->guard->assertDeletable(definition: $definition, stored: FrozenRecordFixture::record(status: 'draft'));
		$this->assertRefused(fn () => $this->guard->assertDeletable(definition: $definition, stored: FrozenRecordFixture::record(status: 'archived')));
	}//end testDelete()

	/**
	 * The freezing write is recognised; later writes are not freezing.
	 *
	 * @return void
	 */
	public function testIsFreezing(): void {
		$definition = FrozenRecordFixture::definition();

		self::assertTrue($this->guard->isFreezing(definition: $definition, stored: FrozenRecordFixture::record(status: 'draft'), incoming: FrozenRecordFixture::record(status: 'issued')));
		self::assertFalse($this->guard->isFreezing(definition: $definition, stored: FrozenRecordFixture::record(status: 'issued'), incoming: FrozenRecordFixture::record(status: 'archived')));
	}//end testIsFreezing()

	/**
	 * The write-once list holds the declared fields, the seal and the forward pointer.
	 *
	 * @return void
	 */
	public function testWriteOnceFieldList(): void {
		self::assertSame(
			['token', 'seal', 'supersededBy'],
			FrozenRecordFixture::definition(override: ['writeOnce' => ['token']])->writeOnceFields()
		);
	}//end testWriteOnceFieldList()

	/**
	 * An entry without a schema is refused.
	 *
	 * @return void
	 */
	public function testEntryWithoutSchemaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		FrozenRecordFixture::definition(override: ['schema' => '']);
	}//end testEntryWithoutSchemaIsRefused()

	/**
	 * An entry without a frozen status is refused.
	 *
	 * @return void
	 */
	public function testEntryWithoutStatusIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		FrozenRecordFixture::definition(override: ['frozenStatuses' => []]);
	}//end testEntryWithoutStatusIsRefused()

	/**
	 * A successor without a series is refused.
	 *
	 * @return void
	 */
	public function testSuccessorWithoutSeriesIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		FrozenRecordFixture::definition(override: ['successor' => ['defaultPrefix' => 'F']]);
	}//end testSuccessorWithoutSeriesIsRefused()
}//end class
