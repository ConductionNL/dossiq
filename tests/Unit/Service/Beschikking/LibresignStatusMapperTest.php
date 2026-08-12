<?php

/**
 * LibresignStatusMapper Unit Tests.
 *
 * Pure mapping tests — no I/O, no mocks — covering every LibreSign status
 * value this mapper recognises plus an unrecognised value (asserts UNKNOWN,
 * never an implicit SIGNED).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/libresign-besluit-signing/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Beschikking;

use OCA\Procest\Service\Beschikking\LibresignStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Beschikking\LibresignStatusMapper
 */
class LibresignStatusMapperTest extends TestCase {
	/**
	 * Data provider of raw LibreSign values and their expected mapping.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function statusProvider(): array {
		return [
			'draft text' => ['draft', LibresignStatusMapper::PENDING],
			'able_to_sign text' => ['able_to_sign', LibresignStatusMapper::PENDING],
			'partial_signed text' => ['partial_signed', LibresignStatusMapper::PENDING],
			'pending text' => ['pending', LibresignStatusMapper::PENDING],
			'numeric 0' => ['0', LibresignStatusMapper::PENDING],
			'numeric 1' => ['1', LibresignStatusMapper::PENDING],
			'numeric 2' => ['2', LibresignStatusMapper::PENDING],
			'signed text' => ['signed', LibresignStatusMapper::SIGNED],
			'numeric 3' => ['3', LibresignStatusMapper::SIGNED],
			'deleted text' => ['deleted', LibresignStatusMapper::DECLINED],
			'declined text' => ['declined', LibresignStatusMapper::DECLINED],
			'rejected text' => ['rejected', LibresignStatusMapper::DECLINED],
			'cancelled text' => ['cancelled', LibresignStatusMapper::DECLINED],
			'numeric 4' => ['4', LibresignStatusMapper::DECLINED],
			'case-insensitive' => ['SIGNED', LibresignStatusMapper::SIGNED],
			'whitespace padded' => ['  signed  ', LibresignStatusMapper::SIGNED],
		];
	}//end statusProvider()

	/**
	 * Every recognised LibreSign status value maps to the expected internal vocabulary.
	 *
	 * @param string $raw The raw LibreSign status value.
	 * @param string $expected The expected internal mapping.
	 *
	 * @return void
	 *
	 * @dataProvider statusProvider
	 */
	public function testMapsKnownStatusValues(string $raw, string $expected): void {
		$this->assertSame($expected, (new LibresignStatusMapper())->map($raw));
	}//end testMapsKnownStatusValues()

	/**
	 * An unrecognised status value maps to UNKNOWN, never an implicit SIGNED/PENDING guess.
	 *
	 * @return void
	 */
	public function testUnrecognisedValueMapsToUnknown(): void {
		$this->assertSame(LibresignStatusMapper::UNKNOWN, (new LibresignStatusMapper())->map('something-new'));
	}//end testUnrecognisedValueMapsToUnknown()

	/**
	 * An empty status value maps to UNKNOWN.
	 *
	 * @return void
	 */
	public function testEmptyValueMapsToUnknown(): void {
		$this->assertSame(LibresignStatusMapper::UNKNOWN, (new LibresignStatusMapper())->map(''));
	}//end testEmptyValueMapsToUnknown()
}//end class
