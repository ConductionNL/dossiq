<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\BulkActionRegistrationListener;
use OCA\Dossiq\Listener\CaseMergedListener;
use OCA\Dossiq\Listener\Registrar\BulkActionRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use PHPUnit\Framework\TestCase;

/**
 * A merge of cases reaches the merged-case listener, and the bulk actions
 * are offered to OpenRegister's bulk action registration.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\BulkActionRegistrar
 */
class CaseMergeAndBulkActionRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testAMergeReachesTheMergedCaseListener(): void {
		(new CaseMergeRegistrar())->register(context: $this->recordingContext());

		self::assertSame([[ObjectsMergedEvent::class, CaseMergedListener::class, 0]], $this->listeners);
		self::assertSame([], $this->services);
	}//end testAMergeReachesTheMergedCaseListener()

	public function testTheBulkActionsListenToTheRegistrationEvent(): void {
		self::assertTrue(class_exists(BulkActionRegistrationEvent::class), 'the OpenRegister event this registrar waits for is loadable here');

		(new BulkActionRegistrar())->register(context: $this->recordingContext());

		self::assertSame([[BulkActionRegistrationEvent::class, BulkActionRegistrationListener::class, 0]], $this->listeners);
		self::assertSame([], $this->services);
	}//end testTheBulkActionsListenToTheRegistrationEvent()
}//end class
