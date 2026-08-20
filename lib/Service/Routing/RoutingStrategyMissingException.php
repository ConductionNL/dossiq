<?php

/**
 * Procest Routing Strategy Missing Exception
 *
 * Thrown by StrategyRegistry::get() when a routing rule references a strategy
 * name that has not been registered. Callers translate this to a 422 in
 * controller endpoints and to a "Strategie niet gevonden" validation error
 * in the admin UI.
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use RuntimeException;

/**
 * Thrown when a rule references an unregistered routing strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class RoutingStrategyMissingException extends RuntimeException {
}//end class
