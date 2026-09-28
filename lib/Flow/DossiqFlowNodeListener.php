<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Presents dossiq's case actions to OpenRegister's flow engine.
 *
 * ADR-065: OpenRegister owns the flow engine and no leaf app grows a second
 * one. dossiq does not keep one: it CONTRIBUTES what only its cases can do,
 * which is what FlowNodeRegistry is built for and what hermiq already does.
 *
 * ONLY WHAT NO OTHER APP OWNS. Sending mail and notifications, writing an
 * object and evaluating a decision table are OpenRegister's own nodes;
 * generating a document is Filinq's, raising a decision is Decidiq's and an
 * outbound call is Integriq's. dossiq offered its own copies of all of those
 * until change flow-nodes-to-their-owners retired them, and
 * {@see \OCA\Dossiq\Service\Flow\RetiredNodeMap} rewrites stored flows that
 * still name one. What is left here moves a case through its life: its
 * status, its tasks and sub-cases, the person it waits on, its committee and
 * its publication.
 *
 * NODES ARE RESOLVED FROM A LIST, not injected one per constructor parameter.
 * Fifteen named parameters is not a wiring style, it is a coupling problem —
 * phpmd said so at 15 params and a coupling of 17. A class-string list keeps
 * adding a node to one line and keeps this class's own dependencies at two.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 *
 * @psalm-suppress InvalidTemplateParam RegisterFlowNodesEvent is OpenRegister's,
 *     loaded at runtime and suppressed as an undefined class in psalm.xml, so
 *     psalm cannot see that it extends OCP\EventDispatcher\Event and cannot
 *     check the template argument. The annotation is still correct and is what
 *     the engine actually dispatches; only the proof is unavailable here.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class DossiqFlowNodeListener implements IEventListener {

    /**
     * The nodes dossiq contributes, in catalogue order.
     *
     * @var class-string<IFlowNode>[]
     *
     * @psalm-suppress InvalidConstantAssignmentValue Every class listed here
     *     does implement IFlowNode, but IFlowNode is OpenRegister's and is
     *     suppressed as undefined (psalm.xml), so psalm cannot verify the
     *     implements-relationship and rejects the narrowing. The declared type
     *     is the contract this list must satisfy and is kept deliberately —
     *     widening it to plain class-string would silence the error by giving
     *     up the only statement of intent this constant carries.
     */
    private const NODES = [
        DossiqTxCreateTaskNode::class,
        DossiqTxCreateSubCaseNode::class,
        DossiqTxSetStatusNode::class,
        DossiqAskPersonNode::class,
        DossiqEnsureCommitteeNode::class,
        DossiqTxBesluitvormingPublishNode::class,
    ];


    /**
     * Constructor.
     *
     * @param ContainerInterface $container Resolves each node.
     * @param LoggerInterface    $logger    The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Register dossiq's nodes on the catalogue.
     *
     * A node that cannot be constructed is logged and SKIPPED rather than
     * aborting the loop: one unresolvable dependency must not cost the other
     * others their place in the catalogue, and a missing node is visible
     * (the flow editor simply does not offer it) where a failed registration
     * would take everything down with it.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function handle(Event $event): void {
        if (($event instanceof RegisterFlowNodesEvent) === false) {
            return;
        }

        foreach (self::NODES as $class) {
            try {
                $node = $this->container->get($class);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'DossiqFlowNodeListener: could not construct a flow node; it will not be offered',
                    ['node' => $class, 'error' => $e->getMessage()],
                );
                continue;
            }

            if (($node instanceof IFlowNode) === false) {
                continue;
            }

            $event->registerNode(node: $node);
        }//end foreach

    }//end handle()


}//end class
