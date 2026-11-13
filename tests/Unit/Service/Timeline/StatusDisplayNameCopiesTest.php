<?php

/**
 * The status name a timeline entry shows.
 *
 * Pins statusName() of DerivedStatusTimelineListener and StatusMoveEntry
 * before both ask the lookup for it: no id shows nothing and asks nothing;
 * a named status shows its trimmed name; an unnamed status, or one whose
 * lookup fails, shows its id.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Listener\DerivedStatusTimelineListener;
use OCA\Dossiq\Service\Timeline\StatusMoveEntry;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Both timeline writers show the same name for the same status.
 *
 * @covers \OCA\Dossiq\Listener\DerivedStatusTimelineListener
 * @covers \OCA\Dossiq\Service\Timeline\StatusMoveEntry
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Timeline\StatusDisplayName
 */
class StatusDisplayNameCopiesTest extends TestCase {
	/**
	 * Both writers.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function writers(): array {
		return [
			'derived status listener' => [DerivedStatusTimelineListener::class],
			'status move entry' => [StatusMoveEntry::class],
		];
	}//end writers()

	/**
	 * No id shows nothing and asks nothing.
	 *
	 * @param class-string $class The writer.
	 *
	 * @dataProvider writers
	 *
	 * @return void
	 */
	public function testNoIdShowsNothing(string $class): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->expects(self::never())->method('nameFor');

		self::assertSame(expected: '', actual: $this->statusName(class: $class, lookup: $lookup));
	}//end testNoIdShowsNothing()

	/**
	 * A named status shows its trimmed name.
	 *
	 * @param class-string $class The writer.
	 *
	 * @dataProvider writers
	 *
	 * @return void
	 */
	public function testANamedStatusShowsItsName(string $class): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->expects(self::once())->method('nameFor')->with('st-1')->willReturn('  In behandeling ');

		self::assertSame(expected: 'In behandeling', actual: $this->statusName(class: $class, lookup: $lookup, id: 'st-1'));
	}//end testANamedStatusShowsItsName()

	/**
	 * An unnamed status shows its id.
	 *
	 * @param class-string $class The writer.
	 *
	 * @dataProvider writers
	 *
	 * @return void
	 */
	public function testAnUnnamedStatusShowsItsId(string $class): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('nameFor')->willReturn('   ');

		self::assertSame(expected: 'st-1', actual: $this->statusName(class: $class, lookup: $lookup, id: 'st-1'));
	}//end testAnUnnamedStatusShowsItsId()

	/**
	 * A failing lookup shows the id.
	 *
	 * @param class-string $class The writer.
	 *
	 * @dataProvider writers
	 *
	 * @return void
	 */
	public function testAFailingLookupShowsTheId(string $class): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('nameFor')->willThrowException(new RuntimeException('register down'));

		self::assertSame(expected: 'st-1', actual: $this->statusName(class: $class, lookup: $lookup, id: 'st-1'));
	}//end testAFailingLookupShowsTheId()

	/**
	 * The writer's statusName() over the given lookup.
	 *
	 * @param class-string                      $class  The writer.
	 * @param StatusTypeLookup&MockObject       $lookup The lookup.
	 * @param string                            $id     The statusType id.
	 *
	 * @return string The name shown.
	 */
	private function statusName(string $class, StatusTypeLookup $lookup, string $id = ''): string {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$arguments[] = ($type->getName() === StatusTypeLookup::class) ? $lookup : $this->createMock(originalClassName: $type->getName());
		}

		$subject = new $class(...$arguments);

		return (new ReflectionMethod($subject, 'statusName'))->invoke($subject, $id);
	}//end statusName()
}//end class
