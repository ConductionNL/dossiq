<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow\Support;

use OCA\Dossiq\Flow\Support\RunsInjectedHandler;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The node runs exactly the handler it was given.
 *
 * @covers \OCA\Dossiq\Flow\Support\RunsInjectedHandler
 */
class RunsInjectedHandlerTest extends TestCase {

	public function testTheInjectedHandlerIsTheOneRun(): void {
		$handler = $this->createMock(ActionHandlerInterface::class);
		$node = new class ($handler) {
			use RunsInjectedHandler;

			public function __construct(private readonly ActionHandlerInterface $handler) {
			}

			public function exposed(): ActionHandlerInterface {
				return $this->handler();
			}
		};

		$this->assertSame($handler, $node->exposed());
	}
}
