<?php

/**
 * Unit tests for RunsUnderSystemIdentity: repair work runs inside the object
 * service's system identity when the service offers one, and directly
 * otherwise.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 */
class RunsUnderSystemIdentityTest extends TestCase {

	private function host(): object {
		return new class {
			use RunsUnderSystemIdentity;

			public function run(?object $objectService, callable $work): void {
				$this->withSystemIdentity(objectService: $objectService, work: $work);
			}
		};
	}//end host()

	public function testTheWorkRunsInsideTheSystemIdentityWhenTheServiceOffersOne(): void {
		$service = new class {
			public bool $inside = false;

			/** @var list<bool> */
			public array $seen = [];

			public function runAsSystem(callable $work): void {
				$this->inside = true;
				$work();
				$this->inside = false;
			}
		};

		$this->host()->run(
			$service,
			function () use ($service): void {
				$service->seen[] = $service->inside;
			}
		);

		$this->assertSame([true], $service->seen);
	}//end testTheWorkRunsInsideTheSystemIdentityWhenTheServiceOffersOne()

	public function testTheWorkRunsDirectlyWhenTheServiceHasNoSystemIdentity(): void {
		$calls = 0;
		$this->host()->run(
			new class {
			},
			function () use (&$calls): void {
				$calls++;
			}
		);

		$this->assertSame(1, $calls);
	}//end testTheWorkRunsDirectlyWhenTheServiceHasNoSystemIdentity()

	public function testTheWorkRunsDirectlyWithoutAnObjectService(): void {
		$calls = 0;
		$this->host()->run(
			null,
			function () use (&$calls): void {
				$calls++;
			}
		);

		$this->assertSame(1, $calls);
	}//end testTheWorkRunsDirectlyWithoutAnObjectService()
}//end class
