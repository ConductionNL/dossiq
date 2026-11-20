<?php

/**
 * Tests for HandoverAct: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\HandoverAct;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HandoverAct.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
 */
class HandoverActTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new HandoverAct(
			fromUser: 'fromUser-value',
			toUser: 'toUser-value',
			actor: 'actor-value',
			batchId: 'batchId-value',
			now: 'now-value'
		);

		self::assertSame(expected: 'fromUser-value', actual: $subject->fromUser);
		self::assertSame(expected: 'toUser-value', actual: $subject->toUser);
		self::assertSame(expected: 'actor-value', actual: $subject->actor);
		self::assertSame(expected: 'batchId-value', actual: $subject->batchId);
		self::assertSame(expected: 'now-value', actual: $subject->now);
	}//end testPropertiesReadBack()
}//end class
