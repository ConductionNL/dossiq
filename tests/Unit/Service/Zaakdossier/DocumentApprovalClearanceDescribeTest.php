<?php

/**
 * Unit tests for DocumentApprovalClearance::describe(): the sentence a
 * refused document action carries, naming the route, step and actor it
 * waits on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The waiting-on sentence.
 */
#[CoversClass(DocumentApprovalClearance::class)]
class DocumentApprovalClearanceDescribeTest extends TestCase {

	/**
	 * Describe a clearance answer.
	 *
	 * @param array<string, mixed> $clearance The answer
	 *
	 * @return string
	 */
	private function describe(array $clearance): string {
		$service = new DocumentApprovalClearance(container: $this->createMock(ContainerInterface::class), logger: new NullLogger());

		return $service->describe($clearance);

	}//end describe()

	/**
	 * Each waiting entry names its route, step and actor, joined by semicolons.
	 *
	 * @return void
	 */
	public function testEveryWaitingEntryIsNamed(): void {
		$this->assertSame(
			'Besluit college, step "Paraaf", waiting on Wethouder Jansen; Juridische toets',
			$this->describe(
				[
					'routed' => true,
					'cleared' => false,
					'waitingOn' => [
						['routeName' => ' Besluit college ', 'stageName' => ' Paraaf ', 'actor' => ' Wethouder Jansen '],
						['route' => 'Juridische toets', 'stageName' => '', 'actor' => ''],
					],
				]
			)
		);

	}//end testEveryWaitingEntryIsNamed()

	/**
	 * An entry without a route name is an approval route; the routeName wins over route.
	 *
	 * @return void
	 */
	public function testFallbacks(): void {
		$this->assertSame(
			'an approval route, waiting on secretaris; Hoofdroute',
			$this->describe(['waitingOn' => [['actor' => 'secretaris'], ['routeName' => 'Hoofdroute', 'route' => 'oud']]])
		);

	}//end testFallbacks()

	/**
	 * Nothing waiting, or only unreadable entries, describes as the empty string.
	 *
	 * @return void
	 */
	public function testNothingWaitingIsEmpty(): void {
		$this->assertSame('', $this->describe(['routed' => true, 'cleared' => true, 'waitingOn' => []]));
		$this->assertSame('', $this->describe([]));
		$this->assertSame('', $this->describe(['waitingOn' => ['not-an-entry', 7]]));

	}//end testNothingWaitingIsEmpty()
}//end class
