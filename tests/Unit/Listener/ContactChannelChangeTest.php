<?php

/**
 * Unit test for ContactChannelChange.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ContactChannelChange;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Listener\ContactChannelChange
 */
class ContactChannelChangeTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$subject = 'subject-value';
		$organisation = 'organisation-value';
		$channel = 'channel-value';

		$value = new ContactChannelChange(
			subject: $subject,
			organisation: $organisation,
			channel: $channel,
		);

		$this->assertSame(expected: $subject, actual: $value->subject);
		$this->assertSame(expected: $organisation, actual: $value->organisation);
		$this->assertSame(expected: $channel, actual: $value->channel);
	}//end testEveryPropertyReadsBack()
}//end class
