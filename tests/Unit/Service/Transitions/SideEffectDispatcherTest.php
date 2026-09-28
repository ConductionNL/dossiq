<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Transitions
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCP\IL10N;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCP\IUserSession;
use OCA\Dossiq\Service\Flow\RetiredActionRunner;
use OCA\Integriq\Event\SourceRequestedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the transition side-effect dispatcher after the flow-node port.
 *
 * It had NO direct tests before this — both suites that mention it mock it away
 * — while it is the thing that decides whether a status change fires its
 * actions at all.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class SideEffectDispatcherTest extends TestCase {


    /**
     * A node that records nothing and either succeeds or throws.
     *
     * @param string          $id     The node id.
     * @param \Throwable|null $throws Optional failure to raise.
     *
     * @return IFlowNode The node.
     */
    private function node(string $id, ?\Throwable $throws=null): IFlowNode {
        $node = $this->createMock(IFlowNode::class);
        $node->method('getId')->willReturn($id);
        if ($throws !== null) {
            $node->method('execute')->willThrowException($throws);
        } else {
            $node->method('execute')->willReturnArgument(0);
        }

        return $node;

    }//end node()


    /**
     * Build the dispatcher over an optional node catalogue.
     *
     * @param FlowNodeRegistry|null      $nodes  The catalogue, or null for the fallback path.
     * @param ActionHandlerRegistry|null $legacy The local registry.
     * @param RetiredActionRunner|null   $retired Runs retired types as their replacements.
     *
     * @return SideEffectDispatcher The dispatcher.
     */
    private function dispatcher(?FlowNodeRegistry $nodes, ?ActionHandlerRegistry $legacy=null, ?RetiredActionRunner $retired=null): SideEffectDispatcher {
        $container = $this->createMock(ContainerInterface::class);
        if ($nodes !== null) {
            $container->method('get')->willReturn($nodes);
        } else {
            $container->method('get')->willThrowException(new RuntimeException('absent'));
        }

        return new SideEffectDispatcher(
            $legacy ?? $this->createMock(ActionHandlerRegistry::class),
            $container,
            $this->createMock(LoggerInterface::class),
            $retired
        );

    }//end dispatcher()


    /**
     * An empty node catalogue, built the way the real one must be.
     *
     * The registry takes a dispatcher and a logger — it collects contributed
     * nodes through the first — and these tests register into it directly, so
     * neither dependency does anything here. They are passed because leaving
     * them out is a fatal against the real class, which is what a no-argument
     * stub hid for six call sites.
     *
     * @return FlowNodeRegistry The catalogue.
     */
    private function registry(): FlowNodeRegistry {
        return new FlowNodeRegistry(
            $this->createMock(IEventDispatcher::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end registry()


    /**
     * With OpenRegister present, a transition action runs the SHARED node.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testActionsRunThroughTheSharedNode(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.createTask'));

        $results = $this->dispatcher($registry)->dispatch(
            [['type' => 'createTask']],
            ['id' => 'case-1'],
            ['transition' => 'submitted']
        );

        $this->assertSame([['type' => 'createTask', 'ok' => true]], $results);

    }//end testActionsRunThroughTheSharedNode()


    /**
     * The dispatcher resolves the LIVE id space, not the catalogue's.
     *
     * A transition type resolves as `dossiq.<type>`; a node under any other
     * prefix is not the one a declaration means.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testItResolvesTheLiveIdSpace(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.action.createTask'));

        $results = $this->dispatcher($registry)->dispatch([['type' => 'createTask']], [], []);

        $this->assertFalse($results[0]['ok']);
        $this->assertSame('unknown_action_type', $results[0]['error']);

    }//end testItResolvesTheLiveIdSpace()


    /**
     * A node that throws becomes a failed row — it does NOT abort the loop.
     *
     * A node signals failure by throwing, because the flow engine's onError
     * policy only sees what propagates. This dispatcher's contract is the
     * opposite and predates it: a failed action must not stop the remaining
     * ones or roll back the status change.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testAFailedActionDoesNotAbortTheRest(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.createTask', new RuntimeException('smtp down')));
        $registry->register($this->node('dossiq.createSubCase'));

        $results = $this->dispatcher($registry)->dispatch(
            [['type' => 'createTask'], ['type' => 'createSubCase']],
            [],
            []
        );

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame('smtp down', $results[0]['error']);
        $this->assertTrue($results[1]['ok']);

    }//end testAFailedActionDoesNotAbortTheRest()


    /**
     * An action type nothing provides is reported, not silently dropped.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnknownActionTypeIsReported(): void {
        $results = $this->dispatcher($this->registry())->dispatch(
            [['type' => 'doesNotExist']],
            [],
            []
        );

        $this->assertSame(
            [['type' => 'doesNotExist', 'ok' => false, 'error' => 'unknown_action_type']],
            $results
        );

    }//end testUnknownActionTypeIsReported()


    /**
     * Without OpenRegister the local handlers still fire.
     *
     * A transition must never silently skip its side effects because a
     * neighbouring app is not installed.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testFallsBackToTheLocalHandlersWithoutOpenRegister(): void {
        $handler = $this->createMock(ActionHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new ActionResult(true));

        $legacy = $this->createMock(ActionHandlerRegistry::class);
        $legacy->method('getHandler')->willReturn($handler);

        $results = $this->dispatcher(null, $legacy)->dispatch([['type' => 'createTask']], [], []);

        $this->assertSame([['type' => 'createTask', 'ok' => true]], $results);

    }//end testFallsBackToTheLocalHandlersWithoutOpenRegister()


    /**
     * An action with no type is skipped rather than dispatched blind.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testTypelessActionIsSkipped(): void {
        $this->assertSame(
            [],
            $this->dispatcher($this->registry())->dispatch([['config' => 1]], [], [])
        );

    }//end testTypelessActionIsSkipped()


    /**
     * The real retired-action runner over a node catalogue.
     *
     * @param FlowNodeRegistry      $nodes      The catalogue.
     * @param IEventDispatcher|null $dispatcher Carries the Source request to integriq; a silent one when null.
     *
     * @return RetiredActionRunner The runner.
     */
    private function retired(FlowNodeRegistry $nodes, ?IEventDispatcher $dispatcher=null): RetiredActionRunner {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => ['register' => '12', 'case_schema' => '34'][$key] ?? ''
        );
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $params=[]): string => vsprintf($text, $params)
        );
        $syntax    = new RetiredTemplateSyntax();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($nodes);

        return new RetiredActionRunner(
            new RetiredNodeMap(),
            new RetiredNodeTranslator($settings, $container, $l10n, $syntax, new RetiredDocumentSteps($syntax), new RetiredWebhookSteps(($dispatcher ?? $this->createMock(IEventDispatcher::class)), $this->createMock(IUserSession::class), $syntax)),
            $container,
            $this->createMock(LoggerInterface::class)
        );

    }//end retired()


    /**
     * A declared `notify` runs OpenRegister's notification step, translated, as the user who moved the case.
     *
     * @return void
     *
     * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
     */
    public function testARetiredDeclarationRunsAsItsReplacement(): void {
        $seen     = [];
        $registry = $this->registry();
        $node     = $this->createMock(IFlowNode::class);
        $node->method('getId')->willReturn('openregister.send-notification');
        $node->expects($this->once())->method('execute')->willReturnCallback(
            static function (array $items, array $config, array $context) use (&$seen): array {
                $seen = ['items' => $items, 'config' => $config, 'context' => $context];
                return $items;
            }
        );
        $registry->register($node);

        $results = $this->dispatcher($registry, null, $this->retired($registry))->dispatch(
            [['type' => 'notify', 'message' => 'Uw bezwaar is afgehandeld']],
            ['id' => 'case-1', 'assignee' => 'jan'],
            ['transitionLabel' => 'Afronden', 'userId' => 'behandelaar1']
        );

        $this->assertSame([['type' => 'notify', 'ok' => true]], $results);
        $this->assertSame(['{{ assignee }}'], $seen['config']['recipients']);
        $this->assertSame('A case you handle changed status: Afronden', $seen['config']['title']);
        $this->assertSame('behandelaar1', $seen['context']['runAs']);
        $this->assertSame('case-1', $seen['items'][0]['json']['id']);

    }//end testARetiredDeclarationRunsAsItsReplacement()


    /**
     * A declared `setField` runs both steps it became, in order, passing the item on.
     *
     * @return void
     *
     * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
     */
    public function testATwoStepReplacementRunsInOrder(): void {
        $order    = [];
        $registry = $this->registry();
        foreach (['openregister.set-fields', 'openregister.object-write'] as $id) {
            $node = $this->createMock(IFlowNode::class);
            $node->method('getId')->willReturn($id);
            $node->method('execute')->willReturnCallback(
                static function (array $items, array $config) use ($id, &$order): array {
                    $order[] = $id;
                    if ($id === 'openregister.set-fields') {
                        $items[0]['json']['archiveStatus'] = $config['set']['archiveStatus'];
                    } else {
                        $order[] = $items[0]['json']['archiveStatus'];
                    }

                    return $items;
                }
            );
            $registry->register($node);
        }

        $results = $this->dispatcher($registry, null, $this->retired($registry))->dispatch(
            [['type' => 'setField', 'field' => 'archiveStatus', 'value' => 'gearchiveerd']],
            ['id' => 'case-1'],
            ['userId' => 'u']
        );

        $this->assertSame([['type' => 'setField', 'ok' => true]], $results);
        $this->assertSame(['openregister.set-fields', 'openregister.object-write', 'gearchiveerd'], $order);

    }//end testATwoStepReplacementRunsInOrder()


    /**
     * A declared webhook runs as integriq's source call: the URL's base as a Source, the case and the transition as the body.
     *
     * @return void
     *
     * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
     */
    public function testARetiredWebhookRunsAsASourceCall(): void {
        $requested = [];
        $events    = $this->createMock(IEventDispatcher::class);
        $events->expects($this->once())->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use (&$requested): void {
                if ($event instanceof SourceRequestedEvent) {
                    $requested = ['base' => $event->getBaseUrl(), 'timeout' => $event->getTimeoutSeconds()];
                    $event->setSource(sourceId: 'src-1', sourceSlug: 'url-https-hooks-example-org', created: true);
                }
            }
        );

        $seen     = [];
        $registry = $this->registry();
        $node     = $this->createMock(IFlowNode::class);
        $node->method('getId')->willReturn('openconnector.source-call');
        $node->expects($this->once())->method('execute')->willReturnCallback(
            static function (array $items, array $config, array $context) use (&$seen): array {
                $seen = ['items' => $items, 'config' => $config, 'context' => $context];
                return $items;
            }
        );
        $registry->register($node);

        $transition = ['transitionLabel' => 'Afronden', 'userId' => 'behandelaar1', 'to' => 'afgehandeld'];
        $results    = $this->dispatcher($registry, null, $this->retired($registry, $events))->dispatch(
            [['type' => 'webhook', 'url' => 'https://hooks.example.org/case-events?kind=status', 'headers' => ['X-Zaak' => 'ja']]],
            ['id' => 'case-1'],
            $transition
        );

        $this->assertSame([['type' => 'webhook', 'ok' => true]], $results);
        $this->assertSame(['base' => 'https://hooks.example.org', 'timeout' => 5], $requested);
        $this->assertSame('src-1', $seen['config']['source']);
        $this->assertSame('/case-events?kind=status', $seen['config']['endpoint']);
        $this->assertSame('POST', $seen['config']['method']);
        $this->assertSame(['X-Zaak' => 'ja'], $seen['config']['headers']);
        $this->assertSame(['case' => '{{ @item }}', 'transition' => $transition], $seen['config']['body']);
        $this->assertSame('behandelaar1', $seen['context']['triggeredBy']);
        $this->assertSame('case-1', $seen['items'][0]['json']['id']);

    }//end testARetiredWebhookRunsAsASourceCall()


    /**
     * Without integriq answering, a declared webhook fails with the reason rather than reading as unknown.
     *
     * @return void
     *
     * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
     */
    public function testARetiredWebhookReportsWhyItCannotRun(): void {
        $registry = $this->registry();

        $results = $this->dispatcher($registry, null, $this->retired($registry))->dispatch(
            [['type' => 'webhook', 'url' => 'https://hooks.example.org/x']],
            [],
            []
        );

        $this->assertFalse($results[0]['ok']);
        $this->assertStringStartsWith('retired_action_unmappable: ', $results[0]['error']);
        $this->assertStringContainsString('hooks.example.org', $results[0]['error']);

    }//end testARetiredWebhookReportsWhyItCannotRun()


    /**
     * The kept vocabulary still runs its own node when the runner is wired.
     *
     * @return void
     *
     * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
     */
    public function testAKeptTypeStillRunsItsOwnNode(): void {
        $registry = $this->registry();
        $registry->register($this->node('dossiq.createTask'));

        $results = $this->dispatcher($registry, null, $this->retired($registry))->dispatch(
            [['type' => 'createTask', 'title' => 'Bel de aanvrager']],
            ['id' => 'case-1'],
            []
        );

        $this->assertSame([['type' => 'createTask', 'ok' => true]], $results);

    }//end testAKeptTypeStillRunsItsOwnNode()


}//end class
