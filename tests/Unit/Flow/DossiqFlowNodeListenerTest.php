<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\Dossiq\Flow\DossiqFlowNodeListener;
use OCA\Dossiq\Flow\DossiqTxCreateTaskNode;
use OCA\Dossiq\Flow\DossiqTxCreateSubCaseNode;
use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Flow\DossiqEnsureCommitteeNode;
use OCA\Dossiq\Flow\DossiqTxSetStatusNode;
use OCA\Dossiq\Flow\DossiqTxBesluitvormingPublishNode;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves dossiq actually contributes every case action it still owns.
 *
 * A node class that exists but is never registered is invisible to the flow
 * editor — and looks identical to one that works, right up until somebody tries
 * to build a flow with it.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class DossiqFlowNodeListenerTest extends TestCase {

    /**
     * The id each node class reports, in the order the listener registers them.
     *
     * @var array<class-string, string>
     */
    private const EXPECTED_IDS = [
        DossiqTxCreateTaskNode::class => 'dossiq.createTask',
        DossiqTxCreateSubCaseNode::class => 'dossiq.createSubCase',
        DossiqTxSetStatusNode::class => 'dossiq.setStatus',
        DossiqAskPersonNode::class => 'dossiq.askPerson',
        DossiqEnsureCommitteeNode::class => 'dossiq.ensureCommittee',
        DossiqTxBesluitvormingPublishNode::class => 'dossiq.besluitvormingPublish',
    ];



    /**
     * Build the listener over a container that yields id-reporting nodes.
     *
     * The listener resolves its nodes from a class-string list, so the test
     * asserts what reaches the CATALOGUE rather than what was injected — which
     * is the thing that actually matters: a node class that exists but never
     * registers is invisible to the flow editor and looks identical to one that
     * works.
     *
     * @param string[] $failing Class names the container should refuse to build.
     *
     * @return DossiqFlowNodeListener The listener under test.
     */
    private function listener(array $failing=[]): DossiqFlowNodeListener {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $class) use ($failing): IFlowNode {
                if (in_array($class, $failing, true) === true) {
                    throw new RuntimeException('cannot construct ' . $class);
                }

                $node = $this->createMock(IFlowNode::class);
                $node->method('getId')->willReturn(self::EXPECTED_IDS[$class]);
                return $node;
            }
        );

        return new DossiqFlowNodeListener($container, $this->createMock(LoggerInterface::class));

    }//end listener()


    /**
     * An empty node catalogue for the listener to contribute to.
     *
     * The registry, not the event, is where a contributed node lands — the
     * event only carries it. Both take constructor arguments the stubs used
     * to omit, which is how six sibling call sites came to build them in a
     * way that fatals against the real OpenRegister while green here.
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
     * Every action lands on the catalogue — both vocabularies.
     *
     * Asserted against the fixture rather than a literal count, so adding a node
     * means adding it in ONE place; a count in the test name goes stale the
     * first time somebody adds a node and does not notice.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testEveryActionIsRegistered(): void {
        $registry = $this->registry();
        $this->listener()->handle(new RegisterFlowNodesEvent($registry));

        $ids = array_keys($registry->all());

        $this->assertSame(
            array_values(self::EXPECTED_IDS),
            $ids
        );

    }//end testEveryActionIsRegistered()


    /**
     * An unrelated event is ignored rather than half-handled.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnrelatedEventIsIgnored(): void {
        $other = new class extends Event {
        };

        $this->listener()->handle($other);

        $this->addToAssertionCount(1);

    }//end testUnrelatedEventIsIgnored()

    /**
     * One unbuildable node does not cost the others their place.
     *
     * The list-based resolution introduced this branch: if a single node's
     * dependencies cannot be constructed, aborting would empty the whole
     * catalogue. A skipped node is visible — the editor simply does not offer
     * it — where a failed registration takes everything down with it.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testOneUnbuildableNodeDoesNotCostTheRest(): void {
        $registry = $this->registry();
        $this->listener(failing: [DossiqTxCreateTaskNode::class])->handle(new RegisterFlowNodesEvent($registry));

        $ids = array_keys($registry->all());

        // Derived from the fixture, not written as a literal. The sibling test
        // above already says why: a count in a test goes stale the first time
        // somebody adds or retires a node, and this one did — it was 17
        // against a catalogue that had grown to 18.
        $this->assertCount((count(self::EXPECTED_IDS) - 1), $ids);
        $this->assertNotContains('dossiq.createTask', $ids);
        $this->assertContains('dossiq.setStatus', $ids);

    }//end testOneUnbuildableNodeDoesNotCostTheRest()


    /**
     * The nodes whose owners now provide them are not offered any more.
     *
     * A stored flow that still names one is rewritten by the repair step; the
     * catalogue itself must not offer a second copy of another app's node.
     *
     * @return void
     *
     * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
     */
    public function testNodesOwnedElsewhereAreNotOffered(): void {
        $registry = $this->registry();
        $this->listener()->handle(new RegisterFlowNodesEvent($registry));

        $ids = array_keys($registry->all());
        foreach (['dossiq.sendEmail', 'dossiq.webhook', 'dossiq.notify', 'dossiq.setField', 'dossiq.evaluateDecision', 'dossiq.requestDecision', 'dossiq.action.sendEmail', 'dossiq.action.notifyRole', 'dossiq.action.callWebhook', 'dossiq.action.createDocument', 'dossiq.action.mergeTemplate'] as $retired) {
            $this->assertNotContains($retired, $ids);
        }

    }//end testOneUnbuildableNodeDoesNotCostTheRest()


}//end class
