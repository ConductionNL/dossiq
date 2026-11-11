<?php

/**
 * WorkflowDefinitionService::publish() refusals.
 *
 * Pins the publishes that write nothing and answer null: an unknown
 * definition (logged), one that is not a draft, and a draft while the
 * definition or case type schema is not configured. And that a configured
 * draft is published.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the refusals of WorkflowDefinitionService::publish().
 */
class WorkflowPublishRefusalTest extends TestCase {
	/**
	 * The saves the repository received.
	 *
	 * @var array<int, string>
	 */
	private array $saves = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The service over one stored row, with the given schemas configured.
	 *
	 * @param array<string, mixed>|null $row        The stored definition, or null for none.
	 * @param array<int, string>        $configured The schema keys that are configured.
	 *
	 * @return WorkflowDefinitionService
	 */
	private function service(?array $row, array $configured): WorkflowDefinitionService {
		$repository = $this->createMock(WorkflowDefinitionRepository::class);
		$repository->method('findById')->willReturn($row);
		$repository->method('findCaseType')->willReturn(['id' => 'ct-1']);
		$repository->method('listVersionsForCaseType')->willReturn([]);
		$repository->method('isConfiguredFor')->willReturnCallback(static fn (string $schemaKey): bool => in_array($schemaKey, $configured, true));
		$repository->method('save')->willReturnCallback(
			function (array $payload, ?string $uuid = null) use ($row): array {
				$this->saves[] = (string)$uuid;
				return array_merge((array)$row, $payload);
			}
		);
		$stamper = $this->createMock(TransitionAuthorizationStamper::class);
		$stamper->method('stamp')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new WorkflowDefinitionService($repository, new WorkflowLifecycleGuard($repository, $logger), $stamper, new WorkflowJsonProperty(), $logger);
	}//end service()

	/**
	 * A definition row.
	 *
	 * @param string $lifecycleStatus Its lifecycle status.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $lifecycleStatus): array {
		return ['id' => 'wf-1', 'caseType' => 'ct-1', 'lifecycleStatus' => $lifecycleStatus, 'isActive' => false, 'version' => 1, 'transitions' => '[]', 'steps' => '[]'];
	}//end row()

	/**
	 * Every refused publish answers null and writes nothing; a configured draft is published.
	 *
	 * @return void
	 */
	public function testEveryRefusedPublishWritesNothing(): void {
		$all = [WorkflowDefinitionRepository::SCHEMA_DEFINITION, WorkflowDefinitionRepository::SCHEMA_CASE_TYPE];

		self::assertNull($this->service(null, $all)->publish('wf-1'));
		self::assertContains('Dossiq: publish() — definition not found', $this->warnings);
		self::assertNull($this->service(self::row(WorkflowDefinitionService::STATUS_PUBLISHED), $all)->publish('wf-1'));
		self::assertNull($this->service(self::row(WorkflowDefinitionService::STATUS_DRAFT), [WorkflowDefinitionRepository::SCHEMA_DEFINITION])->publish('wf-1'));
		self::assertNull($this->service(self::row(WorkflowDefinitionService::STATUS_DRAFT), [WorkflowDefinitionRepository::SCHEMA_CASE_TYPE])->publish('wf-1'));
		self::assertSame([], $this->saves);

		$published = $this->service(self::row(WorkflowDefinitionService::STATUS_DRAFT), $all)->publish('wf-1');
		self::assertIsArray($published);
		self::assertSame(['wf-1'], $this->saves);
	}//end testEveryRefusedPublishWritesNothing()
}//end class
