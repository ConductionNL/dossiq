<?php

/**
 * A gesture answers its result, a refusal its sentence, code and status, and
 * a failure a logged 500 with the controller's failure sentence.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use LogicException;
use OCA\Dossiq\Controller\Support\AnswersCaseGestures;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersCaseGestures
 */
class AnswersCaseGesturesTest extends TestCase {

	/**
	 * A gesture controller over the given logger.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return object
	 */
	private function controller(LoggerInterface $logger): object {
		return new class ($logger) {
			use AnswersCaseGestures;

			private const REFUSAL_STATUS = ['taken' => Http::STATUS_CONFLICT];

			public function __construct(private readonly LoggerInterface $logger) {
			}

			public function run(callable $gesture): mixed {
				return $this->answer(caseId: 'c1', run: $gesture);
			}

			protected function refusalSentence(string $code): string {
				return match ($code) {
					'taken' => 'Someone has it.',
					default => 'It could not be changed.',
				};
			}
		};
	}

	public function testTheGestureResultIsTheAnswer(): void {
		$answer = $this->controller(logger: $this->createMock(LoggerInterface::class))->run(fn (): array => ['ok' => true]);

		$this->assertSame(Http::STATUS_OK, $answer->getStatus());
		$this->assertSame(['ok' => true], $answer->getData());
	}

	public function testARefusalCarriesSentenceCodeAndStatus(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringEndsWith(': the gesture was refused'), ['code' => 'taken', 'caseId' => 'c1']);

		$answer = $this->controller(logger: $logger)->run(fn () => throw new RuntimeException('taken'));

		$this->assertSame(Http::STATUS_CONFLICT, $answer->getStatus());
		$this->assertSame(['error' => 'Someone has it.', 'code' => 'taken'], $answer->getData());
	}

	public function testAnUnknownRefusalIsABadRequest(): void {
		$answer = $this->controller(logger: $this->createMock(LoggerInterface::class))->run(fn () => throw new RuntimeException('odd'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $answer->getStatus());
		$this->assertSame(['error' => 'It could not be changed.', 'code' => 'odd'], $answer->getData());
	}

	public function testAFailureIsALogged500(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringEndsWith(': the gesture failed'), ['exception' => 'boom', 'caseId' => 'c1']);

		$answer = $this->controller(logger: $logger)->run(fn () => throw new LogicException('boom'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $answer->getStatus());
		$this->assertSame(['error' => 'It could not be changed.'], $answer->getData());
	}
}
