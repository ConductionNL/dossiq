<?php

/**
 * ActionFlowSteps Unit Tests
 *
 * An action type the retired-node table does not know becomes one step of
 * its own node with its decoded config; a known one becomes what the
 * translator answers; a type that cannot become a step, or a step no node
 * implements, is skipped with the reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\ActionFlowSteps;
use OCA\Dossiq\Service\Actions\ProjectedAction;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ActionFlowSteps.
 *
 * @covers \OCA\Dossiq\Service\Actions\ActionFlowSteps
 * @uses   \OCA\Dossiq\Service\Actions\ProjectedAction
 */
final class ActionFlowStepsTest extends TestCase {

	/**
	 * Steps over a node registry that knows the given node ids.
	 *
	 * @param array<int, string>        $known      The node ids the registry answers.
	 * @param array<string, mixed>|null $row        What the retired-node table answers.
	 * @param RetiredNodeTranslator     $translator The translator.
	 *
	 * @return ActionFlowSteps
	 */
	private function steps(array $known, ?array $row, RetiredNodeTranslator $translator): ActionFlowSteps {
		$registry = new class($known) {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $known The node ids.
			 */
			public function __construct(private array $known) {
			}

			/**
			 * Answer the node, or refuse.
			 *
			 * @param string $id The node id.
			 *
			 * @return string
			 */
			public function get(string $id): string {
				if (in_array($id, $this->known, true) === false) {
					throw new RuntimeException('unknown node ' . $id);
				}
				return $id;
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($registry);
		$map = $this->createMock(originalClassName: RetiredNodeMap::class);
		$map->method('rowFor')->willReturn($row);

		return new ActionFlowSteps(
			map: $map,
			translator: $translator,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end steps()

	/**
	 * An unknown type is one step of its own node, its JSON config decoded.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsOneStepOfItsOwnNode(): void {
		$steps = $this->steps(
			known: ['dossiq.action.notify'],
			row: null,
			translator: $this->createMock(originalClassName: RetiredNodeTranslator::class)
		);
		$projected = new ProjectedAction(action: ['type' => 'notify', 'config' => '{"to":"handler"}'], marker: 'm1');

		$this->assertNull(actual: $steps->resolveSteps(projected: $projected));
		$this->assertSame(expected: [['type' => 'dossiq.action.notify', 'config' => ['to' => 'handler']]], actual: $projected->steps);
	}//end testAnUnknownTypeIsOneStepOfItsOwnNode()

	/**
	 * A retired type becomes what the translator answers for its row.
	 *
	 * @return void
	 */
	public function testARetiredTypeBecomesWhatTheTranslatorAnswers(): void {
		$translator = $this->createMock(originalClassName: RetiredNodeTranslator::class);
		$translator->expects($this->once())->method('stepsFor')
			->with(['replacement' => 'filinq.render'], ['template' => 't1'])
			->willReturn([['type' => 'filinq.render', 'config' => ['template' => 't1']]]);
		$steps = $this->steps(known: ['filinq.render'], row: ['replacement' => 'filinq.render'], translator: $translator);
		$projected = new ProjectedAction(action: ['type' => 'document', 'config' => ['template' => 't1']], marker: 'm2');

		$this->assertNull(actual: $steps->resolveSteps(projected: $projected));
		$this->assertSame(expected: [['type' => 'filinq.render', 'config' => ['template' => 't1']]], actual: $projected->steps);
	}//end testARetiredTypeBecomesWhatTheTranslatorAnswers()

	/**
	 * A type that cannot become a step, and a step no node implements, are skipped with the reason.
	 *
	 * @return void
	 */
	public function testAnUnmappableTypeAndAMissingNodeAreSkipped(): void {
		$translator = $this->createMock(originalClassName: RetiredNodeTranslator::class);
		$translator->method('stepsFor')->willThrowException(new UnmappableStep(message: 'it wrote a local table'));
		$unmappable = $this->steps(known: [], row: ['replacement' => 'x'], translator: $translator)
			->resolveSteps(projected: new ProjectedAction(action: ['type' => 'legacy'], marker: 'm3'));
		$this->assertSame(
			expected: ['outcome' => 'skipped', 'marker' => 'm3', 'detail' => '"legacy" cannot become a flow step: it wrote a local table'],
			actual: $unmappable
		);

		$missing = $this->steps(known: [], row: null, translator: $this->createMock(originalClassName: RetiredNodeTranslator::class))
			->resolveSteps(projected: new ProjectedAction(action: ['type' => 'notify', 'config' => 'not json'], marker: 'm4'));
		$this->assertSame(
			expected: [
				'outcome' => 'skipped',
				'marker' => 'm4',
				'detail' => 'no node implements "dossiq.action.notify" (for "notify"); a flow around it would never run',
			],
			actual: $missing
		);
	}//end testAnUnmappableTypeAndAMissingNodeAreSkipped()
}//end class
