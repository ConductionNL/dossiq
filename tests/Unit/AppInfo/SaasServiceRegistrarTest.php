<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\OrganisationStatusChangeListener;
use OCA\Dossiq\Listener\QuotaConsumptionListener;
use OCA\Dossiq\Listener\Registrar\SaasServiceRegistrar;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * An organisation's status change is followed, and quota is checked before a
 * create and counted after it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\SaasServiceRegistrar
 */
class SaasServiceRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testStatusChangesAndQuotaOnBothSidesOfACreateAreFollowed(): void {
		(new SaasServiceRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[OrganisationUpdatedEvent::class, OrganisationStatusChangeListener::class, 0],
				[ObjectCreatingEvent::class, QuotaConsumptionListener::class, 0],
				[ObjectCreatedEvent::class, QuotaConsumptionListener::class, 0],
			],
			$this->listeners
		);
	}//end testStatusChangesAndQuotaOnBothSidesOfACreateAreFollowed()
}//end class
