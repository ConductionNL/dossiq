<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for OpenRegister's published flow-node contract.
 *
 * dossiq's nodes implement this interface, so without it the classes cannot be
 * loaded in a unit test on an instance where OpenRegister is absent. Mirrors
 * openregister lib/Service/Flow/IFlowNode.php — if that contract changes, this
 * stub is where dossiq finds out.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Stub of OpenRegister's IFlowNode.
 */
interface IFlowNode {

    /**
     * The namespaced node id.
     *
     * @return string The id.
     */
    public function getId(): string;

    /**
     * The node's display name.
     *
     * @return string The name.
     */
    public function getDisplayName(): string;

    /**
     * What the node does.
     *
     * @return string The description.
     */
    public function getDescription(): string;

    /**
     * The node icon path.
     *
     * @return string The path.
     */
    public function getIcon(): string;

    /**
     * Whether the node is offered in a scope.
     *
     * @param integer $scope The workflow scope.
     *
     * @return boolean True when available.
     */
    public function isAvailableForScope(int $scope): bool;

    /**
     * Reject a config the node cannot act on.
     *
     * @param array<string, mixed> $config The step config.
     *
     * @return void
     */
    public function validateConfig(array $config): void;

    /**
     * Run the node over a batch of items.
     *
     * @param array<int, array<string, mixed>> $items   The items.
     * @param array<string, mixed>             $config  The step config.
     * @param array<string, mixed>             $context The run context.
     *
     * @return array<int, array<string, mixed>> The resulting items.
     */
    public function execute(array $items, array $config, array $context): array;

}//end interface
