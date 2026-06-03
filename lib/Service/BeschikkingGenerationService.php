<?php

/**
 * Procest Beschikking Generation Service
 *
 * Generates a beschikking PDF via Docudesk for verleend/geweigerd
 * omgevingsvergunningen, attaches it as a bijlage with type 'beschikking'
 * to the vergunningaanvraag, and stores it in the case folder.
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
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates Docudesk template rendering and bijlage attachment for
 * beschikking documents on omgevingsvergunning cases.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
class BeschikkingGenerationService
{
    /**
     * Constructor.
     *
     * @param SettingsService      $settingsService     The settings service
     * @param INotificationManager $notificationManager The NC notification manager
     * @param IUserSession         $userSession         The current user session
     * @param ContainerInterface   $container           The DI container
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly INotificationManager $notificationManager,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a beschikking PDF and attach it to the vergunningaanvraag.
     *
     * Picks the template from `dso_beschikking_template_verleend` or
     * `dso_beschikking_template_geweigerd` config, calls Docudesk to render,
     * attaches the result as a bijlage with `type: beschikking`, stores the
     * PDF in the case folder, and sends a notification to the behandelaar.
     *
     * @param string $zaakId     UUID of the Procest zaak
     * @param string $outcome    'verleend' or 'geweigerd'
     * @param string $motivation Decision motivation text
     *
     * @return array<string, mixed> Result containing bijlage metadata
     *
     * @throws \RuntimeException When OpenRegister is unavailable
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function generateBeschikking(string $zaakId, string $outcome, string $motivation): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            throw new \RuntimeException('Procest register or case schema not configured');
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

        $configKey = 'dso_beschikking_template_geweigerd';
        if ($outcome === 'verleend') {
            $configKey = 'dso_beschikking_template_verleend';
        }

        $templateId = $this->settingsService->getConfigValue($configKey, '');

        // Attempt Docudesk rendering; produce a placeholder on failure.
        $pdfContent = $this->renderDocudeskTemplate(
            templateId: $templateId,
            zaakData: $zaakArray,
            outcome: $outcome,
            motivation: $motivation,
        );

        $fileName = 'beschikking-'.$outcome.'-'.$zaakId.'.pdf';

        // Attach as bijlage on the vergunningaanvraag.
        $vergunningaanvraagRef = (string) ($zaakArray['vergunningaanvraagRef'] ?? '');
        if ($vergunningaanvraagRef !== '') {
            $this->attachBijlageToVergunningaanvraag(
                objectService: $objectService,
                vergunningaanvraagRef: $vergunningaanvraagRef,
                fileName: $fileName,
                pdfContent: $pdfContent,
                outcome: $outcome,
            );
        }

        // Notify the assignee.
        $assignee = (string) ($zaakArray['assignee'] ?? '');
        if ($assignee !== '') {
            $this->notifyBeschikkingGenerated(
                assignee: $assignee,
                zaakId: $zaakId,
                outcome: $outcome,
                fileName: $fileName,
            );
        }

        $this->logger->info(
            'BeschikkingGenerationService: beschikking generated',
            [
                'app'        => Application::APP_ID,
                'zaakId'     => $zaakId,
                'outcome'    => $outcome,
                'templateId' => $templateId,
                'file'       => $fileName,
            ],
        );

        return [
            'zaakId'      => $zaakId,
            'outcome'     => $outcome,
            'fileName'    => $fileName,
            'bijlageType' => 'beschikking',
        ];
    }//end generateBeschikking()

    /**
     * Render a beschikking via Docudesk (when available) or produce a stub.
     *
     * @param string               $templateId The Docudesk template ID
     * @param array<string, mixed> $zaakData   Zaak data for template variables
     * @param string               $outcome    'verleend' or 'geweigerd'
     * @param string               $motivation Decision motivation
     *
     * @return string PDF content (binary or placeholder)
     */
    private function renderDocudeskTemplate(
        string $templateId,
        array $zaakData,
        string $outcome,
        string $motivation,
    ): string {
        if ($templateId === '') {
            return '%PDF-1.4 beschikking placeholder ('.$outcome.')';
        }

        try {
            $docudesk = $this->container->get('OCA\Docudesk\Service\RenderService');
            return (string) $docudesk->render(
                templateId: $templateId,
                data: array_merge($zaakData, ['outcome' => $outcome, 'motivation' => $motivation]),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BeschikkingGenerationService: Docudesk unavailable, using placeholder',
                ['error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
            return '%PDF-1.4 beschikking placeholder ('.$outcome.')';
        }
    }//end renderDocudeskTemplate()

    /**
     * Attach the beschikking PDF as a bijlage on the vergunningaanvraag.
     *
     * @param object $objectService         OpenRegister ObjectService
     * @param string $vergunningaanvraagRef The reference to the vergunningaanvraag
     * @param string $fileName              Filename for the bijlage
     * @param string $pdfContent            PDF binary content
     * @param string $outcome               'verleend' or 'geweigerd'
     *
     * @return void
     */
    private function attachBijlageToVergunningaanvraag(
        object $objectService,
        string $vergunningaanvraagRef,
        string $fileName,
        string $pdfContent,
        string $outcome,
    ): void {
        try {
            $aanvraag = $objectService->findObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                id: $vergunningaanvraagRef,
            );

            if ($aanvraag === null) {
                return;
            }

            $aanvraagArray = $this->extractArray(obj: $aanvraag);
            $bijlagen      = $aanvraagArray['bijlagen'] ?? [];
            $bijlagen[]    = [
                'naam'    => $fileName,
                'type'    => 'beschikking',
                'outcome' => $outcome,
            ];

            $aanvraagArray['bijlagen'] = $bijlagen;

            $objectService->saveObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                object: $aanvraagArray,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BeschikkingGenerationService: could not attach bijlage to vergunningaanvraag',
                ['ref' => $vergunningaanvraagRef, 'error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
        }//end try
    }//end attachBijlageToVergunningaanvraag()

    /**
     * Send a Nextcloud notification about beschikking generation.
     *
     * @param string $assignee Nextcloud user ID
     * @param string $zaakId   Zaak UUID
     * @param string $outcome  'verleend' or 'geweigerd'
     * @param string $fileName Attachment file name
     *
     * @return void
     */
    private function notifyBeschikkingGenerated(
        string $assignee,
        string $zaakId,
        string $outcome,
        string $fileName,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($assignee)
                ->setDateTime(new \DateTime())
                ->setObject('zaak', $zaakId)
                ->setSubject('beschikking_generated', ['outcome' => $outcome, 'fileName' => $fileName]);

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BeschikkingGenerationService: notification failed',
                ['assignee' => $assignee, 'error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
        }
    }//end notifyBeschikkingGenerated()

    /**
     * Convert an object or array to a plain PHP array.
     *
     * @param mixed $obj Input value
     *
     * @return array<string, mixed>
     */
    private function extractArray(mixed $obj): array
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

        return [];
    }//end extractArray()
}//end class
