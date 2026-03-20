<?php

/**
 * StUF Notification Service
 *
 * Sends outbound StUF-ZKN notifications to legacy systems when case
 * events occur (creation, status change, document upload, closure).
 *
 * @category Service
 * @package  OCA\Procest\Service\StUF
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\StUF;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends StUF-ZKN notifications to configured legacy system endpoints.
 *
 * Supports case creation (zakLk01 mutatiesoort=T), case update
 * (zakLk01 mutatiesoort=W), and document linking (edcLk01)
 * notifications. Notification delivery failures are logged but
 * do not block the originating operation.
 */
class StufNotificationService
{

    /**
     * The HTTP client for SOAP calls.
     *
     * @var Client
     */
    private Client $httpClient;

    /**
     * The OpenRegister ObjectService (loaded dynamically).
     *
     * @var object|null
     */
    private $objectService = null;


    /**
     * Constructor.
     *
     * @param StufMessageBuilder $messageBuilder The message builder.
     * @param LoggerInterface    $logger         The logger.
     */
    public function __construct(
        private readonly StufMessageBuilder $messageBuilder,
        private readonly LoggerInterface $logger,
    ) {
        $this->httpClient = new Client(['timeout' => 10]);
        $this->loadOpenRegisterServices();
    }//end __construct()


    /**
     * Send a case creation notification via StUF-ZKN zakLk01.
     *
     * @param array<string, mixed> $caseData      The case data in Procest format.
     * @param array<string, mixed> $stuurgegevens The stuurgegevens configuration.
     * @param string               $endpointUrl   The StUF endpoint URL.
     *
     * @return bool True if notification was sent successfully.
     */
    public function notifyCaseCreation(
        array $caseData,
        array $stuurgegevens,
        string $endpointUrl,
    ): bool {
        $xml = $this->messageBuilder->buildZakLk01($caseData, 'T', $stuurgegevens);
        return $this->sendSoapMessage($xml, $endpointUrl, 'case creation');
    }//end notifyCaseCreation()


    /**
     * Send a case status update notification via StUF-ZKN zakLk01.
     *
     * @param array<string, mixed> $caseData      The case data in Procest format.
     * @param array<string, mixed> $stuurgegevens The stuurgegevens configuration.
     * @param string               $endpointUrl   The StUF endpoint URL.
     *
     * @return bool True if notification was sent successfully.
     */
    public function notifyCaseStatusUpdate(
        array $caseData,
        array $stuurgegevens,
        string $endpointUrl,
    ): bool {
        $xml = $this->messageBuilder->buildZakLk01($caseData, 'W', $stuurgegevens);
        return $this->sendSoapMessage($xml, $endpointUrl, 'case status update');
    }//end notifyCaseStatusUpdate()


    /**
     * Send a document notification via StUF-ZKN edcLk01.
     *
     * @param array<string, mixed> $documentData   The document metadata.
     * @param string               $zaakIdentifier The related case identifier.
     * @param array<string, mixed> $stuurgegevens  The stuurgegevens configuration.
     * @param string               $endpointUrl    The StUF endpoint URL.
     * @param string               $mutatiesoort   The mutation type (T=create, W=update).
     *
     * @return bool True if notification was sent successfully.
     */
    public function notifyDocumentChange(
        array $documentData,
        string $zaakIdentifier,
        array $stuurgegevens,
        string $endpointUrl,
        string $mutatiesoort = 'T',
    ): bool {
        $xml = $this->messageBuilder->buildEdcLk01(
            $documentData,
            $mutatiesoort,
            $zaakIdentifier,
            $stuurgegevens,
        );
        return $this->sendSoapMessage($xml, $endpointUrl, 'document change');
    }//end notifyDocumentChange()


    /**
     * Get the StUF notification endpoint for a case type.
     *
     * Looks up the case type configuration to find the StUF notification
     * endpoint and stuurgegevens.
     *
     * @param string $caseTypeId The case type identifier.
     *
     * @return array{endpoint: string|null, stuurgegevens: array<string, mixed>}
     */
    public function getNotificationConfig(string $caseTypeId): array
    {
        $config = [
            'endpoint'      => null,
            'stuurgegevens' => [],
        ];

        if ($this->objectService === null) {
            return $config;
        }

        try {
            $settingsService = \OC::$server->get(
                'OCA\Procest\Service\SettingsService',
            );
            $settings = $settingsService->getSettings();

            $stufEndpoint = ($settings['stuf_notification_endpoint'] ?? null);
            if ($stufEndpoint === null || $stufEndpoint === '') {
                return $config;
            }

            $config['endpoint'] = $stufEndpoint;
            $config['stuurgegevens'] = [
                'zender' => [
                    'organisatie' => ($settings['stuf_zender_organisatie'] ?? ''),
                    'applicatie'  => ($settings['stuf_zender_applicatie'] ?? 'Procest'),
                ],
                'ontvanger' => [
                    'organisatie' => ($settings['stuf_ontvanger_organisatie'] ?? ''),
                    'applicatie'  => ($settings['stuf_ontvanger_applicatie'] ?? ''),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StufNotificationService: Failed to load notification config',
                ['exception' => $e->getMessage()],
            );
        }

        return $config;
    }//end getNotificationConfig()


    /**
     * Send a SOAP message to an endpoint.
     *
     * @param string $xml         The SOAP XML content.
     * @param string $endpointUrl The target URL.
     * @param string $context     Description of the notification for logging.
     *
     * @return bool True if sent successfully.
     */
    private function sendSoapMessage(
        string $xml,
        string $endpointUrl,
        string $context,
    ): bool {
        try {
            $response = $this->httpClient->post($endpointUrl, [
                'body'    => $xml,
                'headers' => [
                    'Content-Type' => 'text/xml; charset=UTF-8',
                    'SOAPAction'   => '',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info(
                    "StUF notification ({$context}) sent successfully to {$endpointUrl}",
                );
                return true;
            }

            $this->logger->error(
                "StUF notification ({$context}) failed with HTTP {$statusCode}",
                ['endpoint' => $endpointUrl],
            );
            return false;
        } catch (GuzzleException $e) {
            $this->logger->error(
                "StUF notification ({$context}) failed: {$e->getMessage()}",
                ['endpoint' => $endpointUrl, 'exception' => $e->getMessage()],
            );
            return false;
        }
    }//end sendSoapMessage()


    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $container = \OC::$server;
            $this->objectService = $container->get(
                'OCA\OpenRegister\Service\ObjectService',
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StufNotificationService: OpenRegister not available',
                ['exception' => $e->getMessage()],
            );
        }
    }//end loadOpenRegisterServices()


}//end class
