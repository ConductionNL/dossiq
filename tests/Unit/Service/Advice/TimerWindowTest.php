<?php

/**
 * Tests for TimerWindow: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use DateTimeImmutable;
use OCA\Dossiq\Service\Advice\TimerWindow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TimerWindow.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TimerWindowTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$anchor = new DateTimeImmutable();
		$subject = new TimerWindow(
			anchor: $anchor,
			slaDays: 7
		);

		self::assertSame(expected: $anchor, actual: $subject->anchor);
		self::assertSame(expected: 7, actual: $subject->slaDays);
	}//end testPropertiesReadBack()
}//end class
