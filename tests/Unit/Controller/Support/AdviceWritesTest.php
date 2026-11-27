<?php

/**
 * AdviceWrites Unit Tests
 *
 * Each advice write answered: a transition (400 on a refusal, a logged 500
 * otherwise), a request (the body stamped with the case, the requester and
 * `open`; 201, 400 on an unreadable value, a logged 500 naming the case),
 * and a reminder (403 on a refusal, a logged 500 without the internal
 * message).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use InvalidArgumentException;
use LogicException;
use OCA\Dossiq\Controller\Support\AdviceWrites;
use OCA\Dossiq\Kernel\Contracts\AdviceServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for AdviceWrites.
 *
 * @covers \OCA\Dossiq\Controller\Support\AdviceWrites
 */
final class AdviceWritesTest extends TestCase {

	/**
	 * A transition answers the advice; a refusal is a 400 with its message, anything else a logged 500.
	 *
	 * @return void
	 */
	public function testATransitionAnswersItsOutcome(): void {
		$service = $this->createMock(originalClassName: AdviceServiceInterface::class);
		$service->method('transitionStatus')->willReturnCallback(
			static function (string $id, string $to): array {
				if ($to === 'closed') {
					throw new RuntimeException('not from open');
				}

				if ($to === 'broken') {
					throw new LogicException('boom');
				}

				return ['id' => $id, 'status' => $to];
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq: advice transition failed: boom');
		$writes = new AdviceWrites(adviceService: $service, logger: $logger);

		$this->assertSame(expected: ['id' => 'a-1', 'status' => 'given'], actual: $writes->transition(id: 'a-1', to: 'given', data: [])->getData());
		$refused = $writes->transition(id: 'a-1', to: 'closed', data: []);
		$this->assertSame(expected: [400, ['error' => 'not from open']], actual: [$refused->getStatus(), $refused->getData()]);
		$failed = $writes->transition(id: 'a-1', to: 'broken', data: []);
		$this->assertSame(expected: [500, ['error' => 'Could not transition advice request']], actual: [$failed->getStatus(), $failed->getData()]);
	}//end testATransitionAnswersItsOutcome()

	/**
	 * A request is stamped with the case, the requester and `open`, and answered 201.
	 *
	 * @return void
	 */
	public function testARequestIsStampedAndCreated(): void {
		$service = $this->createMock(originalClassName: AdviceServiceInterface::class);
		$service->expects($this->once())->method('requestAdvice')->with(
			'case-1',
			['advisor' => 'jurist', 'caseRef' => 'case-1', 'requestedBy' => 'behandelaar', 'status' => 'open'],
			'behandelaar'
		)->willReturn(['id' => 'a-1']);
		$writes = new AdviceWrites(adviceService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$created = $writes->request(id: 'case-1', uid: 'behandelaar', data: ['advisor' => 'jurist', 'status' => 'given', 'caseRef' => 'other']);

		$this->assertSame(expected: [201, ['id' => 'a-1']], actual: [$created->getStatus(), $created->getData()]);
	}//end testARequestIsStampedAndCreated()

	/**
	 * An unreadable value is a 400; any other failure a logged 500 naming the case.
	 *
	 * @return void
	 */
	public function testARequestThatFailsIsAnswered(): void {
		$service = $this->createMock(originalClassName: AdviceServiceInterface::class);
		$service->method('requestAdvice')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'case-bad-date') {
					throw new InvalidArgumentException('deadline is not a date');
				}

				throw new LogicException('store down');
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Failed to create advice request for case case-2: store down', ['app' => 'dossiq']);
		$writes = new AdviceWrites(adviceService: $service, logger: $logger);

		$bad = $writes->request(id: 'case-bad-date', uid: 'behandelaar', data: []);
		$this->assertSame(expected: [400, ['error' => 'deadline is not a date']], actual: [$bad->getStatus(), $bad->getData()]);
		$failed = $writes->request(id: 'case-2', uid: 'behandelaar', data: []);
		$this->assertSame(expected: [500, ['error' => 'Could not create advice request: store down']], actual: [$failed->getStatus(), $failed->getData()]);
	}//end testARequestThatFailsIsAnswered()

	/**
	 * A reminder is acknowledged; a refusal is a 403, anything else a 500 without the internal message.
	 *
	 * @return void
	 */
	public function testAReminderAnswersItsOutcome(): void {
		$calls = 0;
		$service = $this->createMock(originalClassName: AdviceServiceInterface::class);
		$service->method('dispatchReminderAsUser')->willReturnCallback(
			static function () use (&$calls): void {
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('not your advice');
				}

				if ($calls === 3) {
					throw new LogicException('mailer down');
				}
			}
		);
		$writes = new AdviceWrites(adviceService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(expected: ['status' => 'reminded'], actual: $writes->remind(id: 'a-1')->getData());
		$refused = $writes->remind(id: 'a-1');
		$this->assertSame(expected: [403, ['error' => 'not your advice']], actual: [$refused->getStatus(), $refused->getData()]);
		$failed = $writes->remind(id: 'a-1');
		$this->assertSame(expected: [500, ['error' => 'Could not send reminder']], actual: [$failed->getStatus(), $failed->getData()]);
	}//end testAReminderAnswersItsOutcome()
}//end class
