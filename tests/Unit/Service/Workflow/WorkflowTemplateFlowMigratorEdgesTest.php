<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Workflow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The migration's edges: an OpenRegister without runAs(), a template schema
 * that is not configured or cannot be read, and a template's own description.
 *
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator
 * @uses   \OCA\Dossiq\Service\Flow\FlowMigrationSummary
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\RowList
 */
class WorkflowTemplateFlowMigratorEdgesTest extends TestCase {

	/**
	 * Flow documents written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Warnings logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build the migrator.
	 *
	 * @param object $objectService The fake ObjectService.
	 * @param bool $templateSchemaConfigured Whether the template schema is configured.
	 *
	 * @return WorkflowTemplateFlowMigrator The migrator.
	 */
	private function migrator(object $objectService, bool $templateSchemaConfigured = true): WorkflowTemplateFlowMigrator {
		$written = &$this->written;
		$flowService = new class($written) {
			/**
			 * @param array<int, array<string, mixed>> $written Writes.
			 */
			public function __construct(private array &$written) {
			}

			/**
			 * @param string $app The app id.
			 * @param string|null $a Unused.
			 * @param string|null $b Unused.
			 * @param int $limit Page size.
			 * @param int $offset Page offset.
			 *
			 * @return array<int, object> No flows.
			 */
			public function findAll(string $app, ?string $a = null, ?string $b = null, int $limit = 100, int $offset = 0): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $document The flow document.
			 * @param string|null $uuid The uuid.
			 *
			 * @return object The stored flow.
			 */
			public function save(array $document, ?string $uuid = null): object {
				$this->written[] = $document;

				return new class {
					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'flow-new';
					}
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($templateSchemaConfigured): string {
				return match ($key) {
					'workflow_template_schema' => ($templateSchemaConfigured === true ? 'workflowTemplate' : ''),
					'status_type_schema' => 'statusType',
					default => 'dossiq',
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($flowService);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new WorkflowTemplateFlowMigrator($settings, $container, $logger);
	}

	/**
	 * An ObjectService that runs as the user and answers template reads.
	 *
	 * @param array<int, array<string, mixed>> $templates The templates.
	 * @param bool $templatesThrow Whether a template read throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(array $templates, bool $templatesThrow = false): object {
		return new class($templates, $templatesThrow) {
			/**
			 * @param array<int, array<string, mixed>> $templates The templates.
			 * @param bool $templatesThrow Whether a template read throws.
			 */
			public function __construct(private array $templates, private bool $templatesThrow) {
			}

			/**
			 * @param IUser $user The acting user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($schema === 'statusType') {
					return [];
				}

				if ($this->templatesThrow === true) {
					throw new RuntimeException('templates unavailable');
				}

				return $this->templates;
			}
		};
	}

	/**
	 * A template with one transition.
	 *
	 * @param string $description The template's own description.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(string $description = ''): array {
		return [
			'id' => 'wt-1',
			'title' => 'Bezwaar',
			'version' => 3,
			'description' => $description,
			'transitions' => [
				['slug' => 'a', 'fromStatus' => 'Ontvangen', 'toStatus' => 'In behandeling', 'label' => 'Start'],
			],
		];
	}

	/**
	 * Without runAs() there is no owner for the flows, so nothing runs.
	 *
	 * @return void
	 */
	public function testWithoutRunAsItReportsWhy(): void {
		$objectService = new class {
			/**
			 * @return array<int, array<string, mixed>> Never reached.
			 */
			public function searchObjectsBySlug(): array {
				throw new RuntimeException('must not be read');
			}
		};

		$summary = $this->migrator($objectService)->migrate($this->createMock(IUser::class), dryRun: false);

		$this->assertStringContainsString('no runAs()', $summary['note']);
		$this->assertSame(0, $summary['total']);
		$this->assertSame([], $this->written);
	}

	/**
	 * An unconfigured template schema is an empty run, not a failure.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTemplateSchemaMigratesNothing(): void {
		$migrator = $this->migrator($this->objectService([$this->template()]), templateSchemaConfigured: false);

		$summary = $migrator->migrate($this->createMock(IUser::class), dryRun: false);

		$this->assertSame(0, $summary['total']);
		$this->assertSame(0, $summary['failed']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->warnings);
	}

	/**
	 * A template read that throws is logged and makes an empty run.
	 *
	 * @return void
	 */
	public function testAFailedTemplateReadIsLoggedAndEmpty(): void {
		$migrator = $this->migrator($this->objectService([$this->template()], templatesThrow: true));

		$summary = $migrator->migrate($this->createMock(IUser::class), dryRun: false);

		$this->assertSame(0, $summary['total']);
		$this->assertSame([], $this->written);
		$this->assertSame(['Dossiq: could not list workflow templates'], $this->warnings);
	}

	/**
	 * A template's own description leads, followed by the provenance.
	 *
	 * @return void
	 */
	public function testTheOwnDescriptionPrecedesTheProvenance(): void {
		$this->migrator($this->objectService([$this->template(description: '  Bezwaarprocedure  ')]))
			->migrate($this->createMock(IUser::class), dryRun: false);

		$this->assertCount(1, $this->written);
		$this->assertSame(
			'Bezwaarprocedure — Projected from the Dossiq workflow definition "Bezwaar" (version 3). '
			. 'It arrives DISABLED: the definition still drives cases, and enabling '
			. 'this without retiring it would move every case twice.',
			$this->written[0]['description']
		);
	}

	/**
	 * Without its own description the flow carries the provenance alone.
	 *
	 * @return void
	 */
	public function testWithoutADescriptionOnlyTheProvenanceIsWritten(): void {
		$this->migrator($this->objectService([$this->template()]))
			->migrate($this->createMock(IUser::class), dryRun: false);

		$this->assertStringStartsWith('Projected from the Dossiq workflow definition "Bezwaar" (version 3).', $this->written[0]['description']);
	}
}
