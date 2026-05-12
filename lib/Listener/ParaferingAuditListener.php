<?php

/**
 * Parafering Audit Listener
 *
 * Subscribes to ParafeerTransitionEvent and persists one append-only
 * paraferingAuditEntry per emitted transition. The application services
 * NEVER write audit entries directly — every audit row flows through this
 * single listener so additional consumers (SIEM streaming, e-Depot push)
 * can attach without modifying the routing services.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parafering-audit-trail/tasks.md#T03
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\Procest\Event\ParafeerTransitionEvent;
use OCA\Procest\Service\Parafering\AuditTrailService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listener that writes paraferingAuditEntry rows for each transition event.
 *
 * @implements IEventListener<ParafeerTransitionEvent>
 */
class ParaferingAuditListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AuditTrailService $auditTrailService The audit-trail service
     * @param SettingsService   $settingsService   Procest settings bridge (for voorstel lookup)
     * @param LoggerInterface   $logger            PSR-3 logger
     */
    public function __construct(
        private readonly AuditTrailService $auditTrailService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a ParafeerTransitionEvent.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ParafeerTransitionEvent === false) {
            return;
        }

        try {
            $contentSnapshot = $this->fetchContentSnapshot($event->getVoorstelId());

            $this->auditTrailService->record(
                voorstelId: $event->getVoorstelId(),
                step: $event->getStep(),
                action: $event->getAction(),
                actor: $event->getActor(),
                actorRole: $event->getActorRole(),
                reason: $event->getReason(),
                contentSnapshot: $contentSnapshot,
            );
        } catch (Throwable $e) {
            // Swallow — audit-write failures MUST NOT propagate back to
            // the routing service. Detectable via the OR audit-trail-immutable
            // mutation log and this error log entry.
            $this->logger->error(
                'Procest: ParaferingAuditListener failed',
                [
                    'voorstel'  => $event->getVoorstelId(),
                    'action'    => $event->getAction(),
                    'exception' => $e->getMessage(),
                ],
            );
        }//end try
    }//end handle()

    /**
     * Fetch a content snapshot of the voorstel at transition moment.
     *
     * Returns an empty array when the voorstel cannot be loaded — the audit
     * entry is still recorded so the transition is auditable.
     *
     * @param string $voorstelId The voorstel UUID/slug
     *
     * @return array<string, mixed>
     */
    private function fetchContentSnapshot(string $voorstelId): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return [];
            }

            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('voorstel_schema');
            if ($register === '' || $schema === '') {
                return [];
            }

            $voorstel = $objectService->findObject($register, $schema, $voorstelId);
            $array    = [];
            if (is_array($voorstel) === true) {
                $array = $voorstel;
            } else if (is_object($voorstel) === true) {
                if (method_exists($voorstel, 'jsonSerialize') === true) {
                    $serialized = $voorstel->jsonSerialize();
                    if (is_array($serialized) === true) {
                        $array = $serialized;
                    }
                } else if (method_exists($voorstel, 'toArray') === true) {
                    $arr = $voorstel->toArray();
                    if (is_array($arr) === true) {
                        $array = $arr;
                    }
                } else {
                    $array = (array) $voorstel;
                }
            }

            return $this->auditTrailService->buildContentSnapshot($array);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: failed to load voorstel for audit snapshot',
                [
                    'voorstel'  => $voorstelId,
                    'exception' => $e->getMessage(),
                ],
            );

            return [];
        }//end try
    }//end fetchContentSnapshot()
}//end class
