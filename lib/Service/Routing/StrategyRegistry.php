<?php

/**
 * Procest Strategy Registry
 *
 * Holds the built-in routing strategies (single-role, or-set, hierarchical,
 * round-robin, least-loaded) and exposes lookup by strategy name. Rules
 * referencing an unknown strategy cause RoutingStrategyMissingException so
 * the admin UI can block saving and the resolver can fail loudly.
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Routing;

use OCA\Procest\Service\Routing\Strategy\HierarchicalStrategy;
use OCA\Procest\Service\Routing\Strategy\LeastLoadedStrategy;
use OCA\Procest\Service\Routing\Strategy\OrSetStrategy;
use OCA\Procest\Service\Routing\Strategy\RoundRobinStrategy;
use OCA\Procest\Service\Routing\Strategy\SingleRoleStrategy;

/**
 * Registry of routing strategies.
 *
 * The five built-in strategies are injected by name; additional strategies
 * MAY be appended via register() (used by tests). The registry never silently
 * substitutes a default — unknown strategies always throw.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class StrategyRegistry
{
    /**
     * The registered strategies keyed by name.
     *
     * @var array<string, RoutingStrategyInterface>
     */
    private array $strategies = [];

    /**
     * Constructor.
     *
     * @param SingleRoleStrategy   $singleRole   Single-role strategy
     * @param OrSetStrategy        $orSet        OR-set strategy
     * @param HierarchicalStrategy $hierarchical Hierarchical strategy
     * @param RoundRobinStrategy   $roundRobin   Round-robin strategy
     * @param LeastLoadedStrategy  $leastLoaded  Least-loaded strategy
     */
    public function __construct(
        SingleRoleStrategy $singleRole,
        OrSetStrategy $orSet,
        HierarchicalStrategy $hierarchical,
        RoundRobinStrategy $roundRobin,
        LeastLoadedStrategy $leastLoaded,
    ) {
        $this->register($singleRole);
        $this->register($orSet);
        $this->register($hierarchical);
        $this->register($roundRobin);
        $this->register($leastLoaded);
    }//end __construct()

    /**
     * Register a strategy under its name().
     *
     * @param RoutingStrategyInterface $strategy The strategy to register
     *
     * @return void
     */
    public function register(RoutingStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->name()] = $strategy;
    }//end register()

    /**
     * List the names of all registered strategies.
     *
     * @return array<int, string>
     */
    public function list(): array
    {
        return array_keys($this->strategies);
    }//end list()

    /**
     * Whether a strategy with the given name is registered.
     *
     * @param string $name The strategy name
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->strategies[$name]);
    }//end has()

    /**
     * Fetch a strategy by name.
     *
     * @param string $name The strategy name
     *
     * @return RoutingStrategyInterface
     *
     * @throws RoutingStrategyMissingException When the strategy is not registered
     */
    public function get(string $name): RoutingStrategyInterface
    {
        if (isset($this->strategies[$name]) === false) {
            throw new RoutingStrategyMissingException(
                sprintf('Routing strategy "%s" is not registered', $name)
            );
        }

        return $this->strategies[$name];
    }//end get()
}//end class
