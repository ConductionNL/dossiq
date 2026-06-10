<?php

/**
 * Status Change Dispatcher Listener
 *
 * Consumes Procest's internal VergunningStatusChangedEvent and dispatches
 * a normalized cross-app notification payload back to the source DSO
 * vergunningaanvraag object in OpenRegister. For terminal "Verleend" or
 * "Geweigerd" transitions, attaches the beschikking URL so the citizen
 * portal / OpenConnector can fetch the formal decision document.
 *
 * The listener decouples Procest's case-management lifecycle from the
 * outbound DSO bus: status changes flow event -> listener -> OpenRegister
 * payload update, with OpenConnector subsequently propagating to LV-DSO.
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/vth-workflow-configuration-08-dso-integration/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Event\VergunningStatusChangedEvent;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Status-change dispatcher for vergunningaanvraag DSO sync.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/vth-workflow-configuration-08-dso-integration/tasks.md#2
 */
class StatusChangeDispatcherListener implements IEventListener
{
    private const TERMINAL_STATUSES = ['verleend', 'geweigerd'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings bridge for OR access
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a dispatched VergunningStatusChangedEvent.
     *
     * @param Event $event The dispatched event (only VergunningStatusChangedEvent is processed)
     *
     * @return void
     *
     * @spec openspec/changes/vth-workflow-configuration-08-dso-integration/tasks.md#2
     */
    public function handle(Event $event): void
    {
        if (($event instanceof VergunningStatusChangedEvent) === false) {
            return;
        }

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            return;
        }

        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return;
            }

            $register = $this->settingsService->getConfigValue('register');
            // The DSO vergunningaanvraag schema is OpenConnector-managed; if
            // a dedicated config key is not present, the dispatch is logged
            // and skipped (other listeners may still consume the event).
            $schema = $this->settingsService->getConfigValue('vergunningaanvraag_schema');
            if ($register === '' || $schema === '') {
                $this->logger->info(
                    'StatusChangeDispatcherListener: register or vergunningaanvraag_schema not configured; skipping DSO pushback.'
                );
                return;
            }

            $ref       = $event->getVergunningaanvraagRef();
            $newStatus = $event->getNewStatus();

            $payload = [
                'id'             => $ref,
                'status'         => $newStatus,
                'besluitdatum'   => $event->getBesluitdatum(),
                'toelichting'    => $event->getToelichting(),
                'updatedBy'      => $event->getUserId(),
                'updatedAt'      => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];

            if (in_array(strtolower($newStatus), self::TERMINAL_STATUSES, true) === true) {
                $beschikkingUrl = $event->getBeschikkingUrl();
                if ($beschikkingUrl !== null && $beschikkingUrl !== '') {
                    $payload['beschikkingUrl'] = $beschikkingUrl;
                }
            }

            $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $payload,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'StatusChangeDispatcherListener failed: '.$e->getMessage(),
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end handle()
}//end class
