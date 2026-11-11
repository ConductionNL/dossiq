<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use PHPUnit\Framework\TestCase;

/**
 * A frozen field compares by its canonical form: key order at any depth is no change, list order is.
 */
class FrozenRecordCanonicalTest extends TestCase {

	/**
	 * Whether the guard lets the frozen `amount` go from `$stored` to `$incoming`.
	 *
	 * @param mixed $stored   The stored value.
	 * @param mixed $incoming The incoming value.
	 *
	 * @return bool Whether the update passes.
	 */
	private function passes(mixed $stored, mixed $incoming): bool {
		try {
			(new FrozenRecordGuard())->assertUpdatable(
				definition: FrozenRecordFixture::definition(),
				stored: FrozenRecordFixture::record(status: 'issued', extra: ['amount' => $stored]),
				incoming: FrozenRecordFixture::record(status: 'issued', extra: ['amount' => $incoming]),
			);
		} catch (RefusedException $e) {
			return false;
		}

		return true;
	}//end passes()

	/**
	 * Key order is no change, at every depth.
	 *
	 * @return void
	 */
	public function testKeyOrderIsNoChange(): void {
		self::assertTrue($this->passes(['x' => 1, 'y' => ['b' => 1, 'a' => [2, 3]]], ['y' => ['a' => [2, 3], 'b' => 1], 'x' => 1]));
	}//end testKeyOrderIsNoChange()

	/**
	 * List order and nested values are a change.
	 *
	 * @return void
	 */
	public function testListOrderIsAChange(): void {
		self::assertFalse($this->passes(['y' => ['a' => [2, 3]]], ['y' => ['a' => [3, 2]]]));
		self::assertFalse($this->passes(['y' => ['a' => 1]], ['y' => ['a' => '1']]));
	}//end testListOrderIsAChange()
}//end class
