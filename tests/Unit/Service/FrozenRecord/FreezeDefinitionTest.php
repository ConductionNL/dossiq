<?php

/**
 * One freeze entry: its validation, defaults, and which fields freeze.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use InvalidArgumentException;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 */
class FreezeDefinitionTest extends TestCase {

	public function testAMinimalEntryTakesEveryDefault(): void {
		$definition = new FreezeDefinition(['schema' => ' besluit ', 'frozenStatuses' => ['vastgesteld', ' ', 'vastgesteld', 7]]);

		self::assertSame('besluit', $definition->schema);
		self::assertSame(['vastgesteld'], $definition->frozenStatuses);
		self::assertSame('currentStatus', $definition->statusField);
		self::assertSame('seal', $definition->sealField);
		self::assertNull($definition->successor);
		self::assertSame(FreezeDefinition::DEFAULT_RULE, $definition->rule);
		self::assertSame(FreezeDefinition::DEFAULT_MESSAGE, $definition->message);
		self::assertSame([], $definition->writeOnceFields());
	}//end testAMinimalEntryTakesEveryDefault()

	public function testAnEntryWithoutASchemaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A freeze entry must name the schema it guards.');
		new FreezeDefinition(['schema' => '  ', 'frozenStatuses' => ['x']]);
	}//end testAnEntryWithoutASchemaIsRefused()

	public function testAnEntryWithoutAFreezingStatusIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The freeze entry for besluit names no status at which the record freezes.');
		new FreezeDefinition(['schema' => 'besluit', 'frozenStatuses' => ['  ']]);
	}//end testAnEntryWithoutAFreezingStatusIsRefused()

	public function testASuccessorWithoutASeriesIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('declares a successor without a series');
		new FreezeDefinition(['schema' => 'besluit', 'frozenStatuses' => ['x'], 'successor' => ['defaultPrefix' => 'B']]);
	}//end testASuccessorWithoutASeriesIsRefused()

	public function testFreezesAtMatchesExactly(): void {
		$definition = new FreezeDefinition(['schema' => 's', 'frozenStatuses' => ['vastgesteld']]);

		self::assertTrue($definition->freezesAt(status: 'vastgesteld'));
		self::assertFalse($definition->freezesAt(status: 'Vastgesteld'));
	}//end testFreezesAtMatchesExactly()

	public function testWriteOnceFieldsAddTheSealAndTheSupersededByField(): void {
		$definition = new FreezeDefinition([
			'schema' => 's',
			'frozenStatuses' => ['x'],
			'writeOnce' => ['issuedAt', 'seal'],
			'sealFields' => ['body'],
			'successor' => ['series' => 'besluiten'],
		]);

		self::assertSame(['issuedAt', 'seal', 'supersededBy'], $definition->writeOnceFields());
		self::assertSame(
			['series' => 'besluiten', 'defaultPrefix' => '', 'numberField' => 'reference', 'supersedesField' => 'supersedes', 'supersededByField' => 'supersededBy'],
			$definition->successor
		);
	}//end testWriteOnceFieldsAddTheSealAndTheSupersededByField()

	public function testWithoutAFieldListEverythingButTheMutableFieldsFreezes(): void {
		$definition = new FreezeDefinition(['schema' => 's', 'frozenStatuses' => ['x'], 'mutableFields' => ['notes'], 'statusField' => 'state', 'writeOnce' => ['issuedAt']]);

		self::assertTrue($definition->isFrozenField(field: 'body'));
		self::assertFalse($definition->isFrozenField(field: 'notes'));
		// The envelope, metadata, the status itself and write-once fields are never "frozen" fields.
		foreach (['', '@self', '_extend', 'id', 'uuid', 'state', 'issuedAt'] as $field) {
			self::assertFalse($definition->isFrozenField(field: $field), $field);
		}
	}//end testWithoutAFieldListEverythingButTheMutableFieldsFreezes()

	public function testWithAFieldListOnlyThoseAndTheSuccessorLinksFreeze(): void {
		$definition = new FreezeDefinition([
			'schema' => 's',
			'frozenStatuses' => ['x'],
			'fields' => ['body', 'decision'],
			'mutableFields' => ['body'],
			'successor' => ['series' => 'b', 'numberField' => 'kenmerk'],
		]);

		self::assertTrue($definition->isFrozenField(field: 'body'));
		self::assertTrue($definition->isFrozenField(field: 'kenmerk'));
		self::assertTrue($definition->isFrozenField(field: 'supersedes'));
		self::assertFalse($definition->isFrozenField(field: 'supersededBy'));
		self::assertFalse($definition->isFrozenField(field: 'notes'));
	}//end testWithAFieldListOnlyThoseAndTheSuccessorLinksFreeze()

	public function testCustomRuleAndMessageAreKept(): void {
		$definition = new FreezeDefinition(['schema' => 's', 'frozenStatuses' => ['x'], 'rule' => ' besluit-bevroren ', 'message' => 'Bevroren.']);

		self::assertSame('besluit-bevroren', $definition->rule);
		self::assertSame('Bevroren.', $definition->message);
	}//end testCustomRuleAndMessageAreKept()
}//end class
