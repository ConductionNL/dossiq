<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's node-registration collect event.
 *
 * procest's ProcestFlowNodeListener is typed against it, so without this stub
 * the listener cannot be loaded — and phpstan cannot resolve the
 * `IEventListener<RegisterFlowNodesEvent>` generic bound either.
 *
 * DELIBERATELY NOT A FAITHFUL COPY OF THE CONSTRUCTOR. The real event takes
 * OpenRegister's FlowNodeRegistry and hands each node straight to it; procest
 * has no such class and does not need one to prove it registers the right six.
 * This stub collects them instead, which is exactly what a consumer-side test
 * needs to assert.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Stub of OpenRegister's RegisterFlowNodesEvent.
 */
class RegisterFlowNodesEvent extends Event {

    /**
     * The nodes registered on this event.
     *
     * @var IFlowNode[]
     */
    private array $nodes = [];


    /**
     * Register one node on the catalogue.
     *
     * @param IFlowNode $node The node to register.
     *
     * @return void
     */
    public function registerNode(IFlowNode $node): void {
        $this->nodes[] = $node;

    }//end registerNode()


    /**
     * The nodes registered so far. Stub-only accessor.
     *
     * @return IFlowNode[] The registered nodes.
     */
    public function getRegisteredNodes(): array {
        return $this->nodes;

    }//end getRegisteredNodes()


}//end class
