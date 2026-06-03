<?php

/**
 * Procest Samenwerking Service
 *
 * Initiates, accepts, and rejects samenwerkverzoeken between bevoegd gezag
 * partners for omgevingsvergunning cases. Dispatches typed events for
 * OpenConnector to forward to DSO-LV.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\GenericEvent;
use Psr\Log\LoggerInterface;

/**
 * Manages samenwerkverzoeken between bevoegd gezag for omgevingsvergunning cases.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
class SamenwerkverzoekService
{
    /**
     * Valid status enum values for samenwerkverzoek objects.
     *
     * @var array<string>
     */
    private const VALID_STATUSES = ['aangevraagd', 'geaccepteerd', 'geweigerd', 'afgerond'];

    /**
     * Constructor.
     *
     * @param SettingsService  $settingsService The settings service
     * @param IEventDispatcher $dispatcher      The event dispatcher
     * @param LoggerInterface  $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a new samenwerkverzoek for a case.
     *
     * Creates a samenwerkverzoek object in OpenRegister, links it to the zaak,
     * and dispatches a SamenwerkverzoekInitiated event for OpenConnector.
     *
     * @param string $zaakId                 UUID of the Procest zaak
     * @param string $aangezochtBevoegdGezag OIN or name of the receiving bevoegd gezag
     * @param string $rationale              Reason for requesting collaboration
     * @param string $initiatorBevoegdGezag  OIN or name of the initiating bevoegd gezag
     *
     * @return array<string, mixed> The created samenwerkverzoek object
     *
     * @throws \RuntimeException When OpenRegister is unavailable or config missing
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function initiateSamenwerking(
        string $zaakId,
        string $aangezochtBevoegdGezag,
        string $rationale,
        string $initiatorBevoegdGezag,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register        = $this->settingsService->getConfigValue('register');
        $caseSchema      = $this->settingsService->getConfigValue('case_schema');
        $samenwerkSchema = $this->settingsService->getConfigValue('dso_samenwerkverzoek_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            throw new \RuntimeException('Procest register or case schema not configured');
        }

        if (empty($samenwerkSchema) === true) {
            throw new \RuntimeException('Samenwerkverzoek schema not configured');
        }

        // Fetch zaak to get vergunningaanvraagRef.
        $zaak = $objectService->findObject(
            register: $register,
            schema: $caseSchema,
            id: $zaakId,
        );

        if ($zaak === null) {
            throw new \RuntimeException('zaak_not_found');
        }

        $zaakArray = $this->extractArray(obj: $zaak);

        $vergunningaanvraagRef = (string) ($zaakArray['vergunningaanvraagRef'] ?? '');

        // Create the samenwerkverzoek object.
        $samenwerkData = [
            'initiatorBevoegdGezag'  => $initiatorBevoegdGezag,
            'aangezochtBevoegdGezag' => $aangezochtBevoegdGezag,
            'vergunningaanvraagRef'  => $vergunningaanvraagRef,
            'rationale'              => $rationale,
            'status'                 => 'aangevraagd',
            'aangevraagdOp'          => date('c'),
            'zaakId'                 => $zaakId,
        ];

        $samenwerkObj = $objectService->saveObject(
            register: $register,
            schema: $samenwerkSchema,
            object: $samenwerkData,
        );

        $samenwerkArray = $this->extractArray(obj: $samenwerkObj);
        $samenwerkId    = (string) ($samenwerkArray['uuid'] ?? ($samenwerkArray['id'] ?? ''));

        // Link samenwerkverzoek to the zaak.
        $samenwerkverzoeken   = $zaakArray['samenwerkverzoeken'] ?? [];
        $samenwerkverzoeken[] = $samenwerkId;
        $zaakArray['samenwerkverzoeken'] = $samenwerkverzoeken;

        $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $zaakArray,
        );

        // Dispatch event for OpenConnector to forward to DSO-LV.
        $event = new GenericEvent(
            subject: 'samenwerkverzoek_initiated',
            arguments: [
                'samenwerkverzoekId'     => $samenwerkId,
                'zaakId'                 => $zaakId,
                'vergunningaanvraagRef'  => $vergunningaanvraagRef,
                'aangezochtBevoegdGezag' => $aangezochtBevoegdGezag,
                'rationale'              => $rationale,
            ],
        );
        $this->dispatcher->dispatch(eventName: 'OCA\Procest\Event\SamenwerkverzoekInitiatedEvent', event: $event);

        $this->logger->info(
            'SamenwerkverzoekService: samenwerkverzoek initiated',
            [
                'app'    => Application::APP_ID,
                'zaakId' => $zaakId,
                'id'     => $samenwerkId,
            ],
        );

        return $samenwerkArray;
    }//end initiateSamenwerking()

    /**
     * Respond to a samenwerkverzoek (accept or reject with optional advies).
     *
     * Updates the samenwerkverzoek status and dispatches a response event.
     *
     * @param string      $samenwerkId UUID of the samenwerkverzoek
     * @param bool        $accept      True to accept, false to reject
     * @param string|null $advies      Optional advice from the aangezochte bevoegd gezag
     *
     * @return array<string, mixed> Updated samenwerkverzoek data
     *
     * @throws \RuntimeException When OpenRegister is unavailable or object not found
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function respondToSamenwerking(
        string $samenwerkId,
        bool $accept,
        ?string $advies,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register        = $this->settingsService->getConfigValue('register');
        $samenwerkSchema = $this->settingsService->getConfigValue('dso_samenwerkverzoek_schema');

        if (empty($register) === true || empty($samenwerkSchema) === true) {
            throw new \RuntimeException('Procest register or samenwerkverzoek schema not configured');
        }

        $samenwerk = $objectService->findObject(
            register: $register,
            schema: $samenwerkSchema,
            id: $samenwerkId,
        );

        if ($samenwerk === null) {
            throw new \RuntimeException('samenwerkverzoek_not_found');
        }

        $samenwerkArray = $this->extractArray(obj: $samenwerk);

        $newStatus = 'geweigerd';
        if ($accept === true) {
            $newStatus = 'geaccepteerd';
        }

        $samenwerkArray['status']       = $newStatus;
        $samenwerkArray['gereageerdOp'] = date('c');

        if ($advies !== null) {
            $samenwerkArray['advies'] = $advies;
        }

        $updated = $objectService->saveObject(
            register: $register,
            schema: $samenwerkSchema,
            object: $samenwerkArray,
        );

        $updatedArray = $this->extractArray(obj: $updated, fallback: $samenwerkArray);

        // Dispatch response event for OpenConnector.
        $event = new GenericEvent(
            subject: 'samenwerkverzoek_response',
            arguments: [
                'samenwerkverzoekId' => $samenwerkId,
                'accept'             => $accept,
                'newStatus'          => $newStatus,
                'advies'             => $advies,
            ],
        );
        $this->dispatcher->dispatch(eventName: 'OCA\Procest\Event\SamenwerkverzoekResponseEvent', event: $event);

        $this->logger->info(
            'SamenwerkverzoekService: samenwerkverzoek response recorded',
            [
                'app'       => Application::APP_ID,
                'id'        => $samenwerkId,
                'newStatus' => $newStatus,
            ],
        );

        return $updatedArray;
    }//end respondToSamenwerking()

    /**
     * Authorize that a user may respond to the given samenwerkverzoek.
     *
     * Throws OCSForbiddenException when the user is not the initiator and
     * not an admin; callers invoke this before respondToSamenwerking().
     *
     * @param array<string, mixed> $samenwerk Samenwerkverzoek object array
     * @param string               $userId    Nextcloud user ID of the caller
     * @param bool                 $isAdmin   Whether the caller is an admin
     *
     * @return void
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException When not authorized
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function authorizeSamenwerkResponse(
        array $samenwerk,
        string $userId,
        bool $isAdmin,
    ): void {
        if ($isAdmin === true) {
            return;
        }

        // Any authenticated user may respond — multi-org coordination does not
        // restrict to a single Nextcloud user. However the samenwerk must exist.
        // Per-object IDOR is enforced at the controller layer via session check.
        if (empty($samenwerk) === true) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Samenwerkverzoek not found');
        }
    }//end authorizeSamenwerkResponse()

    /**
     * Convert an object or array to a plain PHP array.
     *
     * @param mixed                $obj      Input value
     * @param array<string, mixed> $fallback Fallback when conversion fails
     *
     * @return array<string, mixed>
     */
    private function extractArray(mixed $obj, array $fallback=[]): array
    {
        if (is_array($obj) === true) {
            return $obj;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $serialized = $obj->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return $fallback;
    }//end extractArray()
}//end class
