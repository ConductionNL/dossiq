<?php

/**
 * The internal write marker counts nested writes and always releases them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\InternalWriteMarker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\InternalWriteMarker
 */
class InternalWriteMarkerTest extends TestCase {
	/**
	 * Marked inside a write, also a nested one, and released after each.
	 *
	 * @return void
	 */
	public function testAWriteIsMarkedUntilTheOutermostEnds(): void {
		$marker = new InternalWriteMarker();
		$seen = [];

		$result = $marker->runInternal(
			objectId: 'req-1',
			write: function () use ($marker, &$seen): string {
				$seen[] = $marker->isInternal(objectId: 'req-1');
				$marker->runInternal(objectId: 'req-1', write: static fn (): null => null);
				$seen[] = $marker->isInternal(objectId: 'req-1');
				$seen[] = $marker->isInternal(objectId: 'req-2');
				return 'saved';
			}
		);

		$this->assertFalse(condition: $marker->isInternal(objectId: 'req-1'), message: 'released after the write');
		$this->assertSame(expected: [true, true, false], actual: $seen, message: 'marked inside and after a nested write, and only for its own object');
		$this->assertSame(expected: 'saved', actual: $result);
	}//end testAWriteIsMarkedUntilTheOutermostEnds()

	/**
	 * A write that throws is released too.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReleased(): void {
		$marker = new InternalWriteMarker();

		try {
			$marker->runInternal(objectId: 'req-1', write: static fn (): never => throw new RuntimeException('save refused'));
		} catch (RuntimeException $refused) {
			$this->assertSame(expected: 'save refused', actual: $refused->getMessage());
		}

		$this->assertFalse(condition: $marker->isInternal(objectId: 'req-1'), message: 'a failed write must not leave the marker set');
	}//end testAFailedWriteIsReleased()
}//end class
