<?php

/**
 * How a term instance is classified as met, missed, running or suspended.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\TermOutcome;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermOutcome
 */
class TermOutcomeTest extends TestCase {
	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function rows(): array {
		return [
			'completed in time' => [['status' => 'completed', 'voltooiDatum' => '2026-05-01', 'endDateCurrent' => '2026-05-02'], 'met'],
			'completed late' => [['status' => 'completed', 'voltooiDatum' => '2026-05-03T10:00:00+00:00', 'endDateCurrent' => '2026-05-02'], 'missed'],
			'completed without a date' => [['status' => 'completed'], 'met'],
			'exceeded' => [['status' => 'exceeded'], 'missed'],
			'running' => [['status' => 'lopend'], 'running'],
			'extended' => [['status' => 'verlengd'], 'running'],
			'paused' => [['status' => 'paused'], 'suspended'],
			'withdrawn' => [['status' => 'withdrawn'], ''],
			'no status' => [[], ''],
		];
	}

	/**
	 * @dataProvider rows
	 */
	public function testClassify(array $row, string $expected): void {
		self::assertSame($expected, (new TermOutcome())->classify(row: $row));
	}
}
