<?php

/**
 * Unit test for Bounce.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\Bounce;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Email\Bounce
 */
class BounceTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$recipient = 'recipient-value';
		$reason = 'reason-value';
		$actorId = 'actorId-value';

		$value = new Bounce(
			recipient: $recipient,
			reason: $reason,
			actorId: $actorId,
		);

		$this->assertSame(expected: $recipient, actual: $value->recipient);
		$this->assertSame(expected: $reason, actual: $value->reason);
		$this->assertSame(expected: $actorId, actual: $value->actorId);
	}//end testEveryPropertyReadsBack()
}//end class
