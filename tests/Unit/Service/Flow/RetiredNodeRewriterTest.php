<?php

/**
 * Unit tests for RetiredNodeRewriter and RetiredNodeMap.
 *
 * Both classes are real here: the rewriter is pure, so the graph that goes in
 * and the graph that comes out are the whole contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeRewriter
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeMap
 */
class RetiredNodeRewriterTest extends TestCase {

	/**
	 * The shape AutomaticActionFlowMigrator builds, list endpoints and all.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
	 */
	private function migratedReminderFlow(): array {
		return [
			'nodes' => [
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'action', 'type' => 'dossiq.action.scheduleReminder', 'config' => ['triggerAt' => 'x']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			'edges' => [
				['id' => 'trigger-action', 'from' => ['trigger'], 'to' => ['action']],
				['id' => 'action-end', 'from' => ['action'], 'to' => ['end']],
			],
		];
	}//end migratedReminderFlow()

	/**
	 * The shipped table retires the reminder node with no replacement.
	 *
	 * @return void
	 */
	public function testTheShippedTableRetiresTheReminderWithoutReplacement(): void {
		$map = new RetiredNodeMap();

		$row = $map->rowFor(type: 'dossiq.action.scheduleReminder');
		self::assertNotNull($row);
		self::assertNull($row['replacement']);
		self::assertNull($map->rowFor(type: 'dossiq.setStatus'));
		// callWebhook runs again, as Integriq's source call, so only the reminder is left.
		self::assertSame(['scheduleReminder'], array_keys($map->retiredActionTypes()));
	}//end testTheShippedTableRetiresTheReminderWithoutReplacement()

	/**
	 * A removed step is bridged: trigger -> reminder -> end becomes trigger -> end.
	 *
	 * @return void
	 */
	public function testARemovedStepIsBridgedToItsSuccessor(): void {
		$flow = $this->migratedReminderFlow();

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $flow['nodes'], edges: $flow['edges']);

		self::assertSame(['trigger', 'end'], array_column($result['nodes'], 'id'));
		self::assertSame(
			[['id' => 'trigger-action', 'from' => ['trigger'], 'to' => ['end']]],
			$result['edges']
		);
		self::assertCount(1, $result['changes']);
		self::assertSame('action', $result['changes'][0]['step']);
		self::assertSame('removed', $result['changes'][0]['outcome']);
		self::assertFalse($result['changes'][0]['orphaned']);
	}//end testARemovedStepIsBridgedToItsSuccessor()

	/**
	 * A second pass over the rewritten graph changes nothing.
	 *
	 * @return void
	 */
	public function testRewritingTwiceIsANoOp(): void {
		$rewriter = new RetiredNodeRewriter(new RetiredNodeMap());
		$flow = $this->migratedReminderFlow();

		$first = $rewriter->rewrite(nodes: $flow['nodes'], edges: $flow['edges']);
		$second = $rewriter->rewrite(nodes: $first['nodes'], edges: $first['edges']);

		self::assertSame([], $second['changes']);
		self::assertSame($first['nodes'], $second['nodes']);
		self::assertSame($first['edges'], $second['edges']);
	}//end testRewritingTwiceIsANoOp()

	/**
	 * A single-id edge keeps its shape, and a fan-out becomes one edge per target.
	 *
	 * @return void
	 */
	public function testStringEndpointsKeepTheirShape(): void {
		$nodes = [
			['id' => 'start', 'type' => 'openregister.trigger-object'],
			['id' => 'remind', 'type' => 'dossiq.action.scheduleReminder'],
			['id' => 'a', 'type' => 'openregister.end'],
			['id' => 'b', 'type' => 'openregister.end'],
		];
		$edges = [
			['id' => 'e1', 'from' => 'start', 'to' => 'remind', 'fromExit' => 'yes'],
			['id' => 'e2', 'from' => 'remind', 'to' => 'a'],
			['id' => 'e3', 'from' => 'remind', 'to' => 'b'],
		];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(
			[
				['id' => 'e1-1', 'from' => 'start', 'to' => 'a', 'fromExit' => 'yes'],
				['id' => 'e1-2', 'from' => 'start', 'to' => 'b', 'fromExit' => 'yes'],
			],
			$result['edges']
		);
	}//end testStringEndpointsKeepTheirShape()

	/**
	 * A removed last step drops the edge into it and says so.
	 *
	 * @return void
	 */
	public function testARemovedLastStepIsReportedAsOrphaning(): void {
		$nodes = [
			['id' => 'start', 'type' => 'openregister.trigger-object'],
			['id' => 'remind', 'type' => 'dossiq.action.scheduleReminder'],
		];
		$edges = [['id' => 'e1', 'from' => 'start', 'to' => 'remind']];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame([], $result['edges']);
		self::assertTrue($result['changes'][0]['orphaned']);
	}//end testARemovedLastStepIsReportedAsOrphaning()

	/**
	 * A row with a replacement renames the step and keeps its id, config and edges.
	 *
	 * @return void
	 */
	public function testAReplacementRowRenamesTheStepAndKeepsItsConfig(): void {
		$map = new RetiredNodeMap(
			[
				'dossiq.old' => ['replacement' => 'openregister.new', 'reason' => 'test row'],
			]
		);
		$nodes = [['id' => 's', 'type' => 'dossiq.old', 'config' => ['k' => 'v']]];
		$edges = [['id' => 'e', 'from' => 'x', 'to' => 's']];

		$result = (new RetiredNodeRewriter($map))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame([['id' => 's', 'type' => 'openregister.new', 'config' => ['k' => 'v']]], $result['nodes']);
		self::assertSame($edges, $result['edges']);
		self::assertSame('replaced', $result['changes'][0]['outcome']);
		self::assertSame('openregister.new', $result['changes'][0]['replacement']);
	}//end testAReplacementRowRenamesTheStepAndKeepsItsConfig()

	/**
	 * A graph without a retired step comes back unchanged, with no change rows.
	 *
	 * @return void
	 */
	public function testACleanGraphIsLeftAlone(): void {
		$nodes = [['id' => 'a', 'type' => 'dossiq.askPerson'], ['id' => 'b', 'type' => 'openregister.end']];
		$edges = [['id' => 'e', 'from' => 'a', 'to' => 'b']];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(['nodes' => $nodes, 'edges' => $edges, 'changes' => []], $result);
	}//end testACleanGraphIsLeftAlone()
}//end class
