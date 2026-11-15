<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\BulkActionRegistrationListener;
use OCA\Dossiq\Listener\Registrar\BulkActionRegistrar;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use PHPUnit\Framework\TestCase;

/**
 * Where OpenRegister publishes its bulk-action registration event, dossiq
 * listens to it to offer its case bulk actions.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BulkActionRegistrar
 */
class BulkActionRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheBulkActionListenerJoinsOpenRegistersRegistrationEvent(): void {
		self::assertTrue(class_exists(BulkActionRegistrationEvent::class), 'OpenRegister publishes the event in this test run');

		(new BulkActionRegistrar())->register(context: $this->recordingContext());

		self::assertSame([[BulkActionRegistrationEvent::class, BulkActionRegistrationListener::class, 0]], $this->listeners);
		self::assertSame([], $this->services);
	}//end testTheBulkActionListenerJoinsOpenRegistersRegistrationEvent()
}//end class
