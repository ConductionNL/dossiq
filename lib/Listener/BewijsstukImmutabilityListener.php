<?php

/**
 * Procest Bewijsstuk Immutability Listener.
 *
 * Enforces the REQ-SUB-007 rule that a bewijsstuk becomes immutable once it is
 * linked to a vaststelling. `BewijsstukService::assertMutable()` implemented
 * that rule and was unit-tested, but had ZERO production callers — an
 * authorization check that is never invoked is identical to having no check at
 * all (OWASP A01:2021). This listener is the call site.
 *
 * It hooks OpenRegister's PRE-persist `ObjectUpdatingEvent` /
 * `ObjectDeletingEvent` pair, both of which implement
 * `StoppableEventInterface`: `stopPropagation()` makes MagicMapper raise
 * `HookStoppedException` BEFORE the row is written or removed. The
 * post-persist `ObjectUpdatedEvent`/`ObjectDeletedEvent` pair is deliberately
 * NOT used — by the time those fire the mutation has already landed in the
 * database, so a listener there cannot prevent anything (the same reasoning
 * `LocationBagValidationListener` records).
 *
 * The check always reads the STORED state (`getOldObject()` on update, the
 * entity itself on delete), never the incoming payload. Reading the payload
 * would let a caller clear `immutable` in the same request that mutates the
 * document and walk straight through the guard.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\BewijsstukService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reject mutation/deletion of a bewijsstuk linked to a vaststelling.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */
class BewijsstukImmutabilityListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SettingsService   $settingsService   Schema slug bridge.
     * @param BewijsstukService $bewijsstukService Owns the REQ-SUB-007
     *                                             immutability rule.
     * @param LoggerInterface   $logger            Structured logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly BewijsstukService $bewijsstukService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Inspect a pre-persist bewijsstuk mutation and reject it when frozen.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/specs/subsidieverlening-keten/spec.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectUpdatingEvent === true) {
            // The STORED state decides, not the incoming payload.
            $this->inspect(event: $event, stored: $event->getOldObject());
            return;
        }

        if ($event instanceof ObjectDeletingEvent === true) {
            $this->inspect(event: $event, stored: $event->getObject());
            return;
        }
    }//end handle()

    /**
     * Apply `BewijsstukService::assertMutable()` to the stored state and stop
     * the save when it rejects.
     *
     * @param ObjectUpdatingEvent|ObjectDeletingEvent $event  The pre-persist,
     *                                                        stoppable event.
     * @param ObjectEntity|null                       $stored The state
     *                                                        currently in the
     *                                                        database.
     *
     * @return void
     */
    private function inspect(ObjectUpdatingEvent|ObjectDeletingEvent $event, ?ObjectEntity $stored): void
    {
        if ($stored === null) {
            return;
        }

        try {
            $payload = $stored->jsonSerialize();
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest: bewijsstuk immutability listener could not read the stored payload: '.$e->getMessage()
            );
            return;
        }

        if (is_array($payload) === false || $this->isBewijsstukSchema(object: $payload) === false) {
            return;
        }

        try {
            $this->bewijsstukService->assertMutable(bewijsstuk: $payload);
        } catch (OCSBadRequestException $rejection) {
            $event->setErrors(
                [
                    'message' => $rejection->getMessage(),
                    'code'    => 'bewijsstuk.immutable',
                ]
            );
            $event->stopPropagation();
            $this->logger->info(
                'Procest: rejected a mutation on an immutable bewijsstuk (REQ-SUB-007)',
                ['uuid' => (string) $stored->getUuid()]
            );
        }
    }//end inspect()

    /**
     * Whether the supplied payload belongs to the `bewijsstuk` schema.
     *
     * @param array<string, mixed> $object Object payload (incl. `@self`).
     *
     * @return bool True when this is a bewijsstuk.
     */
    private function isBewijsstukSchema(array $object): bool
    {
        $expected = $this->settingsService->getConfigValue('bewijsstuk_schema');
        if ($expected === '') {
            return false;
        }

        $candidate = (string) ($object['@self']['schema'] ?? ($object['schema'] ?? ''));

        return $candidate !== '' && (
            $candidate === $expected
            || str_ends_with($candidate, '/'.$expected)
        );
    }//end isBewijsstukSchema()
}//end class
