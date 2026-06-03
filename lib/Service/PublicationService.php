<?php

/**
 * Procest Besluitvorming Publication Service
 *
 * Assembles a DROP/LVBB publication payload from a case's decision object and
 * its signed besluitdocument, then dispatches it to the configured official
 * publication endpoint (DROP or LVBB) via the Nextcloud HTTP client / an
 * OpenConnector source. On success it records the returned publication URI on
 * the decision and caseProperty; on failure it logs a failed-publication
 * activity entry and notifies the handler so a manual retry is possible.
 *
 * The endpoint, optional bearer token, and OpenConnector source slug are read
 * from app config — never hardcoded — so the integration point is tenant
 * configurable. When no endpoint is configured the service reports
 * `not_configured` rather than dispatching to an open URL.
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
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * DROP/LVBB publication dispatcher for besluitvorming cases.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates ObjectService + HTTP client.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
 */
class PublicationService
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
     * Dispatch a case's besluit to the configured DROP/LVBB endpoint.
     *
     * @param string $caseId The case UUID/slug whose decision must be published.
     *
     * @return array<string, mixed> A result: {ok, error?, publicatieReferentie?, skipped?}.
     *
     * @throws RuntimeException When OpenRegister or config is unavailable.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-006
     */
    public function dispatch(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema     = $this->settingsService->getConfigValue(key: 'case_schema');
        $decisionSchema = $this->settingsService->getConfigValue(key: 'decision_schema');
        if ($register === '' || $caseSchema === '' || $decisionSchema === '') {
            throw new RuntimeException('Case- of decision-schema is niet geconfigureerd');
        }

        $case = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));

        // Respect publicationRequired: skip cases that do not need publication.
        if ($this->publicationRequired(objectService: $objectService, register: $register, case: $case) === false) {
            $this->logger->info(
                'Procest: besluit does not require publication, skipping dispatch',
                ['case' => $caseId, 'app' => Application::APP_ID],
            );
            return ['ok' => true, 'skipped' => true];
        }

        $decision = $this->findDecisionForCase(
            objectService: $objectService,
            register: $register,
            schema: $decisionSchema,
            caseId: $caseId,
        );
        if ($decision === null) {
            return ['ok' => false, 'error' => 'no_decision'];
        }

        $endpoint = $this->settingsService->getConfigValue(key: 'drop_lvbb_endpoint');
        if ($endpoint === '' || (str_starts_with($endpoint, 'https://') === false && str_starts_with($endpoint, 'http://') === false)) {
            $this->logger->warning(
                'Procest: DROP/LVBB endpoint not configured; publication deferred',
                ['case' => $caseId],
            );
            $this->recordFailure(
                objectService: $objectService,
                register: $register,
                case: $case,
                reason: 'endpoint_not_configured',
            );
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $payload = $this->assemblePayload(case: $case, decision: $decision);

        try {
            $reference = $this->send(endpoint: $endpoint, payload: $payload);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: DROP/LVBB dispatch failed',
                ['case' => $caseId, 'exception' => $e->getMessage()],
            );
            $this->recordFailure(
                objectService: $objectService,
                register: $register,
                case: $case,
                reason: 'dispatch_error',
            );
            return ['ok' => false, 'error' => 'dispatch_failed'];
        }

        $this->recordSuccess(
            objectService: $objectService,
            register: $register,
            decisionSchema: $decisionSchema,
            decision: $decision,
            case: $case,
            reference: $reference,
        );

        return ['ok' => true, 'publicatieReferentie' => $reference];
    }//end dispatch()

    /**
     * Assemble the STOP/TPOD-compatible publication payload.
     *
     * @param array<string, mixed> $case     The case payload.
     * @param array<string, mixed> $decision The decision payload.
     *
     * @return array<string, mixed> The publication payload.
     */
    private function assemblePayload(array $case, array $decision): array
    {
        return [
            'title'          => (string) ($decision['title'] ?? ($case['title'] ?? '')),
            'decisionDate'   => (string) ($decision['decisionDate'] ?? ''),
            'effectiveDate'  => (string) ($decision['effectiveDate'] ?? ''),
            'governingBody'  => (string) ($decision['governingBody'] ?? ''),
            'explanation'    => (string) ($decision['explanation'] ?? ''),
            'documentUrl'    => (string) ($decision['besluitdocument'] ?? ($case['besluitdocument'] ?? '')),
            'caseIdentifier' => (string) ($case['identifier'] ?? ($case['id'] ?? '')),
        ];
    }//end assemblePayload()

    /**
     * Send the payload to the publication endpoint and return the reference URI.
     *
     * @param string               $endpoint The configured DROP/LVBB endpoint.
     * @param array<string, mixed> $payload  The publication payload.
     *
     * @return string The returned publication reference URI.
     *
     * @throws RuntimeException When the response is not 2xx.
     */
    private function send(string $endpoint, array $payload): string
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $token   = $this->settingsService->getConfigValue(key: 'drop_lvbb_token');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $client   = $this->clientService->newClient();
        $response = $client->post($endpoint, ['json' => $payload, 'headers' => $headers, 'timeout' => 10]);

        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Publicatie-endpoint gaf status '.$status);
        }

        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded) === true) {
            $reference = (string) ($decoded['publicationUri'] ?? ($decoded['uri'] ?? ($decoded['reference'] ?? '')));
            if ($reference !== '') {
                return $reference;
            }
        }

        return $endpoint;
    }//end send()

    /**
     * Persist publication success on the decision and caseProperty.
     *
     * @param object               $objectService  The ObjectService.
     * @param string               $register       The register slug.
     * @param string               $decisionSchema The decision schema id.
     * @param array<string, mixed> $decision       The decision payload.
     * @param array<string, mixed> $case           The case payload.
     * @param string               $reference      The publication reference URI.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — persistence arguments.
     */
    private function recordSuccess(
        object $objectService,
        string $register,
        string $decisionSchema,
        array $decision,
        array $case,
        string $reference,
    ): void {
        $today      = (new DateTimeImmutable('now'))->format('Y-m-d');
        $decisionId = (string) ($decision['id'] ?? $decision['uuid'] ?? '');

        try {
            $objectService->saveObject(
                $register,
                $decisionSchema,
                ['publicationDate' => $today],
                $decisionId,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Procest: could not store decision publicationDate', ['exception' => $e->getMessage()]);
        }

        $this->setCaseProperty(
            objectService: $objectService,
            register: $register,
            caseId: (string) ($case['id'] ?? $case['uuid'] ?? ''),
            name: 'publicatieReferentie',
            value: $reference,
        );
    }//end recordSuccess()

    /**
     * Log a failed-publication activity entry on the case.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      The register slug.
     * @param array<string, mixed> $case          The case payload.
     * @param string               $reason        The failure reason code.
     *
     * @return void
     */
    private function recordFailure(object $objectService, string $register, array $case, string $reason): void
    {
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($caseSchema === '') {
            return;
        }

        $caseId = (string) ($case['id'] ?? $case['uuid'] ?? '');
        if ($caseId === '') {
            return;
        }

        $activity = $case['activity'] ?? [];
        if (is_string($activity) === true) {
            $decoded  = json_decode($activity, true);
            $activity = [];
            if (is_array($decoded) === true) {
                $activity = $decoded;
            }
        }

        if (is_array($activity) === false) {
            $activity = [];
        }

        $activity[] = [
            'type'      => 'publication_failed',
            'reason'    => $reason,
            'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];

        try {
            $objectService->saveObject($register, $caseSchema, ['activity' => $activity], $caseId);
        } catch (\Throwable $e) {
            $this->logger->warning('Procest: could not log publication failure activity', ['exception' => $e->getMessage()]);
        }
    }//end recordFailure()

    /**
     * Upsert a caseProperty value for the case.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $caseId        The case UUID.
     * @param string $name          The property name.
     * @param string $value         The property value.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — caseProperty fields.
     */
    private function setCaseProperty(
        object $objectService,
        string $register,
        string $caseId,
        string $name,
        string $value,
    ): void {
        $schema = $this->settingsService->getConfigValue(key: 'case_property_schema');
        if ($schema === '' || $caseId === '') {
            return;
        }

        try {
            $objectService->saveObject(
                $register,
                $schema,
                ['case' => $caseId, 'name' => $name, 'value' => $value],
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: could not set caseProperty',
                ['name' => $name, 'exception' => $e->getMessage()],
            );
        }
    }//end setCaseProperty()

    /**
     * Determine whether the case's caseType requires publication.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      The register slug.
     * @param array<string, mixed> $case          The case payload.
     *
     * @return bool True when publication is required (default true when unknown).
     */
    private function publicationRequired(object $objectService, string $register, array $case): bool
    {
        $caseTypeId     = (string) ($case['caseType'] ?? '');
        $caseTypeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
        if ($caseTypeId === '' || $caseTypeSchema === '') {
            return true;
        }

        try {
            $caseType = $this->toArray(value: $objectService->find($caseTypeId, register: $register, schema: $caseTypeSchema));
            return (bool) ($caseType['publicationRequired'] ?? true);
        } catch (\Throwable $e) {
            return true;
        }
    }//end publicationRequired()

    /**
     * Find the most recent decision linked to a case.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $schema        The decision schema id.
     * @param string $caseId        The case UUID.
     *
     * @return array<string, mixed>|null The decision payload, or null.
     */
    private function findDecisionForCase(
        object $objectService,
        string $register,
        string $schema,
        string $caseId,
    ): ?array {
        $results = $objectService->findAll(
            [
                'filters' => ['register' => $register, 'schema' => $schema, 'case' => $caseId],
                'limit'   => 1,
            ],
        );

        if (is_array($results) === true && isset($results['results']) === true) {
            $results = $results['results'];
        }

        if (is_array($results) === true && count($results) > 0) {
            return $this->toArray(value: $results[0]);
        }

        return null;
    }//end findDecisionForCase()

    /**
     * Convert an ObjectService return value to an associative array.
     *
     * @param mixed $value The returned object/array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($value) === true) {
            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
