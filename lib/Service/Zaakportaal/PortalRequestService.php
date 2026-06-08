<?php

/**
 * Procest Zaakportaal Request Service
 *
 * Creates citizen-initiated requests (PortaalVerzoek) for the Mijn gemeente
 * portal: bezwaarschriften (objections), klachten (complaints) and
 * subsidie-aanvragen. A bezwaar is only accepted within the statutory Awb
 * termijn, computed by the AwbDeadlineService. Each request persists via the
 * OpenRegister ObjectService and is stamped with the authenticated subject
 * reference (taken from the session, never the request body) so reads stay
 * IDOR-safe.
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-07
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Creates and lists citizen portal requests.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — request service composes
 * deadline and persistence collaborators for one bounded workflow.
 */
class PortalRequestService
{
    /**
     * Valid klacht categories.
     *
     * @var array<int, string>
     */
    public const KLACHT_CATEGORIES = ['Bejegening', 'Doorlooptijd', 'Communicatie', 'Medische/Zorgkwaliteit', 'Andere'];

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service.
     * @param AwbDeadlineService $deadlineService The Awb deadline helper.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private AwbDeadlineService $deadlineService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a bezwaar deadline without submitting.
     *
     * @param string $decisionDate The contested decision date.
     *
     * @return array{deadline: string, binnenTermijn: bool, dagenResterend: int}
     *
     * @throws OCSBadRequestException When the date is invalid.
     */
    public function validateBezwaarDeadline(string $decisionDate): array
    {
        $deadline = $this->deadlineService->bezwaarDeadline($decisionDate);

        return [
            'deadline'       => $deadline,
            'binnenTermijn'  => $this->deadlineService->isWithinBezwaarTermijn($decisionDate),
            'dagenResterend' => $this->deadlineService->daysRemaining($deadline),
        ];
    }//end validateBezwaarDeadline()

    /**
     * Submit a bezwaarschrift within the statutory termijn.
     *
     * @param array<string, mixed> $data       The request data.
     * @param string               $subjectRef The authenticated subject reference.
     *
     * @return array<string, mixed> The created request.
     *
     * @throws OCSBadRequestException When the deadline passed or validation fails.
     */
    public function submitBezwaar(array $data, string $subjectRef): array
    {
        $tegenZaakId  = trim((string) ($data['tegenZaakId'] ?? ''));
        $decisionDate = trim((string) ($data['decisionDate'] ?? ''));
        $motivering   = trim((string) ($data['motivering'] ?? ''));

        if ($tegenZaakId === '') {
            throw new OCSBadRequestException('tegenZaakId is verplicht');
        }

        if ($motivering === '') {
            throw new OCSBadRequestException('Motivering is verplicht');
        }

        if ($decisionDate === '') {
            throw new OCSBadRequestException('decisionDate is verplicht');
        }

        $deadline = $this->deadlineService->bezwaarDeadline($decisionDate);
        if ($this->deadlineService->isWithinBezwaarTermijn($decisionDate) === false) {
            throw new OCSBadRequestException('De termijn voor bezwaar (tot '.$deadline.') is verstreken');
        }

        $payload = [
            'soort'              => 'bezwaarschrift',
            'tegenZaakId'        => $tegenZaakId,
            'tegenBeschikkingId' => trim((string) ($data['tegenBeschikkingId'] ?? '')),
            'submitterType'      => $this->normaliseSubmitterType(data: $data),
            'submitterRef'       => $subjectRef,
            'submitterName'      => trim((string) ($data['submitterName'] ?? '')),
            'onderwerp'          => trim((string) ($data['onderwerp'] ?? 'Bezwaar')),
            'motivering'         => $motivering,
            'attachments'        => $this->normaliseAttachments(data: $data),
            'submittedAt'        => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
            'binnenTermijn'      => true,
            'deadline'           => $deadline,
            'referentie'         => $this->reference(prefix: 'BZ'),
            'status'             => 'ontvangen',
        ];

        return $this->persist(payload: $payload, kind: 'bezwaar');
    }//end submitBezwaar()

    /**
     * Submit a klacht (complaint).
     *
     * @param array<string, mixed> $data       The request data.
     * @param string               $subjectRef The authenticated subject reference.
     *
     * @return array<string, mixed> The created request.
     *
     * @throws OCSBadRequestException When validation fails.
     */
    public function submitKlacht(array $data, string $subjectRef): array
    {
        $categorie    = (string) ($data['categorie'] ?? '');
        $omschrijving = trim((string) ($data['omschrijving'] ?? ''));

        if (in_array($categorie, self::KLACHT_CATEGORIES, true) === false) {
            throw new OCSBadRequestException('Ongeldige categorie');
        }

        if ($omschrijving === '') {
            throw new OCSBadRequestException('Omschrijving is verplicht');
        }

        $payload = [
            'soort'               => 'klachtschrift',
            'submitterType'       => $this->normaliseSubmitterType(data: $data),
            'submitterRef'        => $subjectRef,
            'submitterName'       => trim((string) ($data['submitterName'] ?? '')),
            'categorie'           => $categorie,
            'onderwerp'           => trim((string) ($data['onderwerp'] ?? $categorie)),
            'motivering'          => $omschrijving,
            'betrokkenMedewerker' => trim((string) ($data['betrokkenMedewerker'] ?? '')),
            'attachments'         => $this->normaliseAttachments(data: $data),
            'submittedAt'         => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
            'referentie'          => $this->reference(prefix: 'KL'),
            'status'              => 'ontvangen',
        ];

        return $this->persist(payload: $payload, kind: 'klacht');
    }//end submitKlacht()

    /**
     * List the requests submitted by a subject (IDOR-safe).
     *
     * @param string $subjectRef The authenticated subject reference.
     * @param string $soort      Optional soort filter.
     *
     * @return array<int, array<string, mixed>> The requests.
     *
     * @throws OCSBadRequestException When storage is unavailable.
     */
    public function listForSubject(string $subjectRef, string $soort=''): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        $filters = [
            'register'     => (int) $register,
            'schema'       => (int) $schema,
            'submitterRef' => $subjectRef,
        ];

        if ($soort !== '') {
            $filters['soort'] = $soort;
        }

        try {
            $results = $objectService->findAll(['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->error('Zaakportaal: request list failed', ['error' => $e->getMessage()]);
            throw new OCSBadRequestException('Could not retrieve requests');
        }

        return array_map([$this, 'toArray'], $results);
    }//end listForSubject()

    /**
     * Persist a request payload.
     *
     * @param array<string, mixed> $payload The validated payload.
     * @param string               $kind    A short log discriminator.
     *
     * @return array<string, mixed> The saved request.
     *
     * @throws OCSBadRequestException When storage fails.
     */
    private function persist(array $payload, string $kind): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        $saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema);

        $this->logger->info('Zaakportaal: request submitted', ['kind' => $kind, 'referentie' => $payload['referentie']]);

        return $this->toArray(value: $saved);
    }//end persist()

    /**
     * Build a citizen-facing reference number.
     *
     * @param string $prefix The reference prefix (BZ/KL).
     *
     * @return string The reference.
     */
    private function reference(string $prefix): string
    {
        return $prefix.'-'.date('Y').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }//end reference()

    /**
     * Normalise the submitter type from request data.
     *
     * @param array<string, mixed> $data The request data.
     *
     * @return string The submitter type.
     */
    private function normaliseSubmitterType(array $data): string
    {
        $type = (string) ($data['submitterType'] ?? 'burger');
        if (in_array($type, ['burger', 'bedrijf', 'gemachtigde'], true) === true) {
            return $type;
        }

        return 'burger';
    }//end normaliseSubmitterType()

    /**
     * Normalise attachments to a list of string ids.
     *
     * @param array<string, mixed> $data The request data.
     *
     * @return array<int, string> The attachment ids.
     */
    private function normaliseAttachments(array $data): array
    {
        if (isset($data['attachments']) === true && is_array($data['attachments']) === true) {
            return array_values(array_map('strval', $data['attachments']));
        }

        return [];
    }//end normaliseAttachments()

    /**
     * Resolve the ObjectService and register/schema identifiers.
     *
     * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
     *
     * @throws OCSBadRequestException When OpenRegister is unavailable.
     */
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new OCSBadRequestException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('portaal_verzoek_schema');

        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Portaal verzoek schema is not configured');
        }

        return [$objectService, $register, $schema];
    }//end resolve()

    /**
     * Normalise an ObjectService result to a plain array.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string, mixed> The normalised array.
     */
    private function toArray(mixed $value): array
    {
        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end toArray()
}//end class
