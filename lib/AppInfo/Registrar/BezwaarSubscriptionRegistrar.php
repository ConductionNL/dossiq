<?php

/**
 * Procest bezwaar subscription registrar (boot-time).
 *
 * The bezwaar listeners that declare a register/schema interest up front.
 * Driven from boot() rather than register(): the OpenRegister
 * `ObjectEventSubscription` guard is only resolvable once every app's
 * register() has run, so from register() the guard is boot-order dependent and
 * every app registering before OpenRegister silently took the unfiltered
 * fallback. The deliberately unnarrowed registrations stay in
 * {@see BezwaarListenerRegistrar}.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Listener\BezwaarLegalHoldListener;
use OCA\Procest\Listener\BezwaarLifecycleListener;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Subscribes the narrowed bezwaar listeners once every app has registered.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class BezwaarSubscriptionRegistrar
{
    /**
     * The bezwaar lifecycle observer's schema interest.
     *
     * @var array<int,string>
     */
    private const LIFECYCLE_SCHEMAS = [
        'bezwaar',
        'objection',
        'hearingSession',
        'advisoryReport',
        'decision',
    ];

    /**
     * The legal-hold listener's schema interest — the union of its
     * PROCEEDING_OPENED_SCHEMAS and PROCEEDING_CLOSED_SCHEMAS.
     *
     * @var array<int,string>
     */
    private const LEGAL_HOLD_SCHEMAS = [
        'objection',
        'bezwaar',
        'beroep',
        'bezwaarDecision',
        'appealDecision',
    ];

    /**
     * The register slugs both narrowed listeners react to.
     *
     * @var array<int,string>
     */
    private const REGISTERS = ['procest'];

    /**
     * Subscribe the bezwaar listeners that declare a register/schema interest.
     *
     * @param IEventDispatcher $dispatcher The live event dispatcher.
     *
     * @return void
     *
     * @spec openspec/specs/bezwaar-lifecycle/spec.md
     */
    public function subscribe(IEventDispatcher $dispatcher): void
    {
        // Bezwaar-lifecycle observer — routes bezwaar/hearing/advice/decision
        // events onto the status-transition-engine without duplicating
        // transition logic. See ADR-022 + REQ-BL-8.
        //
        // Declares its register/schema interest up front instead of re-deriving
        // it inside every handler call. Registered globally this listener was
        // invoked on every object write on the instance — a larpingapp character
        // create reached `handle()` and bailed at the
        // `in_array($schemaSlug, RELEVANT_SCHEMAS)` guard.
        $this->subscribeFiltered(
            dispatcher: $dispatcher,
            event: ObjectCreatedEvent::class,
            listener: BezwaarLifecycleListener::class,
            schemas: self::LIFECYCLE_SCHEMAS
        );
        $this->subscribeFiltered(
            dispatcher: $dispatcher,
            event: ObjectUpdatedEvent::class,
            listener: BezwaarLifecycleListener::class,
            schemas: self::LIFECYCLE_SCHEMAS
        );

        // Bezwaar/beroep legal hold: when an Awb proceeding (objection) is
        // registered the linked case gets an OpenRegister legal hold; when the
        // proceeding reaches its final outcome (bezwaarDecision / appealDecision)
        // the hold is released. Hold storage + enforcement are OpenRegister's
        // (ADR-022 / migrate-archival-to-or) — this replaces the retired
        // ArchivalTriggerService `opgeschort-juridische-procedure` status.
        $this->subscribeFiltered(
            dispatcher: $dispatcher,
            event: ObjectCreatedEvent::class,
            listener: BezwaarLegalHoldListener::class,
            schemas: self::LEGAL_HOLD_SCHEMAS
        );
    }//end subscribe()

    /**
     * Subscribe one object-lifecycle listener that declares its interest up front.
     *
     * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
     * a listener reacts to and routes dispatches through a single shared proxy,
     * so an uninterested listener is neither constructed nor invoked. When
     * OpenRegister is absent — procest carries no hard dependency on it — this
     * degrades to the plain global registration it replaced, which is exactly
     * the behaviour every listener had before.
     *
     * StaticAccess is unavoidable here: `ObjectEventSubscription::subscribe()`
     * is OpenRegister's published static entry point and is reached through a
     * `class_exists()` guard on a variable class name precisely so procest keeps
     * no compile-time dependency on the optional app; there is no instance to
     * inject.
     *
     * @param IEventDispatcher  $dispatcher The live event dispatcher.
     * @param string            $event      OpenRegister event class name.
     * @param string            $listener   Listener class name.
     * @param array<int,string> $schemas    Schema slugs the listener reacts to.
     *
     * @return void
     *
     * @spec openspec/specs/bezwaar-lifecycle/spec.md
     */
    private function subscribeFiltered(
        IEventDispatcher $dispatcher,
        string $event,
        string $listener,
        array $schemas
    ): void {
        $subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
        if (class_exists($subscription) === true) {
            $subscription::subscribe(
                dispatcher: $dispatcher,
                event: $event,
                listener: $listener,
                registers: self::REGISTERS,
                schemas: $schemas
            );
            return;
        }

        // Loud on purpose. This fallback is correct but UNFILTERED, and while it
        // was silent it was indistinguishable from a working narrowing.
        Server::get(LoggerInterface::class)->warning(
            'OpenRegister ObjectEventSubscription unavailable: '.$listener
            .' fell back to an UNFILTERED registration for '.$event
            .' and will be invoked on every object write instance-wide.',
            ['app' => Application::APP_ID]
        );

        $dispatcher->addServiceListener($event, $listener);
    }//end subscribeFiltered()
}//end class
