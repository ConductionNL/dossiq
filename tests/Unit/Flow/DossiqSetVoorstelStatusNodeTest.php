<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqSetVoorstelStatusNode;
use OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Flow\DossiqSetVoorstelStatusNode
 */
class DossiqSetVoorstelStatusNodeTest extends TestCase {

	/**
	 * Status moves the fake linkage was asked for.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $moves = [];

	/**
	 * Build the node over a recording linkage.
	 *
	 * @param boolean $refuses Whether the linkage rejects the status.
	 *
	 * @return DossiqSetVoorstelStatusNode The node.
	 */
	private function node(bool $refuses=false): DossiqSetVoorstelStatusNode {
		$moves = &$this->moves;

		$linkage = $this->createMock(ParaafFlowLinkage::class);
		$linkage->method('setStatus')->willReturnCallback(
			static function (string $proposalId, string $status) use (&$moves, $refuses): bool {
				if ($refuses === true) {
					throw new RuntimeException('not a voorstel status: ' . $status);
				}

				$moves[] = ['proposal' => $proposalId, 'status' => $status];

				return true;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqSetVoorstelStatusNode($linkage, $l10n, $this->createMock(LoggerInterface::class));

	}//end node()

	/**
	 * A voorstel item.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'voorstel-1']]];

	}//end items()

	/**
	 * 🔴 The node moves the voorstel to the configured status.
	 *
	 * This is what the projection was missing: until it existed, a flow-driven
	 * voorstel collected every paraaf and then sat in `in_parafering`, because
	 * the transitions lived in the service the flow had replaced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItMovesTheVoorstelToTheConfiguredStatus(): void {
		$this->node()->execute($this->items(), ['status' => 'geaccordeerd'], []);

		$this->assertSame([['proposal' => 'voorstel-1', 'status' => 'geaccordeerd']], $this->moves);

	}//end testItMovesTheVoorstelToTheConfiguredStatus()

	/**
	 * The items pass through unchanged, so later steps still see them.
	 *
	 * @return void
	 */
	public function testTheItemsPassThroughUnchanged(): void {
		$items = $this->items();

		$this->assertSame($items, $this->node()->execute($items, ['status' => 'geaccordeerd'], []));

	}//end testTheItemsPassThroughUnchanged()

	/**
	 * A step naming no status is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAStepWithNoStatusIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a status');

		$this->node()->execute($this->items(), [], []);

	}//end testAStepWithNoStatusIsRefused()

	/**
	 * 🔴 A status the schema does not declare is NOT swallowed.
	 *
	 * A flow asking for an impossible status is an authoring error. Catching
	 * it here would leave the voorstel silently where it was, which is how
	 * dossiq#1609's invalid statuses went unnoticed for as long as they did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnUndeclaredStatusIsNotSwallowed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not a voorstel status');

		$this->node(refuses: true)->execute($this->items(), ['status' => 'gereed_voor_agendering'], []);

	}//end testAnUndeclaredStatusIsNotSwallowed()

	/**
	 * An item naming no voorstel is skipped, not raised.
	 *
	 * The run has already collected every paraaf by the time it reaches this
	 * node; failing here would strand it at the very end.
	 *
	 * @return void
	 */
	public function testAnItemWithNoVoorstelIsSkipped(): void {
		$out = $this->node()->execute([['json' => ['title' => 'no id here']]], ['status' => 'geaccordeerd'], []);

		$this->assertSame([], $this->moves);
		$this->assertCount(1, $out);

	}//end testAnItemWithNoVoorstelIsSkipped()

	/**
	 * The node identifies itself as the type the projection emits.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItAnswersToTheIdTheProjectionEmits(): void {
		$this->assertSame('dossiq.setVoorstelStatus', $this->node()->getId());

	}//end testItAnswersToTheIdTheProjectionEmits()

	/**
	 * The node describes itself for the flow editor.
	 *
	 * @return void
	 */
	public function testItDescribesItselfForTheEditor(): void {
		$node = $this->node();

		$this->assertNotSame('', trim($node->getDisplayName()));
		$this->assertNotSame('', trim($node->getDescription()));
		$this->assertNotSame('', trim($node->getIcon()));
		$this->assertTrue($node->isAvailableForScope(1));

	}//end testItDescribesItselfForTheEditor()

}//end class
