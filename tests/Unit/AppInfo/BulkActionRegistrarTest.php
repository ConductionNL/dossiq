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
 * With OpenRegister's bulk action event present, dossiq listens on it to
 * register its case actions.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BulkActionRegistrar
 */
class BulkActionRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheRegistrationEventReachesTheListener(): void {
		self::assertTrue(class_exists(BulkActionRegistrationEvent::class), 'The suite stubs the OpenRegister event.');

		(new BulkActionRegistrar())->register(context: $this->recordingContext());

		self::assertSame([[BulkActionRegistrationEvent::class, BulkActionRegistrationListener::class, 0]], $this->listeners);
	}//end testTheRegistrationEventReachesTheListener()
}//end class
