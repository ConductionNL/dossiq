<?php

/**
 * Unit tests for DeclareNotificationTemplates' messages and failure paths.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\DeclareNotificationTemplates;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the step reports, and how it survives a registry that fails or answers oddly.
 */
#[CoversClass(DeclareNotificationTemplates::class)]
final class DeclareNotificationTemplatesFailuresTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every info line the step wrote.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Run the step over a registry the container answers.
	 *
	 * @param mixed $registry What the container hands out.
	 *
	 * @return void
	 */
	private function runStep(mixed $registry): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DeclareNotificationTemplates::TEMPLATE_REGISTRY)->willReturn($registry);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = $message;
			}
		);

		(new DeclareNotificationTemplates(container: $container, logger: $this->logger))->run($output);
	}//end runStep()

	/**
	 * Wire the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lines = [];
	}//end setUp()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$step = new DeclareNotificationTemplates(container: $this->createMock(ContainerInterface::class), logger: $this->logger);

		self::assertSame('Fill the Dutch notification template gaps dossiq can answer', $step->getName());
	}//end testTheStepNamesItself()

	/**
	 * Something that is not a registry, and a registry without a gap list, write nothing.
	 *
	 * @return void
	 */
	public function testANonRegistryOrOneWithoutGapsWritesNothing(): void {
		$this->runStep('not an object');
		$this->runStep(new class() {
		});

		self::assertSame(
			[
				'OpenRegister carries no notification templates on this instance, so none were filled.',
				'Every platform event already has text, so dossiq wrote none.',
			],
			$this->lines
		);
	}//end testANonRegistryOrOneWithoutGapsWritesNothing()

	/**
	 * A gap list that fails to read is logged; one that is not a list, or holds no names, counts as no gaps.
	 *
	 * @return void
	 */
	public function testAnUnreadableGapListCountsAsNoGaps(): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq notifications: the template gap list was not read, {reason}',
			['app' => 'dossiq', 'reason' => 'store offline']
		);

		$this->runStep(
			new class() {
				/**
				 * Fail.
				 *
				 * @return array<int,string>
				 */
				public function gaps(): array {
					throw new RuntimeException('store offline');
				}
			}
		);
		$this->runStep(
			new class() {
				/**
				 * Not a list.
				 *
				 * @return string
				 */
				public function gaps(): string {
					return 'object_created';
				}
			}
		);
		$this->runStep(
			new class() {
				/**
				 * No usable names.
				 *
				 * @return array<int,mixed>
				 */
				public function gaps(): array {
					return ['', 7, null];
				}
			}
		);

		self::assertSame(array_fill(0, 3, 'Every platform event already has text, so dossiq wrote none.'), $this->lines);
	}//end testAnUnreadableGapListCountsAsNoGaps()

	/**
	 * A registry that cannot be edited fills nothing; a failing write is logged and the rest still count.
	 *
	 * @return void
	 */
	public function testFailingWritesAreLoggedAndNotCounted(): void {
		$this->runStep(
			new class() {
				/**
				 * Two gaps.
				 *
				 * @return array<int,string>
				 */
				public function gaps(): array {
					return ['object_created'];
				}
			}
		);

		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq notifications: template {event} was not written, {reason}',
			['app' => 'dossiq', 'event' => 'object_created', 'reason' => 'read-only']
		);
		$this->runStep(
			new class() {
				/**
				 * Two gaps dossiq can answer.
				 *
				 * @return array<int,string>
				 */
				public function gaps(): array {
					return ['object_created', 'object_transitioned'];
				}

				/**
				 * Refuse one.
				 *
				 * @param string                   $event    The event.
				 * @param array<string,mixed>|null $template The template.
				 *
				 * @return void
				 */
				public function edit(string $event, ?array $template): void {
					if ($event === 'object_created') {
						throw new RuntimeException('read-only');
					}
				}
			}
		);

		self::assertSame(
			[
				'Filled 0 of 1 notification template gap(s) with Dutch text.',
				'Filled 1 of 2 notification template gap(s) with Dutch text.',
			],
			$this->lines
		);
	}//end testFailingWritesAreLoggedAndNotCounted()
}//end class
