<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseArchiveState;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What one call to the archive handler answers, refuses and logs.
 */
class CaseArchiveStateCallTest extends TestCase {

	/**
	 * The error lines logged, as message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * The service over a handler answering `$answer`, or throwing it.
	 *
	 * @param mixed  $answer The answer, or a Throwable to throw.
	 * @param string $schema The case schema slug.
	 *
	 * @return CaseArchiveState The service.
	 */
	private function state(mixed $answer, string $schema = 'case'): CaseArchiveState {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['case_schema', '', $schema]]);
		$handler = new class ($answer) {
			/**
			 * @param mixed $answer The answer or the throwable.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param string $id       The object id.
			 * @param string $reason   Why.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed The answer.
			 */
			public function unarchive(string $id, string $reason, string $register, string $schema): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($handler);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new CaseArchiveState(settings: $settings, container: $container, logger: $logger);
	}//end state()

	/**
	 * A handler answering no array is read as an empty answer.
	 *
	 * @return void
	 */
	public function testANonArrayAnswerIsEmpty(): void {
		self::assertSame([], $this->state(answer: true)->clear(caseId: 'case-1', reason: 'r'));
		self::assertSame([], $this->errors);
	}//end testANonArrayAnswerIsEmpty()

	/**
	 * A missing case schema refuses like a missing register.
	 *
	 * @return void
	 */
	public function testAMissingSchemaRefuses(): void {
		try {
			$this->state(answer: [], schema: '')->clear(caseId: 'case-1', reason: 'r');
			self::fail('Not refused');
		} catch (RefusedException $e) {
			self::assertSame(
				['archive-state-unavailable', RefusedException::STATUS_INDETERMINATE, 'The case register is not configured, so the archive could not be reached.'],
				[$e->getRule(), $e->getStatus(), $e->getSentence()]
			);
		}
	}//end testAMissingSchemaRefuses()

	/**
	 * A refusal the handler throws passes through as it is, unlogged.
	 *
	 * @return void
	 */
	public function testAHandlersRefusalPassesThrough(): void {
		$refusal = new RefusedException(rule: 'own-rule', sentence: 'Own sentence.', status: RefusedException::STATUS_REFUSED);
		try {
			$this->state(answer: $refusal)->clear(caseId: 'case-1', reason: 'r');
			self::fail('Not refused');
		} catch (RefusedException $e) {
			self::assertSame($refusal, $e);
		}

		self::assertSame([], $this->errors);
	}//end testAHandlersRefusalPassesThrough()

	/**
	 * Any other failure is logged with the case, the verb and the message, and refused with that message.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndRefused(): void {
		try {
			$this->state(answer: new RuntimeException('down'))->clear(caseId: 'case-1', reason: 'r');
			self::fail('Not refused');
		} catch (RefusedException $e) {
			self::assertSame(['archive-state-refused', RefusedException::STATUS_REFUSED, 'down'], [$e->getRule(), $e->getStatus(), $e->getSentence()]);
		}

		self::assertSame(
			[['CaseArchiveState: the platform refused an archive state change', ['caseId' => 'case-1', 'verb' => 'unarchive', 'exception' => 'down']]],
			$this->errors
		);
	}//end testAFailureIsLoggedAndRefused()
}//end class
