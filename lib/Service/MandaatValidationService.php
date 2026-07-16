<?php

/**
 * Procest Mandaat Validation Service
 *
 * Validates that the official signing a mandaatbesluit has sufficient delegated
 * authority for the decision scope, by querying the configured mandaatregister.
 * When the register is unreachable the service does NOT silently pass — it
 * returns a `requiresManualConfirmation` flag so the workflow guard can prompt
 * the handler to confirm authority manually (logged in the audit trail).
 *
 * The mandaatregister endpoint is read from app config — never hardcoded.
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Mandaatregister authority validator for mandaatbesluiten.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class MandaatValidationService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister + config.
     * @param IClientService  $clientService   Nextcloud HTTP client factory.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate the signing official's mandate for a case.
     *
     * @param string $caseId        The mandaatbesluit case UUID/slug.
     * @param string $signingUserId The UID of the official signing the besluit.
     *
     * @return array<string, mixed> {valid: bool, requiresManualConfirmation: bool, message?: string, registerLink?: string}
     *
     * @spec openspec/specs/besluitvorming-workflow/spec.md
     */
    public function validate(string $caseId, string $signingUserId): array
    {
        $endpoint = $this->settingsService->getConfigValue(key: 'mandaatregister_endpoint');
        if ($endpoint === '' || (str_starts_with($endpoint, 'https://') === false && str_starts_with($endpoint, 'http://') === false)) {
            // No register configured: require manual confirmation, do not pass silently.
            return [
                'valid'                      => false,
                'requiresManualConfirmation' => true,
                'message'                    => 'Mandaatregister is niet geconfigureerd. Bevestig het mandaat handmatig.',
            ];
        }

        $category = $this->resolveMandaatCategory(caseId: $caseId);

        try {
            $headers = ['Accept' => 'application/json'];
            $token   = $this->settingsService->getConfigValue(key: 'mandaatregister_token');
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            }

            $client   = $this->clientService->newClient();
            $url      = rtrim($endpoint, '/').'/mandaten?gebruiker='.rawurlencode($signingUserId).'&categorie='.rawurlencode($category);
            $response = $client->get($url, ['headers' => $headers, 'timeout' => 8]);

            $status = (int) $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $this->unreachable(status: (string) $status);
            }

            $decoded      = json_decode((string) $response->getBody(), true);
            $hasAuthority = is_array($decoded) === true && ($decoded['hasAuthority'] ?? false) === true;

            if ($hasAuthority === true) {
                return ['valid' => true, 'requiresManualConfirmation' => false];
            }

            $message = 'De ondertekenende ambtenaar heeft onvoldoende mandaat voor dit besluit. '
                .'Raadpleeg het mandaatregister.';

            return [
                'valid'                      => false,
                'requiresManualConfirmation' => false,
                'message'                    => $message,
                'registerLink'               => rtrim($endpoint, '/').'/mandaten?categorie='.rawurlencode($category),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: mandaatregister unreachable',
                ['case' => $caseId, 'exception' => $e->getMessage()],
            );
            return $this->unreachable(status: 'connection_error');
        }//end try
    }//end validate()

    /**
     * Build the "unreachable" result that requires manual confirmation.
     *
     * @param string $status The failing status / error code.
     *
     * @return array<string, mixed>
     */
    private function unreachable(string $status): array
    {
        return [
            'valid'                      => false,
            'requiresManualConfirmation' => true,
            'message'                    => 'Het mandaatregister is momenteel niet bereikbaar. Bevestig het mandaat handmatig.',
            'status'                     => $status,
        ];
    }//end unreachable()

    /**
     * Resolve the mandaatCategorie caseProperty value for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return string The mandate category (empty when not set).
     */
    private function resolveMandaatCategory(string $caseId): string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $propertySchema = $this->settingsService->getConfigValue(key: 'case_property_schema');
        if ($register === '' || $propertySchema === '') {
            return '';
        }

        try {
            $results = $objectService->findAll(
                [
                    'filters' => ['register' => $register, 'schema' => $propertySchema, 'case' => $caseId, 'name' => 'mandaatCategorie'],
                    'limit'   => 1,
                ],
            );

            if (is_array($results) === true && isset($results['results']) === true) {
                $results = $results['results'];
            }

            if (is_array($results) === true && count($results) > 0) {
                $first = $results[0];
                if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
                    $first = $first->jsonSerialize();
                }

                if (is_array($first) === true) {
                    return (string) ($first['value'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Procest: could not resolve mandaatCategorie', ['exception' => $e->getMessage()]);
        }//end try

        return '';
    }//end resolveMandaatCategory()
}//end class
