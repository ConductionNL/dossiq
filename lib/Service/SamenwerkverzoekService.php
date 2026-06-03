<?php

/**
 * Procest Samenwerkverzoek Service
 *
 * Manages samenwerkverzoek lifecycle: initiating, accepting, and rejecting
 * coordination requests between bevoegde gezagen for DSO vergunningaanvragen.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for managing samenwerkverzoek objects in OpenRegister.
 *
 * Creates samenwerkverzoeken on behalf of the initiating bevoegd gezag and
 * processes accept/reject responses from the aangezochte bevoegd gezag.
 */
class SamenwerkverzoekService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a samenwerkverzoek for a DSO zaak.
     *
     * Creates a samenwerkverzoek object in OpenRegister, links it to the
     * Procest zaak's samenwerkverzoeken array, and returns the created object.
     *
     * @param string $zaakId                 UUID of the Procest zaak
     * @param string $aangezochtBevoegdGezag OIN or name of the receiving authority
     * @param string $rationale              Reason for the samenwerking request
     *
     * @return array<string, mixed> The created samenwerkverzoek object
     *
     * @throws \RuntimeException When OpenRegister is unavailable
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function initiateSamenwerking(
        string $zaakId,
        string $aangezochtBevoegdGezag,
        string $rationale,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available.');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        $swSchema   = $this->settingsService->getConfigValue(key: 'dso_samenwerkverzoek_schema');

        // Load zaak to get vergunningaanvraagRef and initiatorBevoegdGezag.
        $zaak = $objectService->getObject(
            register: $register,
            schema: $caseSchema,
            id: $zaakId
        );
        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaak = $zaak->jsonSerialize();
        }

        if (is_array($zaak) === false) {
            throw new \RuntimeException("Zaak {$zaakId} not found.");
        }

        $vergunningRef         = (string) ($zaak['vergunningaanvraagRef'] ?? '');
        $initiatorBevoegdGezag = (string) ($zaak['bevoegdGezag'] ?? 'Gemeente');

        // Create samenwerkverzoek.
        $swData = [
            'initiatorBevoegdGezag'  => $initiatorBevoegdGezag,
            'aangezochtBevoegdGezag' => $aangezochtBevoegdGezag,
            'vergunningaanvraagRef'  => $vergunningRef,
            'rationale'              => $rationale,
            'status'                 => 'aangevraagd',
            'aangevraagdOp'          => date('c'),
        ];

        if ($swSchema !== '') {
            $resolvedSchema = $swSchema;
        } else {
            $resolvedSchema = 'samenwerkverzoek';
        }

        $swObj = $objectService->saveObject(
            register: $register,
            schema: $resolvedSchema,
            object: $swData
        );
        if (is_object($swObj) === true && method_exists($swObj, 'jsonSerialize') === true) {
            $swArr = $swObj->jsonSerialize();
        } else {
            $swArr = (array) $swObj;
        }

        $swId = (string) ($swArr['id'] ?? ($swArr['uuid'] ?? ''));

        // Link samenwerkverzoek to zaak.
        if ($swId !== '') {
            $rawExisting = $zaak['samenwerkverzoeken'] ?? null;
            if (is_array($rawExisting) === true) {
                $existing = $rawExisting;
            } else {
                $existing = [];
            }

            $existing[] = $swId;
            $zaak['samenwerkverzoeken'] = $existing;
            $objectService->saveObject(
                register: $register,
                schema: $caseSchema,
                object: $zaak
            );
        }

        $this->logger->info(
            'SamenwerkverzoekService: samenwerkverzoek created for zaak '.$zaakId,
            ['app' => Application::APP_ID, 'swId' => $swId],
        );

        return $swArr;
    }//end initiateSamenwerking()

    /**
     * Accept or reject a samenwerkverzoek.
     *
     * Updates the samenwerkverzoek status to 'geaccepteerd' or 'geweigerd'
     * and stores the optional advies on the object.
     *
     * @param string      $samenwerkId UUID of the samenwerkverzoek
     * @param bool        $accept      True to accept, false to reject
     * @param string|null $advies      Advice text (for accepted requests)
     *
     * @return array<string, mixed> Updated samenwerkverzoek object
     *
     * @throws \RuntimeException When OpenRegister is unavailable
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
            throw new \RuntimeException('OpenRegister is not available.');
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $swSchema = $this->settingsService->getConfigValue(key: 'dso_samenwerkverzoek_schema');
        if ($swSchema !== '') {
            $schema = $swSchema;
        } else {
            $schema = 'samenwerkverzoek';
        }

        $sw = $objectService->getObject(
            register: $register,
            schema: $schema,
            id: $samenwerkId
        );
        if (is_object($sw) === true && method_exists($sw, 'jsonSerialize') === true) {
            $sw = $sw->jsonSerialize();
        }

        if (is_array($sw) === false) {
            throw new \RuntimeException("Samenwerkverzoek {$samenwerkId} not found.");
        }

        if ($accept === true) {
            $sw['status'] = 'geaccepteerd';
        } else {
            $sw['status'] = 'geweigerd';
        }

        $sw['gereageerdOp'] = date('c');

        if ($advies !== null) {
            $sw['advies'] = $advies;
        }

        $updated = $objectService->saveObject(
            register: $register,
            schema: $schema,
            object: $sw
        );

        if ($accept === true) {
            $action = 'accepted';
        } else {
            $action = 'rejected';
        }

        $this->logger->info(
            'SamenwerkverzoekService: samenwerkverzoek '.$samenwerkId.' '.$action,
            ['app' => Application::APP_ID],
        );

        if (is_object($updated) === true && method_exists($updated, 'jsonSerialize') === true) {
            return $updated->jsonSerialize();
        }

        return (array) $updated;
    }//end respondToSamenwerking()
}//end class
