<?php

/**
 * Parafering Audit Append-Only Validator
 *
 * Hooks into OpenRegister's pre-save and pre-delete event pipeline and rejects
 * any UPDATE or DELETE on the paraferingAuditEntry schema. INSERTs are
 * validated for enum + hash shape. Application services CANNOT bypass this
 * because OR dispatches the *ing events from the generic ObjectService write
 * path that every audit row passes through.
 *
 * @category Validator
 * @package  OCA\Procest\Validator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parafering-audit-trail/tasks.md#T05
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Validator;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Service\Parafering\AuditTrailService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validator listener that enforces append-only semantics on paraferingAuditEntry.
 *
 * @implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent>
 */
class ParaferingAuditAppendOnlyValidator implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AuditTrailService $auditTrailService The audit-trail service (assertAppendOnly lives there)
     * @param SettingsService   $settingsService   Provides the audit-entry schema id
     * @param LoggerInterface   $logger            PSR-3 logger
     */
    public function __construct(
        private readonly AuditTrailService $auditTrailService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle pre-save / pre-delete events on objects.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        try {
            $auditSchema = $this->settingsService->getConfigValue('parafering_audit_entry_schema');
            if ($auditSchema === '') {
                return;
            }

            if ($event instanceof ObjectCreatingEvent === true) {
                $object = $event->getObject();
                if ((string) $object->getSchema() !== $auditSchema) {
                    return;
                }

                $payload = $object->getObject();
                if (is_array($payload) === false) {
                    $payload = [];
                }

                $this->auditTrailService->assertAppendOnly($payload, false);
                return;
            }

            if ($event instanceof ObjectUpdatingEvent === true) {
                $object = $event->getNewObject();
                if ((string) $object->getSchema() !== $auditSchema) {
                    return;
                }

                // Any UPDATE on an audit entry is forbidden.
                $payload = $object->getObject();
                if (is_array($payload) === false) {
                    $payload = [];
                }

                $this->auditTrailService->assertAppendOnly($payload, true);
                return;
            }

            if ($event instanceof ObjectDeletingEvent === true) {
                $object = $event->getObject();
                if ((string) $object->getSchema() !== $auditSchema) {
                    return;
                }

                $payload = $object->getObject();
                if (is_array($payload) === false) {
                    $payload = [];
                }

                $this->auditTrailService->assertAppendOnly($payload, true);
                return;
            }
        } catch (OCSForbiddenException $e) {
            // Block the operation by stopping propagation and recording the error.
            if ($event instanceof ObjectCreatingEvent === true
                || $event instanceof ObjectUpdatingEvent === true
                || $event instanceof ObjectDeletingEvent === true
            ) {
                $event->setErrors([$e->getMessage()]);
                $event->stopPropagation();
            }

            $this->logger->warning(
                'Procest: paraferingAuditEntry append-only violation',
                ['message' => $e->getMessage()],
            );

            // Re-throw so the OCS framework surfaces 403 to the API caller.
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: ParaferingAuditAppendOnlyValidator failed',
                ['exception' => $e->getMessage()],
            );
        }//end try
    }//end handle()
}//end class
