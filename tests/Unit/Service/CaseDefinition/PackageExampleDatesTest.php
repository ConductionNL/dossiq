<?php

/**
 * Tests for PackageExampleDates.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
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
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Service\CaseDefinition\PackageExampleDates;
use PHPUnit\Framework\TestCase;

/**
 * Example rows get their dates relative to the install day.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
class PackageExampleDatesTest extends TestCase {

	/**
	 * Date and date-time tokens are filled, nested ones too; anything else is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testFillsTokensRelativeToToday(): void {
		$today = new DateTimeImmutable('2026-10-18 15:42', new DateTimeZone('Europe/Amsterdam'));
		$rows = [
			[
				'startDate' => '{{date:-25}}',
				'deadline' => '{{date:17}}',
				'statusHistory' => [['enteredAt' => '{{datetime:-16 10:00}}']],
				'title' => 'Hoorzitting {{date:-1}}',
				'count' => 3,
				'done' => false,
			],
		];

		$filled = (new PackageExampleDates())->fill(rows: $rows, today: $today);

		self::assertSame(expected: '2026-09-23', actual: $filled[0]['startDate']);
		self::assertSame(expected: '2026-11-04', actual: $filled[0]['deadline']);
		self::assertSame(expected: '2026-10-02T10:00:00+02:00', actual: $filled[0]['statusHistory'][0]['enteredAt']);
		self::assertSame(expected: 'Hoorzitting {{date:-1}}', actual: $filled[0]['title'], message: 'Only a string that is exactly one token is filled.');
		self::assertSame(expected: 3, actual: $filled[0]['count']);
		self::assertFalse(condition: $filled[0]['done']);
	}//end testFillsTokensRelativeToToday()

	/**
	 * A list-of-records answer stored as JSON text gets its tokens filled inside.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/investigation-steps-in-the-case-type/specs/case-page/spec.md
	 */
	public function testFillsTokensInsideAJsonTextAnswer(): void {
		$today = new DateTimeImmutable('2026-10-18 15:42', new DateTimeZone('Europe/Amsterdam'));
		$rows = [
			[
				'value' => '[{"kind":"gesprek","date":"{{date:-12}}"},{"kind":"anders","date":"2026-01-01"}]',
				'plain' => '[not json {{date:-1}}',
			],
		];

		$filled = (new PackageExampleDates())->fill(rows: $rows, today: $today);

		self::assertSame(
			expected: [['kind' => 'gesprek', 'date' => '2026-10-06'], ['kind' => 'anders', 'date' => '2026-01-01']],
			actual: json_decode($filled[0]['value'], true)
		);
		self::assertSame(expected: '[not json {{date:-1}}', actual: $filled[0]['plain']);
	}//end testFillsTokensInsideAJsonTextAnswer()
}//end class
