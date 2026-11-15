<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseMergedListener;
use OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use PHPUnit\Framework\TestCase;

/**
 * A merge of objects reaches the case merge listener.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar
 */
class CaseMergeRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheMergedEventReachesTheCaseMergeListener(): void {
		(new CaseMergeRegistrar())->register(context: $this->recordingContext());

		self::assertSame([[ObjectsMergedEvent::class, CaseMergedListener::class, 0]], $this->listeners);
		self::assertSame([], $this->services);
	}//end testTheMergedEventReachesTheCaseMergeListener()
}//end class
