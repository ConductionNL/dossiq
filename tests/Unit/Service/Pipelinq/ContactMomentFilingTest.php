<?php

/**
 * Unit tests for ContactMomentBridge filing on and off an extra case: pipelinq
 * does the filing, a 200 is a filing, its refusal is the reason, and a
 * pipelinq that does not answer files nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Pipelinq\ContactMomentBridge
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class ContactMomentFilingTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A bridge whose gateway reaches the given filing service, or none.
	 *
	 * @param object|null $filing Pipelinq's filing service.
	 */
	private function bridge(?object $filing): ContactMomentBridge {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($filing): object {
				if ($id !== PipelinqGateway::CONTACT_MOMENT_FILING || $filing === null) {
					throw new RuntimeException('nothing answers to ' . $id);
				}

				return $filing;
			}
		);

		return new ContactMomentBridge(new PipelinqGateway($container, $this->createMock(LoggerInterface::class)), $this->logger);
	}//end bridge()

	/**
	 * A filing service answering one fixed result and recording its calls.
	 *
	 * @param mixed $answer What both acts answer.
	 */
	private function filing(mixed $answer): object {
		return new class($answer) {
			/** @var list<array<string, mixed>> */
			public array $calls = [];

			public function __construct(private mixed $answer) {
			}

			public function fileOnAlsoCase(string $momentId, string $caseId): mixed {
				$this->calls[] = ['fileOnAlsoCase', $momentId, $caseId];
				return $this->answer;
			}

			public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary): mixed {
				$this->calls[] = ['unfileFromCase', $momentId, $caseId, $newPrimary];
				return $this->answer;
			}
		};
	}//end filing()

	public function testA200FilesAndUnfilesWithTrimmedIds(): void {
		$filing = $this->filing(['status' => 200]);
		$bridge = $this->bridge($filing);
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(['filed' => true, 'reason' => ''], $bridge->fileOnAlsoCase(' m-1 ', ' case-2 '));
		$this->assertSame(['filed' => true, 'reason' => ''], $bridge->unfileFromCase('m-1', 'case-2', 'case-3'));
		$this->assertSame(['filed' => true, 'reason' => ''], $bridge->unfileFromCase('m-1', 'case-2'));
		$this->assertSame(
			[
				['fileOnAlsoCase', 'm-1', 'case-2'],
				['unfileFromCase', 'm-1', 'case-2', 'case-3'],
				['unfileFromCase', 'm-1', 'case-2', null],
			],
			$filing->calls
		);
	}//end testA200FilesAndUnfilesWithTrimmedIds()

	public function testARefusalIsLoggedAndBecomesTheReason(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'Dossiq pipelinq: a contact moment was not unfiled, and the refusal is what a handler reads: last_case',
			['status' => 409]
		);

		$result = $this->bridge($this->filing(['status' => 409, 'error' => 'last_case']))->unfileFromCase('m-1', 'case-2');

		$this->assertSame(['filed' => false, 'reason' => 'last_case'], $result);
	}//end testARefusalIsLoggedAndBecomesTheReason()

	public function testARefusalWithoutAnErrorGetsAGenericReason(): void {
		$this->logger->expects($this->once())->method('info')->with(
			'Dossiq pipelinq: a contact moment was not filed, and the refusal is what a handler reads: ',
			['status' => 0]
		);

		$this->assertSame(
			['filed' => false, 'reason' => 'pipelinq refused the act'],
			$this->bridge($this->filing([]))->fileOnAlsoCase('m-1', 'case-2')
		);
	}//end testARefusalWithoutAnErrorGetsAGenericReason()

	public function testWithoutAnAnswerNothingIsFiledAndTheGatewaysReasonIsGiven(): void {
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(
			['filed' => false, 'reason' => 'pipelinq does not serve this capability on this instance'],
			$this->bridge(null)->fileOnAlsoCase('m-1', 'case-2')
		);
		$this->assertSame(
			['filed' => false, 'reason' => ''],
			$this->bridge($this->filing('ok'))->fileOnAlsoCase('m-1', 'case-2')
		);
	}//end testWithoutAnAnswerNothingIsFiledAndTheGatewaysReasonIsGiven()
}//end class
