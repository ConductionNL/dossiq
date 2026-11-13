<?php

/**
 * Dossiq Transition Nodes Test
 *
 * Pins the flow nodes that run a transition action handler: each node's id,
 * the config keys it requires, and the shared behaviour of the base node —
 * refusing a missing or blank required key, running the handler once per
 * item with the item's case, merging the case changes and the result under
 * the output key, refusing a failed action, and the scopes it is offered in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Flow
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

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqFlowNodeBase;
use OCA\Dossiq\Flow\DossiqTxBesluitvormingPublishNode;
use OCA\Dossiq\Flow\DossiqTxCreateSubCaseNode;
use OCA\Dossiq\Flow\DossiqTxCreateTaskNode;
use OCA\Dossiq\Flow\DossiqTxSendMessageNode;
use OCA\Dossiq\Flow\DossiqTxSetCaseAnswerNode;
use OCA\Dossiq\Flow\DossiqTxSetStatusNode;
use OCA\Dossiq\Flow\DossiqTxSuspendTermNode;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\BesluitvormingPublishHandler;
use OCA\Dossiq\Service\Transitions\CreateSubCaseHandler;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\Dossiq\Service\Transitions\SendTemplateMessageHandler;
use OCA\Dossiq\Service\Transitions\SetCaseAnswerHandler;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\SuspendTermHandler;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for the transition flow nodes.
 */
#[CoversClass(DossiqFlowNodeBase::class)]
#[CoversClass(DossiqTxSetStatusNode::class)]
#[CoversClass(DossiqTxSuspendTermNode::class)]
#[CoversClass(DossiqTxCreateTaskNode::class)]
#[CoversClass(DossiqTxCreateSubCaseNode::class)]
#[CoversClass(DossiqTxBesluitvormingPublishNode::class)]
#[CoversClass(DossiqTxSetCaseAnswerNode::class)]
#[CoversClass(DossiqTxSendMessageNode::class)]
#[UsesClass(ActionResult::class)]
final class DossiqTransitionNodesTest extends TestCase {
	/**
	 * Mock l10n that substitutes its parameters.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urls;

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$this->urls = $this->createMock(IURLGenerator::class);
	}//end setUp()

	/**
	 * Each node, its handler class, id and required config keys.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: string, 3: array<int,string>}>
	 */
	public static function nodes(): array {
		return [
			'set status'             => [DossiqTxSetStatusNode::class, SetStatusHandler::class, 'dossiq.setStatus', []],
			'suspend term'           => [DossiqTxSuspendTermNode::class, SuspendTermHandler::class, 'dossiq.suspendTerm', ['durationDays']],
			'create task'            => [DossiqTxCreateTaskNode::class, CreateTaskHandler::class, 'dossiq.createTask', ['title']],
			'create sub-case'        => [DossiqTxCreateSubCaseNode::class, CreateSubCaseHandler::class, 'dossiq.createSubCase', ['caseType']],
			'publish decision-making' => [DossiqTxBesluitvormingPublishNode::class, BesluitvormingPublishHandler::class, 'dossiq.besluitvormingPublish', []],
			'set case answer'        => [DossiqTxSetCaseAnswerNode::class, SetCaseAnswerHandler::class, 'dossiq.setCaseAnswer', ['name']],
			'send message'           => [DossiqTxSendMessageNode::class, SendTemplateMessageHandler::class, 'dossiq.sendMessage', ['emailTemplate']],
		];
	}//end nodes()

	/**
	 * A node over the given handler.
	 *
	 * @param class-string $node    The node class.
	 * @param object       $handler The handler.
	 *
	 * @return DossiqFlowNodeBase
	 */
	private function node(string $node, object $handler): DossiqFlowNodeBase {
		return new $node(handler: $handler, l10n: $this->l10n, urls: $this->urls);
	}//end node()

	/**
	 * Config filling every required key.
	 *
	 * @param array<int,string> $keys The required keys.
	 *
	 * @return array<string,string>
	 */
	private static function filled(array $keys): array {
		$config = array_fill_keys($keys, 'value');
		if ($keys === []) {
			// Set case status also needs a target status or role; see testSetStatusNeedsAStatusOrRole().
			$config['status'] = 'open';
		}

		return $config;
	}//end filled()

	/**
	 * Each node has its own id, the app icon, a display name and a description.
	 *
	 * @param class-string      $node    The node class.
	 * @param class-string      $handler The handler class.
	 * @param string            $id      The node id.
	 *
	 * @return void
	 */
	#[DataProvider('nodes')]
	public function testIdentity(string $node, string $handler, string $id): void {
		$this->urls->expects(self::once())->method('imagePath')->with('dossiq', 'app-dark.svg')->willReturn('/apps/dossiq/img/app-dark.svg');
		$subject = $this->node(node: $node, handler: $this->createMock($handler));

		self::assertSame(expected: $id, actual: $subject->getId());
		self::assertSame(expected: '/apps/dossiq/img/app-dark.svg', actual: $subject->getIcon());
		self::assertNotSame(expected: '', actual: $subject->getDisplayName());
		self::assertNotSame(expected: '', actual: $subject->getDescription());
	}//end testIdentity()

	/**
	 * Each required key must carry a non-blank value; the refusal names the node and the key,
	 * and the handler is not run.
	 *
	 * @param class-string      $node    The node class.
	 * @param class-string      $handler The handler class.
	 * @param string            $id      The node id.
	 * @param array<int,string> $keys    The required keys.
	 *
	 * @return void
	 */
	#[DataProvider('nodes')]
	public function testRequiredKeysAreEnforced(string $node, string $handler, string $id, array $keys): void {
		$mock = $this->createMock($handler);
		$mock->expects(self::never())->method('handle');
		$subject = $this->node(node: $node, handler: $mock);

		$subject->validateConfig(config: self::filled(keys: $keys));

		foreach ($keys as $key) {
			foreach ([[], [$key => '   ']] as $config) {
				$config += ['status' => 'open'];
				try {
					$subject->execute(new FlowNodeCall(items: [['json' => []]], config: $config));
					self::fail('A missing "' . $key . '" was accepted by ' . $id);
				} catch (UnexpectedValueException $e) {
					self::assertSame(expected: $subject->getDisplayName() . ' needs a value for "' . $key . '".', actual: $e->getMessage());
				}
			}
		}
	}//end testRequiredKeysAreEnforced()

	/**
	 * The handler runs once per item with that item's case, the config and the context; the case
	 * changes and the result data are merged into the item under the default output key.
	 *
	 * @param class-string      $node    The node class.
	 * @param class-string      $handler The handler class.
	 * @param string            $id      The node id.
	 * @param array<int,string> $keys    The required keys.
	 *
	 * @return void
	 */
	#[DataProvider('nodes')]
	public function testExecuteRunsTheHandlerPerItem(string $node, string $handler, string $id, array $keys): void {
		$config  = self::filled(keys: $keys);
		$context = ['fromStatus' => 'new', 'toStatus' => 'open'];
		$seen    = [];
		$mock    = $this->createMock($handler);
		$mock->expects(self::exactly(2))
			->method('handle')
			->willReturnCallback(
				static function (array $actionConfig, array $case, array $transitionContext) use (&$seen): ActionResult {
					$seen[] = [$actionConfig, $case, $transitionContext];
					return new ActionResult(succeeded: true, data: ['for' => $case['id']], caseChanges: ['status' => 'open', 'id' => $case['id']]);
				}
			);

		$out = $this->node(node: $node, handler: $mock)->execute(
			new FlowNodeCall(
				items: [['json' => ['id' => 'a', 'status' => 'new'], 'binary' => 'kept'], ['json' => ['id' => 'b']]],
				config: $config,
				context: $context
			)
		);

		self::assertSame(
			expected: [[$config, ['id' => 'a', 'status' => 'new'], $context], [$config, ['id' => 'b'], $context]],
			actual: $seen
		);
		self::assertSame(
			expected: [
				['json' => ['id' => 'a', 'status' => 'open', 'actionResult' => ['for' => 'a']], 'binary' => 'kept'],
				['json' => ['id' => 'b', 'status' => 'open', 'actionResult' => ['for' => 'b']]],
			],
			actual: $out
		);
	}//end testExecuteRunsTheHandlerPerItem()

	/**
	 * A configured output key receives the result; no items means no handler call.
	 *
	 * @return void
	 */
	public function testOutputKeyAndEmptyItems(): void {
		$mock = $this->createMock(BesluitvormingPublishHandler::class);
		$mock->method('handle')->willReturn(new ActionResult(succeeded: true, data: ['ok' => 1]));
		$subject = $this->node(node: DossiqTxBesluitvormingPublishNode::class, handler: $mock);

		$out = $subject->execute(new FlowNodeCall(items: [['json' => ['id' => 'a']]], config: ['output' => 'published']));

		self::assertSame(expected: [['json' => ['id' => 'a', 'published' => ['ok' => 1]]]], actual: $out);
		self::assertSame(expected: [], actual: $subject->execute(new FlowNodeCall(items: [], config: [])));
	}//end testOutputKeyAndEmptyItems()

	/**
	 * A failed action stops the node with the handler's error, or a generic sentence without one.
	 *
	 * @return void
	 */
	public function testFailedActionStopsTheNode(): void {
		$mock = $this->createMock(BesluitvormingPublishHandler::class);
		$mock->method('handle')->willReturnOnConsecutiveCalls(
			new ActionResult(succeeded: false, error: 'Status "x" does not exist.'),
			new ActionResult(succeeded: false)
		);
		$subject = $this->node(node: DossiqTxBesluitvormingPublishNode::class, handler: $mock);

		$messages = [];
		foreach ([1, 2] as $attempt) {
			try {
				$subject->execute(new FlowNodeCall(items: [['json' => []]], config: []));
			} catch (UnexpectedValueException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(expected: ['Status "x" does not exist.', 'The action did not complete.'], actual: $messages);
	}//end testFailedActionStopsTheNode()

	/**
	 * Setting a status needs a non-blank target status or role.
	 *
	 * @return void
	 */
	public function testSetStatusNeedsAStatusOrRole(): void {
		$subject = $this->node(node: DossiqTxSetStatusNode::class, handler: $this->createMock(SetStatusHandler::class));

		$subject->validateConfig(config: ['status' => 'open']);
		$subject->validateConfig(config: ['role' => 'afgehandeld']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Set case status needs a status to move the case to: either a "role" or a literal "status" name.');
		$subject->validateConfig(config: ['status' => ' ', 'role' => '']);
	}//end testSetStatusNeedsAStatusOrRole()

	/**
	 * The nodes are offered in the admin and user scopes only.
	 *
	 * @return void
	 */
	public function testAvailableInAdminAndUserScopes(): void {
		$subject = $this->node(node: DossiqTxSetStatusNode::class, handler: $this->createMock(SetStatusHandler::class));

		self::assertTrue(condition: $subject->isAvailableForScope(scope: 0));
		self::assertTrue(condition: $subject->isAvailableForScope(scope: 1));
		self::assertFalse(condition: $subject->isAvailableForScope(scope: 2));
	}//end testAvailableInAdminAndUserScopes()
}//end class
