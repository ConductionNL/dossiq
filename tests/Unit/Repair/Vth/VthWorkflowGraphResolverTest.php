<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Repair\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair\Vth;

use OCA\Procest\Repair\Vth\VthWorkflowGraphResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the automaticActions normalisation the VTH seeder applies.
 *
 * The resolver had no test at all, which is how `spawnCase` reached shipped
 * data: it was passed through untouched, stored in every seeded workflow, and
 * resolved by nothing at run time.
 */
class VthWorkflowGraphResolverTest extends TestCase {
	private VthWorkflowGraphResolver $resolver;

	/**
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Build the resolver under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new VthWorkflowGraphResolver($this->logger);
	}

	/**
	 * A catalog entry with one transition carrying the given actions.
	 *
	 * @param array<int, array<string, mixed>> $actions The automaticActions block.
	 *
	 * @return array<string, mixed> The catalog entry.
	 */
	private function entry(array $actions): array {
		return [
			'steps' => [
				['slug' => 'rapport', 'title' => 'Rapport', 'statusName' => 'Rapport', 'order' => 1],
			],
			'transitions' => [
				[
					'slug' => 'rapport-naar-opvolging',
					'label' => 'Opvolging starten',
					'fromStatus' => 'Rapport',
					'toStatus' => 'Opvolging',
					'automaticActions' => $actions,
				],
			],
		];
	}

	/**
	 * The status map both fixtures resolve against.
	 *
	 * @return array<string, string> Name → UUID.
	 */
	private function statusMap(): array {
		return [
			'Rapport' => '11111111-1111-4111-8111-111111111111',
			'Opvolging' => '22222222-2222-4222-8222-222222222222',
		];
	}

	/**
	 * The first transition's normalised actions.
	 *
	 * @param array<int, array<string, mixed>> $actions Raw actions.
	 * @param array<string, string> $spawnTargets Template slug → caseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The normalised actions.
	 */
	private function normalise(array $actions, array $spawnTargets): array {
		$graph = $this->resolver->resolve(
			data: $this->entry($actions),
			slug: 'toezichtbezoek',
			statusMap: $this->statusMap(),
			spawnTargets: $spawnTargets,
		);
		$this->assertNotNull($graph);

		return $graph['transitions'][0]['automaticActions'];
	}

	/**
	 * spawnCase becomes the executable createSubCase with a resolved caseType.
	 *
	 * @return void
	 */
	public function testItRewritesSpawnCaseToCreateSubCase(): void {
		$result = $this->normalise(
			[
				[
					'type' => 'spawnCase',
					'config' => [
						'targetWorkflowSlug' => 'handhavingstraject',
						'title' => 'Handhavingstraject na toezichtbezoek',
					],
				],
			],
			['handhavingstraject' => '33333333-3333-4333-8333-333333333333'],
		);

		$this->assertSame(
			[
				[
					'type' => 'createSubCase',
					'config' => [
						'caseType' => '33333333-3333-4333-8333-333333333333',
						'title' => 'Handhavingstraject na toezichtbezoek',
					],
				],
			],
			$result
		);
	}

	/**
	 * An unresolvable target is DROPPED, not stored as a dead action.
	 *
	 * Storing it is exactly the old behaviour: the transition would keep
	 * reporting success while spawning nothing.
	 *
	 * @return void
	 */
	public function testItDropsAnUnresolvableSpawnTarget(): void {
		$this->logger->expects($this->once())->method('warning');

		$result = $this->normalise(
			[['type' => 'spawnCase', 'config' => ['targetWorkflowSlug' => 'niet-geseed']]],
			['handhavingstraject' => '33333333-3333-4333-8333-333333333333'],
		);

		$this->assertSame([], $result);
	}

	/**
	 * A spawnCase without a target slug is dropped too.
	 *
	 * @return void
	 */
	public function testItDropsSpawnCaseWithoutATarget(): void {
		$result = $this->normalise([['type' => 'spawnCase', 'config' => []]], []);

		$this->assertSame([], $result);
	}

	/**
	 * Actions already in the executable vocabulary pass through untouched.
	 *
	 * @return void
	 */
	public function testItLeavesExecutableActionsAlone(): void {
		$actions = [
			['type' => 'notify', 'config' => ['roleName' => 'Inspecteur', 'message' => 'Opvolging gestart']],
			['type' => 'setField', 'config' => ['field' => 'fase', 'value' => 'opvolging']],
		];

		$this->assertSame($actions, $this->normalise($actions, []));
	}

	/**
	 * A malformed (non-array) action entry is skipped rather than fatal.
	 *
	 * @return void
	 */
	public function testItSkipsAMalformedActionEntry(): void {
		$graph = $this->resolver->resolve(
			data: $this->entry(['not-an-action']),
			slug: 'toezichtbezoek',
			statusMap: $this->statusMap(),
			spawnTargets: [],
		);

		$this->assertNotNull($graph);
		$this->assertSame([], $graph['transitions'][0]['automaticActions']);
	}
}
