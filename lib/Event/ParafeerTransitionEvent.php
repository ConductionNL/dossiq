<?php

/**
 * Parafeer Transition Event
 *
 * Domain event dispatched after every successful parafeerroute transition on a
 * voorstel. The ParaferingAuditListener subscribes to this event and writes one
 * append-only paraferingAuditEntry per transition. Application services NEVER
 * write audit entries directly — the event bus is the only entry point so that
 * additional listeners (SIEM streaming, e-Depot push, etc.) can attach later
 * without modifying the routing services.
 *
 * @category Event
 * @package  OCA\Procest\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parafering-audit-trail/tasks.md#T02
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Event;

use OCP\EventDispatcher\Event;

/**
 * Event raised after each parafeerroute transition.
 */
class ParafeerTransitionEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $voorstelId The voorstel UUID/slug
     * @param string      $action     One of started|paraferd|terugsturen|advised|route-changed|completed
     * @param string|null $step       Step identifier (order or UUID) when applicable
     * @param string      $actor      Nextcloud user UID who triggered the transition
     * @param string      $actorRole  Role at the moment of action (steller, parafeerder, etc.)
     * @param string|null $reason     Reason text (mandatory for terugsturen and route-changed)
     */
    public function __construct(
        private readonly string $voorstelId,
        private readonly string $action,
        private readonly ?string $step,
        private readonly string $actor,
        private readonly string $actorRole,
        private readonly ?string $reason = null,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the voorstel id/slug.
     *
     * @return string
     */
    public function getVoorstelId(): string
    {
        return $this->voorstelId;
    }//end getVoorstelId()

    /**
     * Get the transition action.
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }//end getAction()

    /**
     * Get the step identifier (if any).
     *
     * @return string|null
     */
    public function getStep(): ?string
    {
        return $this->step;
    }//end getStep()

    /**
     * Get the actor user UID.
     *
     * @return string
     */
    public function getActor(): string
    {
        return $this->actor;
    }//end getActor()

    /**
     * Get the actor role.
     *
     * @return string
     */
    public function getActorRole(): string
    {
        return $this->actorRole;
    }//end getActorRole()

    /**
     * Get the reason text.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }//end getReason()
}//end class
