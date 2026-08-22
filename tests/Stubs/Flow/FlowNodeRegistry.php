<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's flow-node catalogue.
 *
 * SideEffectDispatcher resolves nodes through it, so the dispatcher's own tests
 * need the type to exist. Declaration-only: the real class wins whenever
 * OpenRegister is installed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use UnexpectedValueException;

/**
 * Stub of OpenRegister's FlowNodeRegistry.
 */
class FlowNodeRegistry {

    /**
     * Registered nodes, keyed by id.
     *
     * @var array<string, IFlowNode>
     */
    private array $nodes = [];


    /**
     * Register a node.
     *
     * @param IFlowNode $node The node.
     *
     * @return void
     */
    public function register(IFlowNode $node): void {
        $this->nodes[$node->getId()] = $node;

    }//end register()


    /**
     * Resolve a node by its type id.
     *
     * @param string $type The node id.
     *
     * @return IFlowNode The node.
     *
     * @throws UnexpectedValueException When no app provides that type.
     */
    public function get(string $type): IFlowNode {
        if (isset($this->nodes[$type]) === false) {
            throw new UnexpectedValueException(sprintf('No node provides "%s".', $type));
        }

        return $this->nodes[$type];

    }//end get()


}//end class
