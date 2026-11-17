<?php

/**
 * EffectRun unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\EffectRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\EffectRun
 */
class EffectRunTest extends TestCase {

	/**
	 * The effect's type is read once, trimmed, and the inputs are kept as given.
	 *
	 * @return void
	 */
	public function testTheTypeIsTrimmedAndTheInputsKept(): void {
		$run = new EffectRun(effect: ['type' => '  setStatus ', 'status' => 'closed'], case: ['id' => 'c-1'], context: ['actor' => 'alice']);

		self::assertSame('setStatus', $run->type);
		self::assertSame(['type' => '  setStatus ', 'status' => 'closed'], $run->effect);
		self::assertSame(['id' => 'c-1'], $run->case);
		self::assertSame(['actor' => 'alice'], $run->context);
	}//end testTheTypeIsTrimmedAndTheInputsKept()

	/**
	 * An effect without a type, or with a non-string one, has the empty type.
	 *
	 * @return void
	 */
	public function testAnEffectWithoutATypeHasTheEmptyType(): void {
		self::assertSame('', (new EffectRun(effect: [], case: [], context: []))->type);
		self::assertSame('', (new EffectRun(effect: ['type' => null], case: [], context: []))->type);
		self::assertSame('7', (new EffectRun(effect: ['type' => 7], case: [], context: []))->type);
	}//end testAnEffectWithoutATypeHasTheEmptyType()
}//end class
