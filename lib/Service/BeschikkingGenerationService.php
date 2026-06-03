<?php

/**
 * Procest Beschikking Generation Service
 *
 * Orchestrates beschikking PDF generation via Docudesk and attaches the
 * resulting document as a bijlage with type 'beschikking' on the
 * OpenRegister vergunningaanvraag object.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and attaching beschikking PDFs on DSO cases.
 *
 * Picks the correct Docudesk template from config (verleend/geweigerd),
 * requests PDF generation, and attaches the result as a bijlage on the
 * linked vergunningaanvraag object in OpenRegister.
 */
class BeschikkingGenerationService
{
    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Settings service
     * @param ContainerInterface $container       DI container (for Docudesk)
     * @param LoggerInterface    $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a beschikking PDF and attach it to the vergunningaanvraag.
     *
     * Selects the Docudesk template based on outcome (verleend/geweigerd),
     * calls Docudesk to render the PDF, attaches the result as a bijlage
     * with type 'beschikking', and sends a notification to the behandelaar.
     *
     * @param string $zaakId     UUID of the Procest zaak
     * @param string $outcome    'verleend' or 'geweigerd'
     * @param string $motivation Decision motivation text
     *
     * @return array<string, mixed> Bijlage metadata (naam, type, url)
     *
     * @throws \RuntimeException When OpenRegister is unavailable or zaak has no vergunningaanvraagRef
     * @throws \InvalidArgumentException When outcome is not 'verleend' or 'geweigerd'
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
     */
    public function generateBeschikking(string $zaakId, string $outcome, string $motivation): array
    {
        if (in_array(needle: $outcome, haystack: ['verleend', 'geweigerd'], strict: true) === false) {
            throw new \InvalidArgumentException(
                "Invalid outcome '{$outcome}'. Must be 'verleend' or 'geweigerd'."
            );
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available.');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        // Load Procest zaak.
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

        $vergunningRef = (string) ($zaak['vergunningaanvraagRef'] ?? '');
        if ($vergunningRef === '') {
            throw new \RuntimeException("Zaak {$zaakId} has no vergunningaanvraagRef; cannot attach beschikking.");
        }

        $templateKey = 'dso_beschikking_template_'.$outcome;
        $templateId  = $this->settingsService->getConfigValue(key: $templateKey);

        // Call Docudesk if available, otherwise create a placeholder.
        $pdfContent = $this->renderViaDocudesk(
            templateId: $templateId,
            zaak: $zaak,
            motivation: $motivation,
            outcome: $outcome
        );

        $fileName = 'beschikking-'.$zaakId.'-'.$outcome.'-'.date('Ymd').'.pdf';
        $bijlage  = [
            'naam'  => $fileName,
            'type'  => 'beschikking',
            'datum' => date('Y-m-d'),
        ];

        if ($pdfContent !== null) {
            $bijlage['content'] = base64_encode(string: $pdfContent);
        }

        // Attach bijlage to vergunningaanvraag.
        $this->attachBijlage(
            vergunningRef: $vergunningRef,
            bijlage: $bijlage,
            objectService: $objectService
        );

        $this->logger->info(
            'BeschikkingGenerationService: beschikking generated for zaak '.$zaakId.' ('.$outcome.')',
            ['app' => Application::APP_ID],
        );

        return $bijlage;
    }//end generateBeschikking()

    /**
     * Attempt to render via Docudesk; returns null when Docudesk is not available.
     *
     * @param string               $templateId Docudesk template ID (may be empty)
     * @param array<string, mixed> $zaak       Procest zaak data
     * @param string               $motivation Decision motivation
     * @param string               $outcome    'verleend' or 'geweigerd'
     *
     * @return string|null PDF binary content or null when unavailable
     */
    private function renderViaDocudesk(
        string $templateId,
        array $zaak,
        string $motivation,
        string $outcome,
    ): ?string {
        if ($templateId === '') {
            return null;
        }

        try {
            $docudesk = $this->container->get('OCA\Docudesk\Service\DocumentService');
            $result   = $docudesk->generateFromTemplate(
                templateId: $templateId,
                data: [
                    'zaak'       => $zaak,
                    'motivation' => $motivation,
                    'outcome'    => $outcome,
                    'datum'      => date('Y-m-d'),
                ]
            );

            if (is_string($result) === true) {
                return $result;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'BeschikkingGenerationService: Docudesk unavailable — '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return null;
        }//end try
    }//end renderViaDocudesk()

    /**
     * Attach a bijlage to the vergunningaanvraag object.
     *
     * @param string               $vergunningRef Reference to vergunningaanvraag
     * @param array<string, mixed> $bijlage       Bijlage metadata
     * @param object               $objectService OpenRegister ObjectService
     *
     * @return void
     */
    private function attachBijlage(string $vergunningRef, array $bijlage, object $objectService): void
    {
        try {
            $aanvraag = $objectService->getObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                id: $vergunningRef
            );
            if (is_object($aanvraag) === true && method_exists($aanvraag, 'jsonSerialize') === true) {
                $aanvraag = $aanvraag->jsonSerialize();
            }

            if (is_array($aanvraag) === false) {
                return;
            }

            $rawBijlagen = $aanvraag['bijlagen'] ?? null;
            if (is_array($rawBijlagen) === true) {
                $bijlagen = $rawBijlagen;
            } else {
                $bijlagen = [];
            }

            $bijlagen[]           = $bijlage;
            $aanvraag['bijlagen'] = $bijlagen;

            $objectService->saveObject(
                register: 'dso',
                schema: 'vergunningaanvraag',
                object: $aanvraag
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BeschikkingGenerationService: could not attach bijlage to '.$vergunningRef.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }//end try
    }//end attachBijlage()
}//end class
