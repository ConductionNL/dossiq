<?php

/**
 * What the flow migrations answer when they cannot start.
 *
 * Pins the early answers of AutomaticActionFlowMigrator::migrate() and
 * WorkflowTemplateFlowMigrator::migrate() before they build them through one
 * helper: an empty summary (every count 0, no rows) carrying the reason, for
 * an absent OpenRegister, an absent FlowService, and (templates only) an
 * object service without runAs().
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use stdClass;

/**
 * Both migrations answer the same empty summary for the same reason.
 *
 * @covers \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
class FlowMigrationSummaryCopiesTest extends TestCase {
	/**
	 * Both migrations.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function migrations(): array {
		return [
			'automatic actions' => [AutomaticActionFlowMigrator::class],
			'workflow templates' => [WorkflowTemplateFlowMigrator::class],
		];
	}//end migrations()

	/**
	 * Without OpenRegister nothing starts and the summary says so.
	 *
	 * @param class-string $class The migration.
	 *
	 * @dataProvider migrations
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingStarts(string $class): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects(self::never())->method('get');

		self::assertSame(
			expected: $this->empty(note: 'OpenRegister is not available.'),
			actual: $this->migrate(class: $class, objectService: null, container: $container)
		);
	}//end testWithoutOpenRegisterNothingStarts()

	/**
	 * Without a FlowService nothing starts and the summary says so.
	 *
	 * @param class-string $class The migration.
	 *
	 * @dataProvider migrations
	 *
	 * @return void
	 */
	public function testWithoutAFlowServiceNothingStarts(string $class): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));

		self::assertSame(
			expected: $this->empty(note: 'OpenRegister exposes no FlowService on this instance.'),
			actual: $this->migrate(class: $class, objectService: new stdClass(), container: $container)
		);
	}//end testWithoutAFlowServiceNothingStarts()

	/**
	 * The template migration needs runAs() to own the flows it creates.
	 *
	 * @return void
	 */
	public function testTheTemplateMigrationNeedsRunAs(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(new stdClass());

		self::assertSame(
			expected: $this->empty(note: 'OpenRegister exposes no runAs(); the migration needs an owner for the flows it creates.'),
			actual: $this->migrate(class: WorkflowTemplateFlowMigrator::class, objectService: new stdClass(), container: $container)
		);
	}//end testTheTemplateMigrationNeedsRunAs()

	/**
	 * The empty summary with the given note.
	 *
	 * @param string $note Why nothing happened.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function empty(string $note): array {
		return ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => [], 'note' => $note];
	}//end empty()

	/**
	 * Run a migration as a user over the given object service and container.
	 *
	 * @param class-string       $class         The migration.
	 * @param object|null        $objectService The object service, or null.
	 * @param ContainerInterface $container     The container.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function migrate(string $class, ?object $objectService, ContainerInterface $container): array {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$arguments[] = match ($type->getName()) {
				SettingsService::class => $settings,
				ContainerInterface::class => $container,
				default => $this->createMock(originalClassName: $type->getName()),
			};
		}

		return (new $class(...$arguments))->migrate($this->createMock(originalClassName: IUser::class), false);
	}//end migrate()
}//end class
