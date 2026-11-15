<?php

/**
 * The name a status is shown under on the timeline.
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

use OCA\Dossiq\Service\Timeline\StatusDisplayName;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * StatusDisplayName: the trimmed name, else the id, else nothing.
 *
 * @covers \OCA\Dossiq\Service\Timeline\StatusDisplayName
 *
 * @spec openspec/specs/case-management/spec.md
 */
class StatusDisplayNameTest extends TestCase {
	/**
	 * No id shows nothing and asks the lookup nothing.
	 *
	 * @return void
	 */
	public function testNoIdShowsNothingAndAsksNothing(): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->expects(self::never())->method('nameFor');

		self::assertSame(expected: '', actual: (new StatusDisplayName(statuses: $lookup))->of(statusTypeId: ''));
	}//end testNoIdShowsNothingAndAsksNothing()

	/**
	 * A named status shows its name, trimmed, asked for by its id.
	 *
	 * @return void
	 */
	public function testANamedStatusShowsItsTrimmedName(): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->expects(self::once())->method('nameFor')->with('st-1')->willReturn("  In behandeling \n");

		self::assertSame(expected: 'In behandeling', actual: (new StatusDisplayName(statuses: $lookup))->of(statusTypeId: 'st-1'));
	}//end testANamedStatusShowsItsTrimmedName()

	/**
	 * A status whose name is blank shows its id.
	 *
	 * @return void
	 */
	public function testABlankNameShowsTheId(): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('nameFor')->willReturn('   ');

		self::assertSame(expected: 'st-2', actual: (new StatusDisplayName(statuses: $lookup))->of(statusTypeId: 'st-2'));
	}//end testABlankNameShowsTheId()

	/**
	 * A lookup that throws shows the id rather than failing the timeline write.
	 *
	 * @return void
	 */
	public function testAFailingLookupShowsTheId(): void {
		$lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$lookup->method('nameFor')->willThrowException(new RuntimeException('register down'));

		self::assertSame(expected: 'st-3', actual: (new StatusDisplayName(statuses: $lookup))->of(statusTypeId: 'st-3'));
	}//end testAFailingLookupShowsTheId()
}//end class
