<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\Registrar\BezwaarListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\BulkActionRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar;
use OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CrossAppListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\DerivedStatusListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ImmutabilityListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\TermijnTimerRegistrar;
use OCA\Dossiq\Listener\Registrar\WorkflowListenerRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * The top-level registrar runs every part in its order, so a listener that
 * must run before another (object listeners before the immutability guard,
 * the case type guards before the derived status) keeps its place.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\ListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ObjectListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ImmutabilityListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseTypeListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\DerivedStatusListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\BezwaarListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\WorkflowListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\TermijnTimerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseMergeRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\BulkActionRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CrossAppListenerRegistrar
 */
class ListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testEveryPartRunsOnceInItsOrder(): void {
		$parts = [
			new ObjectListenerRegistrar(),
			new ImmutabilityListenerRegistrar(),
			new CaseTypeListenerRegistrar(),
			new CasePriorityListenerRegistrar(),
			new DerivedStatusListenerRegistrar(),
			new BezwaarListenerRegistrar(),
			new WorkflowListenerRegistrar(),
			new TermijnTimerRegistrar(),
			new CaseMergeRegistrar(),
			new BulkActionRegistrar(),
			new CrossAppListenerRegistrar(),
		];
		foreach ($parts as $part) {
			$part->register(context: $this->recordingContext());
		}

		$expectedListeners = $this->listeners;
		$expectedServices  = array_column($this->services, 0);
		$this->listeners   = [];
		$this->services    = [];

		(new ListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame($expectedListeners, $this->listeners);
		self::assertSame($expectedServices, array_column($this->services, 0));
		self::assertGreaterThan(50, count($this->listeners));
	}//end testEveryPartRunsOnceInItsOrder()
}//end class
