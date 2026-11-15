<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow\Support;

use OCA\Dossiq\Service\Transitions\ActionHandlerInterface as TransitionActionHandler;

/**
 * The handler a transition node runs: the one injected as its `$handler`.
 *
 * @spec exclude shared plumbing of the transition flow nodes, covered by their own specs
 */
trait RunsInjectedHandler {


    /**
     * The handler this node runs.
     *
     * @return TransitionActionHandler The action handler.
     */
    protected function handler(): TransitionActionHandler {
        return $this->handler;

    }//end handler()


}//end trait
