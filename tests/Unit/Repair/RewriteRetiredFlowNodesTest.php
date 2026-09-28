<?php

/**
 * Unit tests for RewriteRetiredFlowNodes.
 *
 * OpenRegister's flow classes are not installed in the unit suite, so the
 * flow, its mapper and the version service are small hand-written fakes that
 * record what was called and in which order. The map and the rewriter are the
 * real classes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use ArrayObject;
use OCA\Dossiq\Repair\RewriteRetiredFlowNodes;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCP\IL10N;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Integriq\Event\SourceRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\RewriteRetiredFlowNodes
 */
class RewriteRetiredFlowNodesTest extends TestCase {

	/**
	 * Every call the fakes saw, in order.
	 *
	 * @var ArrayObject<int, string>
	 */
	private ArrayObject $calls;

	/**
	 * Every warning the step wrote to its output.
	 *
	 * @var ArrayObject<int, string>
	 */
	private ArrayObject $warnings;

	/**
	 * Reset the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
		$this->warnings = new ArrayObject();
	}//end setUp()

	/**
	 * A stored flow, with the accessors the step reads and writes.
	 *
	 * @param string $name   The flow name.
	 * @param string $status The lifecycle status.
	 * @param array  $nodes  The nodes.
	 * @param array  $edges  The edges.
	 *
	 * @return object The flow.
	 */
	private function flow(string $name, string $status, array $nodes, array $edges): object {
		return new class($name, $status, $nodes, $edges, $this->calls) {
			/**
			 * @param ArrayObject<int, string> $calls
			 */
			public function __construct(
				private string $name,
				public string $status,
				public array $nodes,
				public array $edges,
				private ArrayObject $calls,
			) {
			}

			public function getName(): string {
				return $this->name;
			}

			public function getUuid(): string {
				return 'uuid-' . $this->name;
			}

			public function getLifecycleStatus(): string {
				return $this->status;
			}

			public function setLifecycleStatus(string $status): void {
				$this->status = $status;
			}

			public function getNodes(): array {
				return $this->nodes;
			}

			public function getEdges(): array {
				return $this->edges;
			}

			public function setNodes(array $nodes): void {
				$this->calls[] = 'setNodes:' . $this->name;
				$this->nodes = $nodes;
			}

			public function setEdges(array $edges): void {
				$this->edges = $edges;
			}
		};
	}//end flow()

	/**
	 * The step under test over the given flows.
	 *
	 * @param array<int, object> $flows         The stored flows.
	 * @param array<int, array>  $actions       Stored automaticAction rows of the retired type.
	 * @param bool               $flowsPresent  Whether OpenRegister's flow classes resolve.
	 * @param bool               $publishFails  Whether publishing throws.
	 * @param IEventDispatcher|null $events Carries a Source request to integriq; a silent one when null.
	 *
	 * @return RewriteRetiredFlowNodes The step.
	 */
	private function step(array $flows, array $actions = [], bool $flowsPresent = true, bool $publishFails = false, ?IEventDispatcher $events = null): RewriteRetiredFlowNodes {
		$calls = $this->calls;

		$mapper = new class($flows, $calls) {
			public function __construct(private array $flows, private ArrayObject $calls) {
			}

			public function findAllFlows(?string $app = null, int $limit = 100, int $offset = 0): array {
				$this->calls[] = 'findAllFlows:' . $app;
				return array_slice($this->flows, $offset, $limit);
			}

			public function update(object $flow): object {
				$this->calls[] = 'update:' . $flow->getName();
				return $flow;
			}
		};

		$versions = new class($calls, $publishFails) {
			public function __construct(private ArrayObject $calls, private bool $publishFails) {
			}

			public function createDraft(object $flow): object {
				$this->calls[] = 'createDraft:' . $flow->getName();
				$flow->setLifecycleStatus('draft');
				return new \stdClass();
			}

			public function publish(object $flow): object {
				if ($this->publishFails === true) {
					throw new RuntimeException('dead end');
				}

				$this->calls[] = 'publish:' . $flow->getName();
				$flow->setLifecycleStatus('published');
				return new \stdClass();
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($mapper, $versions, $flowsPresent): object {
				if ($flowsPresent === false) {
					throw new RuntimeException('not installed');
				}

				return (str_ends_with($name, 'FlowMapper') === true) ? $mapper : $versions;
			}
		);

		$objectService = new class($actions, $calls) {
			public function __construct(private array $actions, private ArrayObject $calls) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = 'search:' . $schema . ':' . (string)($filters['type'] ?? '');
				return array_values(
					array_filter($this->actions, static fn (array $row): bool => ($row['type'] ?? '') === ($filters['type'] ?? ''))
				);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ['register' => 'dossiq', 'automatic_action_schema' => 'automaticAction'][$key] ?? ''
		);

		$map = new RetiredNodeMap();

		return new RewriteRetiredFlowNodes($container, $settings, $map, new RetiredNodeRewriter($map, $this->translator(events: $events)), new NullLogger());
	}//end step()

	/**
	 * An output that records its warnings.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return $output;
	}//end output()

	/**
	 * The migrated reminder flow's graph.
	 *
	 * @return array{0: array, 1: array} Nodes and edges.
	 */
	private function reminderGraph(): array {
		return [
			[
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'action', 'type' => 'dossiq.action.scheduleReminder'],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			[
				['id' => 'trigger-action', 'from' => ['trigger'], 'to' => ['action']],
				['id' => 'action-end', 'from' => ['action'], 'to' => ['end']],
			],
		];
	}//end reminderGraph()

	/**
	 * A published flow is rewritten through a draft that is then published.
	 *
	 * @return void
	 */
	public function testAPublishedFlowIsRewrittenThroughANewVersion(): void {
		[$nodes, $edges] = $this->reminderGraph();
		$flow = $this->flow('herinnering', 'published', $nodes, $edges);

		$this->step([$flow])->run($this->recordingOutput());

		self::assertSame(['trigger', 'end'], array_column($flow->nodes, 'id'));
		self::assertSame('published', $flow->status);
		self::assertSame(
			['findAllFlows:dossiq', 'createDraft:herinnering', 'setNodes:herinnering', 'update:herinnering', 'publish:herinnering', 'search:automaticAction:scheduleReminder'],
			$this->calls->getArrayCopy()
		);
		self::assertCount(1, $this->warnings);
		self::assertStringContainsString('"herinnering"', $this->warnings[0]);
		self::assertStringContainsString('step "action" removed', $this->warnings[0]);
	}//end testAPublishedFlowIsRewrittenThroughANewVersion()

	/**
	 * A draft is rewritten in place; no version is created or published.
	 *
	 * @return void
	 */
	public function testADraftFlowIsRewrittenInPlace(): void {
		[$nodes, $edges] = $this->reminderGraph();
		$flow = $this->flow('concept', 'draft', $nodes, $edges);

		$this->step([$flow])->run($this->recordingOutput());

		self::assertSame(['trigger', 'end'], array_column($flow->nodes, 'id'));
		self::assertNotContains('createDraft:concept', $this->calls->getArrayCopy());
		self::assertNotContains('publish:concept', $this->calls->getArrayCopy());
		self::assertContains('update:concept', $this->calls->getArrayCopy());
	}//end testADraftFlowIsRewrittenInPlace()

	/**
	 * A flow without a retired step is not saved, so a second run is a no-op.
	 *
	 * @return void
	 */
	public function testAFlowWithoutARetiredStepIsNotSaved(): void {
		[$nodes, $edges] = $this->reminderGraph();
		$flow = $this->flow('herinnering', 'published', $nodes, $edges);
		$step = $this->step([$flow]);

		$step->run($this->recordingOutput());
		$this->calls->exchangeArray([]);
		$step->run($this->recordingOutput());

		self::assertSame(
			['findAllFlows:dossiq', 'search:automaticAction:scheduleReminder'],
			$this->calls->getArrayCopy()
		);
	}//end testAFlowWithoutARetiredStepIsNotSaved()

	/**
	 * A failed publish is reported by flow name and does not stop the step.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsReportedAndTheWalkContinues(): void {
		[$nodes, $edges] = $this->reminderGraph();
		$first = $this->flow('eerste', 'published', $nodes, $edges);
		$second = $this->flow('tweede', 'published', $nodes, $edges);

		$this->step([$first, $second], publishFails: true)->run($this->recordingOutput());

		$couldNot = array_values(array_filter($this->warnings->getArrayCopy(), fn (string $w): bool => str_contains($w, 'could not save')));
		self::assertCount(2, $couldNot);
		self::assertStringContainsString('"eerste"', $couldNot[0]);
		self::assertStringContainsString('"tweede"', $couldNot[1]);
	}//end testAFailedSaveIsReportedAndTheWalkContinues()

	/**
	 * A stored reminder action is named in a warning and never written.
	 *
	 * The object-service fake has no write method at all, so any attempt to
	 * change the record would fail this test with an undefined-method error.
	 *
	 * @return void
	 */
	public function testAStoredReminderActionIsReportedAndLeftAlone(): void {
		$this->step([], actions: [['slug' => 'herinner-na-24-uur', 'type' => 'scheduleReminder']])->run($this->recordingOutput());

		self::assertCount(1, $this->warnings);
		self::assertStringContainsString('"herinner-na-24-uur"', $this->warnings[0]);
		self::assertStringContainsString('It is kept, but it will not run.', $this->warnings[0]);
	}//end testAStoredReminderActionIsReportedAndLeftAlone()

	/**
	 * Without OpenRegister's flow classes the step skips the flows and still reports actions.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterFlowsTheStepStillReportsActions(): void {
		$this->step([], actions: [['slug' => 'x', 'type' => 'scheduleReminder']], flowsPresent: false)->run($this->recordingOutput());

		self::assertSame(['search:automaticAction:scheduleReminder'], $this->calls->getArrayCopy());
		self::assertCount(1, $this->warnings);
	}//end testWithoutOpenRegisterFlowsTheStepStillReportsActions()

	/**
	 * The real translator, over a configured register and case schema.
	 *
	 * @param IEventDispatcher|null $events Carries a Source request to integriq; a silent one when null.
	 *
	 * @return RetiredNodeTranslator The translator.
	 */
	private function translator(?IEventDispatcher $events = null): RetiredNodeTranslator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '12', 'case_schema' => '34'][$key] ?? ''
		);
		$syntax = new RetiredTemplateSyntax();

		return new RetiredNodeTranslator(
			$settings,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IL10N::class),
			$syntax,
			new RetiredDocumentSteps($syntax),
			new RetiredWebhookSteps(($events ?? $this->createMock(IEventDispatcher::class)), $this->createMock(IUserSession::class), $syntax)
		);
	}//end translator()

	/**
	 * A webhook step becomes integriq's source call when integriq gives a Source for its URL.
	 *
	 * @return void
	 */
	public function testAWebhookStepIsRewrittenToASourceCall(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (Event $event): void {
				if ($event instanceof SourceRequestedEvent) {
					$event->setSource(sourceId: 'src-hooks', sourceSlug: 'url-https-hooks-example-org', created: true);
				}
			}
		);
		$flow = $this->flow(
			'webhook',
			'published',
			[
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'hook', 'type' => 'dossiq.webhook', 'config' => ['url' => 'https://hooks.example.org/x']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			[['id' => 'e', 'from' => 'trigger', 'to' => 'hook'], ['id' => 'f', 'from' => 'hook', 'to' => 'end']]
		);

		$this->step([$flow], events: $events)->run($this->recordingOutput());

		self::assertSame('openconnector.source-call', $flow->nodes[1]['type']);
		self::assertSame('src-hooks', $flow->nodes[1]['config']['source']);
		self::assertSame('/x', $flow->nodes[1]['config']['endpoint']);
		self::assertSame(['case' => '{{ @item }}'], $flow->nodes[1]['config']['body']);
		self::assertContains('publish:webhook', $this->calls->getArrayCopy());
	}//end testAWebhookStepIsRewrittenToASourceCall()

	/**
	 * A flow whose only retired step cannot be carried over is warned about and not saved.
	 *
	 * @return void
	 */
	public function testAnUnmappableStepIsWarnedAboutAndTheFlowIsNotSaved(): void {
		$flow = $this->flow(
			'webhook',
			'published',
			[
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'hook', 'type' => 'dossiq.webhook', 'config' => ['url' => 'https://hooks.example.org/x']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			[['id' => 'e', 'from' => 'trigger', 'to' => 'hook'], ['id' => 'f', 'from' => 'hook', 'to' => 'end']]
		);

		$this->step([$flow])->run($this->recordingOutput());

		self::assertSame('dossiq.webhook', $flow->nodes[1]['type']);
		self::assertNotContains('update:webhook', $this->calls->getArrayCopy());
		self::assertNotContains('createDraft:webhook', $this->calls->getArrayCopy());
		self::assertCount(1, $this->warnings);
		self::assertStringContainsString('step "hook" could not be carried over', $this->warnings[0]);
		self::assertStringContainsString('hooks.example.org', $this->warnings[0]);
	}//end testAnUnmappableStepIsWarnedAboutAndTheFlowIsNotSaved()

	/**
	 * A translated step is saved as its replacement, through a new version.
	 *
	 * @return void
	 */
	public function testATranslatedStepIsSavedAsItsReplacement(): void {
		$flow = $this->flow(
			'besluit',
			'published',
			[
				['id' => 'trigger', 'type' => 'openregister.trigger-manual'],
				['id' => 'doc', 'type' => 'dossiq.action.mergeTemplate', 'config' => ['template' => 'B {{case.title}}', 'targetField' => 'besluitDocument']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			[['id' => 'e', 'from' => 'trigger', 'to' => 'doc'], ['id' => 'f', 'from' => 'doc', 'to' => 'end']]
		);

		$this->step([$flow])->run($this->recordingOutput());

		self::assertSame('filinq.generate-document', $flow->nodes[1]['type']);
		self::assertSame('B {{ item.title }}', $flow->nodes[1]['config']['template']);
		self::assertContains('publish:besluit', $this->calls->getArrayCopy());
		self::assertSame([], $this->warnings->getArrayCopy());
	}//end testATranslatedStepIsSavedAsItsReplacement()
}//end class
