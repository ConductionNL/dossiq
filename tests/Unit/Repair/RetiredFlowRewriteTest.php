<?php

/**
 * Unit tests for the flow walk of RewriteRetiredFlowNodes.
 *
 * The rewriter is stubbed per flow: a flow's first node names what its
 * rewrite answers. The mapper pages the flows and can fail on one page;
 * every call, output line and log line is recorded in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RewriteRetiredFlowNodes;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Characterisation tests for the retired flow rewrite walk.
 *
 * @covers \OCA\Dossiq\Repair\RewriteRetiredFlowNodes
 */
class RetiredFlowRewriteTest extends TestCase {

	/**
	 * Every call, output line and log line, in order.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * A stored flow whose first node names what its rewrite answers.
	 *
	 * @param string $name   The flow name.
	 * @param string $kind   none, replaced, unmappable or mixed.
	 * @param string $status The lifecycle status.
	 *
	 * @return object The flow.
	 */
	private function flow(string $name, string $kind, string $status='draft'): object {
		$said = &$this->said;
		return new class($name, $kind, $status, $said) {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private string $name, private string $kind, private string $status, private array &$said) {
			}

			/**
			 * @return string The name.
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'uuid-'.$this->name;
			}

			/**
			 * @return string The status.
			 */
			public function getLifecycleStatus(): string {
				return $this->status;
			}

			/**
			 * @return array<int, array<string, string>> The nodes.
			 */
			public function getNodes(): array {
				return [['kind' => $this->kind]];
			}

			/**
			 * @return array<int, mixed> The edges.
			 */
			public function getEdges(): array {
				return [];
			}

			/**
			 * @param array<int, mixed> $nodes The nodes.
			 *
			 * @return void
			 */
			public function setNodes(array $nodes): void {
				$this->said[] = 'setNodes '.$this->name.' '.json_encode($nodes);
			}

			/**
			 * @param array<int, mixed> $edges The edges.
			 *
			 * @return void
			 */
			public function setEdges(array $edges): void {
				$this->said[] = 'setEdges '.$this->name.' '.json_encode($edges);
			}
		};
	}//end flow()

	/**
	 * The step over the given flows.
	 *
	 * @param array<int, mixed> $flows    The stored flows (non-objects included).
	 * @param int|null          $failAt   The offset whose page read fails.
	 * @param bool              $present  Whether OpenRegister's flow classes resolve.
	 *
	 * @return RewriteRetiredFlowNodes The step.
	 */
	private function step(array $flows, ?int $failAt=null, bool $present=true): RewriteRetiredFlowNodes {
		$said = &$this->said;
		$mapper = new class($flows, $failAt, $said) {
			/**
			 * @param array<int, mixed>  $flows  The flows.
			 * @param int|null           $failAt The failing offset.
			 * @param array<int, string> $said   The shared record.
			 */
			public function __construct(private array $flows, private ?int $failAt, private array &$said) {
			}

			/**
			 * @return array<int, mixed> The page.
			 */
			public function findAllFlows(?string $app=null, int $limit=100, int $offset=0): array {
				$this->said[] = 'findAllFlows '.$app.' '.$limit.' '.$offset;
				if ($offset === $this->failAt) {
					throw new RuntimeException('flows unreadable');
				}

				return array_slice($this->flows, $offset, $limit);
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return object The flow.
			 */
			public function update(object $flow): object {
				$this->said[] = 'update '.$flow->getName();
				return $flow;
			}
		};
		$versions = new class($said) {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private array &$said) {
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return object A version.
			 */
			public function createDraft(object $flow): object {
				$this->said[] = 'createDraft '.$flow->getName();
				return new \stdClass();
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return object A version.
			 */
			public function publish(object $flow): object {
				if ($flow->getName() === 'stuck') {
					throw new RuntimeException('dead end');
				}

				$this->said[] = 'publish '.$flow->getName();
				return new \stdClass();
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($mapper, $versions, $present): object {
				if ($present === false) {
					throw new RuntimeException('not installed');
				}

				return (str_ends_with($name, 'FlowMapper') === true) ? $mapper : $versions;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$map = $this->createMock(RetiredNodeMap::class);
		$map->method('retiredActionTypes')->willReturn([]);

		$rewriter = $this->createMock(RetiredNodeRewriter::class);
		$rewriter->method('rewrite')->willReturnCallback(
			static function (array $nodes, array $edges): array {
				$change = static fn (string $outcome): array => ['step' => 's-'.$outcome, 'type' => 'old.type', 'outcome' => $outcome, 'replacement' => 'new.type', 'reason' => 'retired', 'orphaned' => false];
				$changes = match ($nodes[0]['kind']) {
					'replaced' => [$change('replaced')],
					'unmappable' => [$change('unmappable')],
					'mixed' => [$change('unmappable'), $change('removed')],
					default => [],
				};

				return ['nodes' => [['kind' => 'rewritten']], 'edges' => [['e' => 1]], 'changes' => $changes];
			}
		);

		$logger = new class($said) extends AbstractLogger {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private array &$said) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->said[] = 'log-'.$level.': '.$message.' '.json_encode($context);
			}
		};

		return new RewriteRetiredFlowNodes($container, $settings, $map, $rewriter, $logger);
	}//end step()

	/**
	 * The output double.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $text): void {
			$this->said[] = 'info: '.$text;
		});
		$output->method('warning')->willReturnCallback(function (string $text): void {
			$this->said[] = 'warning: '.$text;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * Every page is walked; each flow is rewritten, left, or reported, and the walk says how many it rewrote.
	 *
	 * @return void
	 */
	public function testEveryPageIsWalkedAndEachFlowHandled(): void {
		$flows = array_fill(0, 99, 'not a flow');
		$flows[] = $this->flow('plain', 'none');
		$flows[] = $this->flow('draft', 'replaced');
		$flows[] = $this->flow('live', 'replaced', 'published');
		$flows[] = $this->flow('stuck', 'replaced', 'published');
		$flows[] = $this->flow('only-unmappable', 'unmappable');
		$flows[] = $this->flow('mixed', 'mixed');

		$this->step($flows)->run($this->recordingOutput());

		$replaced = 'step "s-replaced": old.type is now new.type (retired).';
		$unmappable = 'step "s-unmappable" could not be carried over: old.type is retired (retired). Rebuild it in the flow editor; until then the run stops at it.';
		$removed = 'step "s-removed" removed: old.type is retired and has no replacement (retired).';
		$context = static fn (string $flow, string $outcome): string => json_encode(['app' => 'dossiq', 'flow' => '"'.$flow.'" (uuid-'.$flow.')', 'step' => 's-'.$outcome, 'type' => 'old.type']);
		$nodes = '[{"kind":"rewritten"}]';
		$edges = '[{"e":1}]';
		self::assertSame(
			[
				'findAllFlows dossiq 100 0',
				'findAllFlows dossiq 100 100',
				'info: Dossiq: flow "draft" (uuid-draft), '.$replaced,
				'log-info: Dossiq: flow "draft" (uuid-draft), '.$replaced.' '.$context('draft', 'replaced'),
				'setNodes draft '.$nodes,
				'setEdges draft '.$edges,
				'update draft',
				'info: Dossiq: flow "live" (uuid-live), '.$replaced,
				'log-info: Dossiq: flow "live" (uuid-live), '.$replaced.' '.$context('live', 'replaced'),
				'createDraft live',
				'setNodes live '.$nodes,
				'setEdges live '.$edges,
				'update live',
				'publish live',
				'info: Dossiq: flow "stuck" (uuid-stuck), '.$replaced,
				'log-info: Dossiq: flow "stuck" (uuid-stuck), '.$replaced.' '.$context('stuck', 'replaced'),
				'createDraft stuck',
				'setNodes stuck '.$nodes,
				'setEdges stuck '.$edges,
				'update stuck',
				'warning: Dossiq: could not save the rewritten flow "stuck" (uuid-stuck); edit it in the flow editor. dead end',
				'log-warning: Dossiq: could not save the rewritten flow "stuck" (uuid-stuck); edit it in the flow editor. dead end {"app":"dossiq","flow":"uuid-stuck"}',
				'warning: Dossiq: flow "only-unmappable" (uuid-only-unmappable), '.$unmappable,
				'log-warning: Dossiq: flow "only-unmappable" (uuid-only-unmappable), '.$unmappable.' '.$context('only-unmappable', 'unmappable'),
				'warning: Dossiq: flow "mixed" (uuid-mixed), '.$unmappable,
				'log-warning: Dossiq: flow "mixed" (uuid-mixed), '.$unmappable.' '.$context('mixed', 'unmappable'),
				'warning: Dossiq: flow "mixed" (uuid-mixed), '.$removed,
				'log-warning: Dossiq: flow "mixed" (uuid-mixed), '.$removed.' '.$context('mixed', 'removed'),
				'setNodes mixed '.$nodes,
				'setEdges mixed '.$edges,
				'update mixed',
				'info: Dossiq: rewrote 3 flow(s) that used a retired step type.',
			],
			$this->said
		);
	}//end testEveryPageIsWalkedAndEachFlowHandled()

	/**
	 * A page that cannot be read stops the walk without a closing line; absent flow classes skip it.
	 *
	 * @return void
	 */
	public function testAnUnreadablePageStopsTheWalk(): void {
		$flows = array_fill(0, 100, 'not a flow');
		$flows[] = $this->flow('never', 'replaced');

		$this->step($flows, failAt: 100)->run($this->recordingOutput());
		$this->step($flows, present: false)->run($this->recordingOutput());

		self::assertSame(
			[
				'findAllFlows dossiq 100 0',
				'findAllFlows dossiq 100 100',
				'log-warning: Dossiq: could not read the stored flows to rewrite retired steps {"app":"dossiq","exception":"flows unreadable"}',
				'info: Dossiq: OpenRegister flows are not available; no stored flow to rewrite.',
			],
			$this->said
		);
	}//end testAnUnreadablePageStopsTheWalk()
}//end class
