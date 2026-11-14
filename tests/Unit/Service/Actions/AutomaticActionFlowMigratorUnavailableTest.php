<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Actions
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Without OpenRegister, or without its FlowService, the migration says why and
 * neither reads an action nor runs as the user.
 *
 * @covers \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator
 * @uses   \OCA\Dossiq\Service\Flow\FlowMigrationSummary
 * @uses   \OCA\Dossiq\Service\Flow\RetiredDocumentSteps
 * @uses   \OCA\Dossiq\Service\Flow\RetiredNodeMap
 * @uses   \OCA\Dossiq\Service\Flow\RetiredNodeTranslator
 * @uses   \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @uses   \OCA\Dossiq\Service\Flow\RetiredWebhookSteps
 */
class AutomaticActionFlowMigratorUnavailableTest extends TestCase {

	/**
	 * Debug messages logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $debug = [];

	/**
	 * Build the migrator.
	 *
	 * @param object|null $objectService The ObjectService, or null when OpenRegister is absent.
	 * @param ContainerInterface $container The container FlowService is resolved from.
	 *
	 * @return AutomaticActionFlowMigrator The migrator.
	 */
	private function migrator(?object $objectService, ContainerInterface $container): AutomaticActionFlowMigrator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		$syntax = new RetiredTemplateSyntax();

		return new AutomaticActionFlowMigrator(
			$settings,
			$container,
			$this->createMock(IAppConfig::class),
			$logger,
			new RetiredNodeMap(),
			new RetiredNodeTranslator(
				$settings,
				$container,
				$this->createMock(IL10N::class),
				$syntax,
				new RetiredDocumentSteps($syntax),
				new RetiredWebhookSteps($this->createMock(IEventDispatcher::class), $this->createMock(IUserSession::class), $syntax)
			),
		);
	}

	/**
	 * An ObjectService that fails the test if the migration reaches it.
	 *
	 * @return object The fake.
	 */
	private function untouchableObjectService(): object {
		return new class {
			/**
			 * @param IUser $user The acting user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed Never answers.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				throw new RuntimeException('runAs must not be reached');
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed> Never answers.
			 */
			public function findAll(array $query): array {
				throw new RuntimeException('findAll must not be reached');
			}
		};
	}

	/**
	 * Without OpenRegister the summary says so and nothing is migrated.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItReportsWhy(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$summary = $this->migrator(null, $container)->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame('OpenRegister is not available.', $summary['note']);
		$this->assertSame(0, $summary['total']);
		$this->assertSame([], $summary['rows']);
	}

	/**
	 * Without a FlowService the miss is logged at debug level, the summary says
	 * so, and the migration never runs as the user.
	 *
	 * @return void
	 */
	public function testWithoutAFlowServiceItReportsWhy(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$summary = $this->migrator($this->untouchableObjectService(), $container)
			->migrate(user: $this->createMock(IUser::class), dryRun: false);

		$this->assertSame('OpenRegister exposes no FlowService on this instance.', $summary['note']);
		$this->assertSame(0, $summary['total']);
		$this->assertSame(['Dossiq: FlowService could not be resolved'], $this->debug);
	}
}
