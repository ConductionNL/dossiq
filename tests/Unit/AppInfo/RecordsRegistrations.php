<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * A registration context double that records, in order, every listener and
 * service a registrar registers.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 */
trait RecordsRegistrations {

	/**
	 * The listeners registered, as [event, listener, priority].
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	private array $listeners = [];

	/**
	 * The services registered, as [name, factory, shared].
	 *
	 * @var array<int, array{0: string, 1: callable, 2: bool}>
	 */
	private array $services = [];

	/**
	 * A context that records what is registered on it.
	 *
	 * @return IRegistrationContext The recording context.
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0): void {
				$this->listeners[] = [$event, $listener, $priority];
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->services[] = [$name, $factory, $shared];
			}
		);

		return $context;
	}//end recordingContext()
}//end trait
