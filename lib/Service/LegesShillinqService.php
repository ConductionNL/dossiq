<?php

/**
 * Procest Leges Shillinq Service
 *
 * Thin client around the shillinq accounts-receivable API for leges invoicing:
 * creates invoices from a legesBerekening, creates credit invoices for refunds,
 * and reads payment status. The shillinq base URL and enable toggle come from
 * app config; no secret is ever returned to a caller and the BSN is masked in
 * all log output (special-category data, NEN 7510 / AVG).
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-005
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Client for shillinq accounts-receivable leges invoicing.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LegesShillinqService
{
    /**
     * Default payment term in days when not configured.
     */
    private const DEFAULT_TERM_DAYS = 14;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings/config access.
     * @param IClientService  $clientService   HTTP client factory.
     * @param IAppManager     $appManager      App availability checks.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IClientService $clientService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether shillinq invoicing is enabled and available.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        if ($this->settingsService->getConfigValue('leges_shillinq_enabled') !== '1') {
            return false;
        }

        return $this->baseUrl() !== '';
    }//end isEnabled()

    /**
     * Create an invoice in shillinq for a calculation.
     *
     * @param array<string, mixed> $calculation The legesBerekening payload.
     * @param array<string, mixed> $debiteur    The debtor {bsn, naam, adres}.
     * @param array<string, mixed> $tarief      The tariff (ledger account, cost carrier, VAT).
     *
     * @return string The shillinq invoice id.
     *
     * @throws RuntimeException When shillinq is disabled, misconfigured, or the call fails.
     */
    public function createInvoice(array $calculation, array $debiteur, array $tarief): string
    {
        $this->requireEnabled();

        $payload = [
            'debiteur'          => $this->buildDebiteur(debiteur: $debiteur),
            'factuurregels'     => [
                [
                    'omschrijving'  => (string) ($tarief['omschrijving'] ?? 'Leges'),
                    'bedrag'        => (int) ($calculation['bedragExclBtw'] ?? 0),
                    'btwPercentage' => (int) ($tarief['btwTarief'] ?? 0),
                ],
            ],
            'grootboekrekening' => (string) ($tarief['grootboekrekening'] ?? ''),
            'kostendrager'      => (string) ($tarief['kostendrager'] ?? ''),
            'betalingstermijn'  => $this->termDays(),
            'reference'         => (string) ($calculation['zaakId'] ?? ''),
        ];

        $response  = $this->post(path: '/api/invoices', payload: $payload);
        $factuurId = (string) ($response['factuurId'] ?? ($response['id'] ?? ''));
        if ($factuurId === '') {
            throw new RuntimeException('Shillinq did not return a factuurId');
        }

        $this->logger->info(
            'Procest leges: shillinq invoice created',
            ['factuurId' => $factuurId, 'zaakId' => (string) ($calculation['zaakId'] ?? '')]
        );

        return $factuurId;
    }//end createInvoice()

    /**
     * Create a credit invoice in shillinq for a refund.
     *
     * @param array<string, mixed> $restitutie        The legesRestitutie payload.
     * @param string               $originalFactuurId The original invoice id.
     *
     * @return string The credit invoice id.
     *
     * @throws RuntimeException When shillinq is disabled/misconfigured or the call fails.
     */
    public function createCreditInvoice(array $restitutie, string $originalFactuurId): string
    {
        $this->requireEnabled();

        $payload = [
            'origineleFactuurId' => $originalFactuurId,
            'bedrag'             => (int) ($restitutie['restitutieBedrag'] ?? 0),
            'reden'              => (string) ($restitutie['restitutieReden'] ?? ''),
            'reference'          => (string) ($restitutie['berekeningId'] ?? ''),
        ];

        $response = $this->post(path: '/api/credit-invoices', payload: $payload);
        $creditId = (string) ($response['creditfactuurId'] ?? ($response['id'] ?? ''));
        if ($creditId === '') {
            throw new RuntimeException('Shillinq did not return a creditfactuurId');
        }

        return $creditId;
    }//end createCreditInvoice()

    /**
     * Read the payment status of an invoice from shillinq.
     *
     * @param string $factuurId The invoice id.
     *
     * @return array<string, mixed> The status payload (e.g. {status, betaaldOp}).
     */
    public function syncPaymentStatus(string $factuurId): array
    {
        if ($this->isEnabled() === false || $factuurId === '') {
            return [];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                $this->baseUrl().'/api/invoices/'.rawurlencode($factuurId),
                ['headers' => $this->headers()]
            );

            return (json_decode((string) $response->getBody(), true) ?? []);
        } catch (\Throwable $e) {
            $this->logger->warning('Procest leges: shillinq status sync failed: '.$e->getMessage());
            return [];
        }
    }//end syncPaymentStatus()

    /**
     * Build the debtor block, never logging the raw BSN.
     *
     * @param array<string, mixed> $debiteur The debtor input.
     *
     * @return array<string, mixed>
     */
    private function buildDebiteur(array $debiteur): array
    {
        return [
            'bsn'   => (string) ($debiteur['bsn'] ?? ''),
            'naam'  => (string) ($debiteur['naam'] ?? ''),
            'adres' => (string) ($debiteur['adres'] ?? ''),
        ];
    }//end buildDebiteur()

    /**
     * POST a payload to shillinq and decode the JSON response.
     *
     * @param string               $path    The API path.
     * @param array<string, mixed> $payload The JSON payload.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the request fails.
     */
    private function post(string $path, array $payload): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->baseUrl().$path,
                [
                    'json'    => $payload,
                    'headers' => $this->headers(),
                ]
            );

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new RuntimeException('Shillinq returned HTTP '.$status);
            }

            return (json_decode((string) $response->getBody(), true) ?? []);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Do not surface the underlying exception message (may contain URLs/secrets).
            $this->logger->error('Procest leges: shillinq request failed: '.$e->getMessage());
            throw new RuntimeException('Shillinq request failed');
        }//end try
    }//end post()

    /**
     * Resolve the shillinq base URL from config.
     *
     * @return string
     */
    private function baseUrl(): string
    {
        return rtrim($this->settingsService->getConfigValue('leges_shillinq_source'), '/');
    }//end baseUrl()

    /**
     * Resolve the configured payment term in days.
     *
     * @return int
     */
    private function termDays(): int
    {
        $configured = (int) $this->settingsService->getConfigValue('leges_betalingstermijn_dagen');
        if ($configured > 0) {
            return $configured;
        }

        return self::DEFAULT_TERM_DAYS;
    }//end termDays()

    /**
     * Standard request headers. Shillinq runs inside the same Nextcloud
     * instance behind app auth; no bearer secret is embedded here.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
            'OCS-APIREQUEST' => 'true',
        ];
    }//end headers()

    /**
     * Ensure shillinq is enabled before a write call.
     *
     * @return void
     *
     * @throws RuntimeException When disabled.
     */
    private function requireEnabled(): void
    {
        if ($this->isEnabled() === false) {
            throw new RuntimeException('Shillinq invoicing is not enabled');
        }

        // Soft availability check; shillinq may be an external service so a
        // missing local app id is not necessarily fatal, only logged.
        if ($this->appManager->isInstalled('shillinq') === false) {
            $this->logger->debug('Procest leges: shillinq app not locally installed; using configured endpoint');
        }
    }//end requireEnabled()
}//end class
