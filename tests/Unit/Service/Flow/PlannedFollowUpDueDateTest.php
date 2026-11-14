<?php

/**
 * Unit tests for PlannedFollowUpDocument::dueDate(): a follow-up's due date
 * must be a real calendar date written as YYYY-MM-DD.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 */
class PlannedFollowUpDueDateTest extends TestCase {

	public function testARealDateIsReadTrimmed(): void {
		$document = new PlannedFollowUpDocument();

		$this->assertSame('2028-02-29', $document->dueDate('2028-02-29')->format('Y-m-d'));
		$this->assertSame('2026-03-01 00:00:00', $document->dueDate(' 2026-03-01 ')->format('Y-m-d H:i:s'));
	}//end testARealDateIsReadTrimmed()

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidDates(): array {
		return [
			'no such day' => ['2026-02-29'],
			'month thirteen' => ['2026-13-01'],
			'unpadded' => ['2026-3-1'],
			'with a time' => ['2026-03-01 10:00'],
			'relative' => ['tomorrow'],
			'empty' => [''],
		];
	}//end invalidDates()

	#[DataProvider('invalidDates')]
	public function testAnythingElseIsAnInvalidDate(string $date): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_date');

		(new PlannedFollowUpDocument())->dueDate($date);
	}//end testAnythingElseIsAnInvalidDate()
}//end class
