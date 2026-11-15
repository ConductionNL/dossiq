<?php

/**
 * A filtered subscription carries its event, listener and schemas.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Registrar
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
 * @spec exclude Parameter object, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Registrar;

use OCA\Dossiq\Listener\Registrar\FilteredSubscription;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\Registrar\FilteredSubscription
 */
class FilteredSubscriptionTest extends TestCase {

	/**
	 * What it is built with reads back unchanged.
	 *
	 * @return void
	 */
	public function testKeepsWhatItIsBuiltWith(): void {
		$subscription = new FilteredSubscription(event: 'Event', listener: 'Listener', schemas: ['objection']);

		self::assertSame(expected: 'Event', actual: $subscription->event);
		self::assertSame(expected: 'Listener', actual: $subscription->listener);
		self::assertSame(expected: ['objection'], actual: $subscription->schemas);
	}//end testKeepsWhatItIsBuiltWith()
}//end class
