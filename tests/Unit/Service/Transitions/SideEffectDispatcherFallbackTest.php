<?php

/**
 * SideEffectDispatcher fallback and failure-row tests.
 *
 * Covers the paths the node-port suite does not reach: a container answering
 * something that is not the node catalogue, an action type the local registry
 * does not know, a local handler failing with and without a reason, and a node
 * throwing an exception with an empty message.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SideEffectDispatcher
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class SideEffectDispatcherFallbackTest extends TestCase {

	/**
	 * A dispatcher whose container answers with something that is not the catalogue.
	 *
	 * @param ActionHandlerRegistry $registry The local handler registry.
	 * @param LoggerInterface       $logger   The logger.
	 *
	 * @return SideEffectDispatcher The dispatcher.
	 */
	private function withoutCatalogue(ActionHandlerRegistry $registry, LoggerInterface $logger): SideEffectDispatcher {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry')
			->willReturn(new \stdClass());

		return new SideEffectDispatcher($registry, $container, $logger);
	}//end withoutCatalogue()

	/**
	 * A container entry that is not a FlowNodeRegistry falls back to the local handlers.
	 *
	 * @return void
	 */
	public function testANonRegistryContainerEntryFallsBackToTheLocalHandlers(): void {
		$handler = $this->createMock(ActionHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with(['type' => 'createTask', 'title' => 'x'], ['id' => 'case-1'], ['userId' => 'jan'])
			->willReturn(new ActionResult(true));

		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->expects($this->once())->method('getHandler')->with('createTask')->willReturn($handler);

		$results = $this->withoutCatalogue($registry, $this->createMock(LoggerInterface::class))->dispatch(
			[['type' => 'createTask', 'title' => 'x']],
			['id' => 'case-1'],
			['userId' => 'jan']
		);

		$this->assertSame([['type' => 'createTask', 'ok' => true]], $results);
	}//end testANonRegistryContainerEntryFallsBackToTheLocalHandlers()

	/**
	 * A type the local registry does not know is reported and logged.
	 *
	 * @return void
	 */
	public function testAnUnknownLocalTypeIsReportedAndLogged(): void {
		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->method('getHandler')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'SideEffectDispatcher: unknown action type',
				['type' => 'teleport', 'context' => ['transition' => 't-1']]
			);

		$results = $this->withoutCatalogue($registry, $logger)->dispatch([['type' => 'teleport']], [], ['transition' => 't-1']);

		$this->assertSame([['type' => 'teleport', 'ok' => false, 'error' => 'unknown_action_type']], $results);
	}//end testAnUnknownLocalTypeIsReportedAndLogged()

	/**
	 * A failing local handler reports its own error, or `action_failed` when it gives none.
	 *
	 * @return void
	 */
	public function testAFailingLocalHandlerReportsItsError(): void {
		$withReason = $this->createMock(ActionHandlerInterface::class);
		$withReason->method('handle')->willReturn(new ActionResult(false, 'no_recipient'));
		$withoutReason = $this->createMock(ActionHandlerInterface::class);
		$withoutReason->method('handle')->willReturn(new ActionResult(false));

		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->method('getHandler')->willReturnMap([
			['sendMessage', $withReason],
			['setCaseAnswer', $withoutReason],
		]);

		$results = $this->withoutCatalogue($registry, $this->createMock(LoggerInterface::class))->dispatch(
			[['type' => 'sendMessage'], ['type' => 'setCaseAnswer']],
			[],
			[]
		);

		$this->assertSame(
			[
				['type' => 'sendMessage', 'ok' => false, 'error' => 'no_recipient'],
				['type' => 'setCaseAnswer', 'ok' => false, 'error' => 'action_failed'],
			],
			$results
		);
	}//end testAFailingLocalHandlerReportsItsError()

	/**
	 * A node throwing with an empty message still reports a cause.
	 *
	 * @return void
	 */
	public function testANodeThrowingWithoutAMessageReportsActionFailed(): void {
		$node = $this->createMock(IFlowNode::class);
		$node->method('getId')->willReturn('dossiq.createTask');
		$node->expects($this->once())->method('execute')->willThrowException(new RuntimeException(''));

		$nodes = new FlowNodeRegistry($this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		$nodes->register($node);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($nodes);

		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->expects($this->never())->method('getHandler');

		$dispatcher = new SideEffectDispatcher($registry, $container, $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[['type' => 'createTask', 'ok' => false, 'error' => 'action_failed']],
			$dispatcher->dispatch([['type' => 'createTask']], ['id' => 'case-1'], [])
		);
	}//end testANodeThrowingWithoutAMessageReportsActionFailed()
}//end class
