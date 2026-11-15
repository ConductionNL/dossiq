<?php

/**
 * Unit tests for AddressesTheCase: an action addresses the case's recipient,
 * applicant and initiator through the correspondent writer when the container
 * has one, and nobody when it does not or the case has no id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\AddressesTheCase;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\Dossiq\Service\Actions\AddressesTheCase
 */
class AddressesTheCaseTest extends TestCase {

	/**
	 * A handler using the trait over the given container.
	 */
	private function handler(ContainerInterface $container): object {
		return new class($container) {
			use AddressesTheCase;

			public function __construct(public ContainerInterface $container) {
			}

			public function parties(string $caseId): array {
				return $this->addressedParties(caseId: $caseId);
			}
		};
	}//end handler()

	public function testTheRecipientApplicantAndInitiatorAreAddressed(): void {
		$writer = $this->createMock(CorrespondentWriter::class);
		$writer->expects($this->once())->method('addressedParties')
			->with('case-1', ['geadresseerde', 'aanvrager', 'initiator'])
			->willReturn([['name' => 'J. Jansen']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(CorrespondentWriter::class)->willReturn(true);
		$container->method('get')->with(CorrespondentWriter::class)->willReturn($writer);

		$this->assertSame([['name' => 'J. Jansen']], $this->handler($container)->parties('case-1'));
	}//end testTheRecipientApplicantAndInitiatorAreAddressed()

	public function testWithoutAWriterNobodyIsAddressed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->expects($this->never())->method('get');

		$this->assertSame([], $this->handler($container)->parties('case-1'));
	}//end testWithoutAWriterNobodyIsAddressed()

	public function testSomethingElseUnderTheWritersNameAddressesNobody(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn(new \stdClass());

		$this->assertSame([], $this->handler($container)->parties('case-1'));
	}//end testSomethingElseUnderTheWritersNameAddressesNobody()

	public function testACaseWithoutAnIdAddressesNobody(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('has');

		$this->assertSame([], $this->handler($container)->parties('  '));
	}//end testACaseWithoutAnIdAddressesNobody()
}//end class
