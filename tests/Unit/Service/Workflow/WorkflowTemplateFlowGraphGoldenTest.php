<?php

/**
 * Golden test for the graph a workflow template projects to: every node and
 * edge, in order, for every kind of transition.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WorkflowTemplateFlowGraphGoldenTest extends TestCase {

	private function graphOf(array $template, array $statusNames = []): ?array {
		$class    = new ReflectionClass(WorkflowTemplateFlowMigrator::class);
		$migrator = $class->newInstanceWithoutConstructor();
		return $class->getMethod('graphOf')->invoke($migrator, $template, $statusNames);
	}//end graphOf()

	public function testEveryNodeAndEdge(): void {
		$template = [
			'transitions' => json_encode(
				[
					['fromStatus' => 'uuid-new', 'toStatus' => ' In behandeling ', 'label' => 'Start'],
					['fromStatus' => '*', 'toStatus' => 'Afgerond'],
					['fromStatus' => 'In behandeling', 'toStatus' => '', 'label' => 'x'],
					['fromStatus' => '', 'toStatus' => 'Afgerond'],
					['fromStatus' => 'In behandeling', 'toStatus' => 'uuid-done', 'slug' => 'afronden'],
					['fromStatus' => 'Ontvangen', 'toStatus' => 'Ontvangen'],
					['fromStatus' => 'Afgerond!', 'toStatus' => 'uuid-new', 'label' => null, 'slug' => 'heropen'],
				]
			),
		];

		$this->assertSame(
			[
				'nodes' => [
					['id' => 'status-ontvangen', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'Ontvangen']],
					['id' => 'status-in-behandeling', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'In behandeling']],
					['id' => 'status-afgerond', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'Afgerond']],
					['id' => 'status-afgerond', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'Afgerond!']],
				],
				'edges' => [
					['id' => 't-1', 'from' => ['status-ontvangen'], 'to' => ['status-in-behandeling'], 'label' => 'Start'],
					['id' => 't-5', 'from' => ['status-in-behandeling'], 'to' => ['status-afgerond'], 'label' => 'afronden'],
					['id' => 't-6', 'from' => ['status-ontvangen'], 'to' => ['status-ontvangen'], 'label' => ''],
					['id' => 't-7', 'from' => ['status-afgerond'], 'to' => ['status-ontvangen'], 'label' => 'heropen'],
				],
			],
			$this->graphOf($template, ['uuid-new' => 'Ontvangen', 'uuid-done' => 'Afgerond'])
		);
	}//end testEveryNodeAndEdge()

	public function testNoUsableTransitionIsNoGraph(): void {
		$this->assertNull($this->graphOf([]));
		$this->assertNull($this->graphOf(['transitions' => '[]']));
		$this->assertNull($this->graphOf(['transitions' => [['fromStatus' => '*', 'toStatus' => 'A'], ['fromStatus' => 'A']]]));
		$this->assertSame(
			['nodes' => [['id' => 'status-a', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'A']], ['id' => 'status-b', 'type' => 'dossiq.setStatus', 'config' => ['status' => 'B']]], 'edges' => [['id' => 't-1', 'from' => ['status-a'], 'to' => ['status-b'], 'label' => '']]],
			$this->graphOf(['transitions' => [['fromStatus' => 'A', 'toStatus' => 'B']]])
		);
	}//end testNoUsableTransitionIsNoGraph()
}//end class
