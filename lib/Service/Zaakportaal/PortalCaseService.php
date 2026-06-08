<?php

/**
 * Procest Zaakportaal Case Service
 *
 * Read-only retrieval of cases for the Mijn gemeente citizen portal. Cases are
 * read from the existing Procest `case` schema (no shadow administration) and
 * mapped to citizen-facing ZaakOverzichtItem / ZaakDetail shapes. All reads are
 * scoped to the authenticated subject reference so a citizen can only ever see
 * cases in which they (or their machtiging target) are involved; a direct
 * lookup of a non-owned case yields a not-found rather than disclosing it.
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-04
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Retrieves and shapes citizen-facing case data.
 *
 * @psalm-suppress UnusedClass
 */
class PortalCaseService
{
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
     * List the cases visible to a subject as ZaakOverzichtItem entries.
     *
     * @param string             $subjectRef    The pseudonymous subject reference.
     * @param array<int, string> $zaaktypeScope Optional machtiging zaaktype restriction.
     *
     * @return array<int, array<string, mixed>> The overview items.
     *
     * @throws OCSBadRequestException When storage is unavailable.
     */
    public function listForSubject(string $subjectRef, array $zaaktypeScope=[]): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        $query = [
            'register'       => (int) $register,
            'schema'         => (int) $schema,
            'portaalSubject' => $subjectRef,
        ];

        try {
            $results = $objectService->findAll(['filters' => $query]);
        } catch (\Throwable $e) {
            $this->logger->error('Zaakportaal: case list failed', ['error' => $e->getMessage()]);
            throw new OCSBadRequestException('Could not retrieve cases');
        }

        $items = [];
        foreach ($results as $result) {
            $case = $this->toArray(value: $result);
            if ($case === []) {
                continue;
            }

            if ($zaaktypeScope !== [] && in_array((string) ($case['caseType'] ?? ''), $zaaktypeScope, true) === false) {
                continue;
            }

            $items[] = $this->toOverviewItem(case: $case);
        }

        return $items;
    }//end listForSubject()

    /**
     * Retrieve a single case detail, scoped to the subject (IDOR-safe).
     *
     * @param string             $id            The case id.
     * @param string             $subjectRef    The pseudonymous subject reference.
     * @param array<int, string> $zaaktypeScope Optional machtiging zaaktype restriction.
     *
     * @return array<string, mixed> The ZaakDetail shape.
     *
     * @throws OCSBadRequestException When the case is not found or not owned.
     */
    public function detailForSubject(string $id, string $subjectRef, array $zaaktypeScope=[]): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        try {
            $found = $objectService->find($id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            throw new OCSBadRequestException('Zaak niet gevonden');
        }

        $case = $this->toArray(value: $found);
        if ($case === [] || (string) ($case['portaalSubject'] ?? '') !== $subjectRef) {
            // Do not disclose existence to non-owners.
            throw new OCSBadRequestException('Zaak niet gevonden');
        }

        if ($zaaktypeScope !== [] && in_array((string) ($case['caseType'] ?? ''), $zaaktypeScope, true) === false) {
            throw new OCSBadRequestException('Zaak niet gevonden');
        }

        return $this->toDetail(case: $case);
    }//end detailForSubject()

    /**
     * Map a raw case to a ZaakOverzichtItem.
     *
     * @param array<string, mixed> $case The raw case.
     *
     * @return array<string, mixed> The overview item.
     */
    private function toOverviewItem(array $case): array
    {
        $deadline  = (string) ($case['deadline'] ?? '');
        $remaining = 0;
        if ($deadline !== '') {
            $remaining = $this->deadlineService->daysRemaining(deadline: $deadline);
        }

        $overschreden = ($deadline !== '' && $remaining === 0 && $this->deadlinePassed(deadline: $deadline) === true);

        return [
            'zaakId'      => (string) ($case['id'] ?? ($case['@self']['id'] ?? '')),
            'zaakKenmerk' => (string) ($case['identifier'] ?? ''),
            'zaaktype'    => (string) ($case['caseType'] ?? ''),
            'onderwerp'   => (string) ($case['title'] ?? ''),
            'status'      => (string) ($case['status'] ?? ''),
            'ingediendOp' => (string) ($case['startDate'] ?? ''),
            'termijnen'   => [
                'afhandelTermijnEinde' => $deadline,
                'termijnOverschreden'  => $overschreden,
                'dagenResterend'       => $remaining,
            ],
        ];
    }//end toOverviewItem()

    /**
     * Map a raw case to a ZaakDetail shape (handler details anonymised).
     *
     * @param array<string, mixed> $case The raw case.
     *
     * @return array<string, mixed> The detail shape.
     */
    private function toDetail(array $case): array
    {
        $deadline = (string) ($case['deadline'] ?? '');

        $overschreden = ($deadline !== '' && $this->deadlinePassed(deadline: $deadline) === true);

        $remaining = 0;
        if ($deadline !== '') {
            $remaining = $this->deadlineService->daysRemaining(deadline: $deadline);
        }

        return [
            'zaakId'          => (string) ($case['id'] ?? ($case['@self']['id'] ?? '')),
            'zaakKenmerk'     => (string) ($case['identifier'] ?? ''),
            'zaaktype'        => (string) ($case['caseType'] ?? ''),
            'onderwerp'       => (string) ($case['title'] ?? ''),
            'huidigeStatus'   => (string) ($case['status'] ?? ''),
            'tijdlijn'        => $this->normaliseTimeline(history: $case['statusHistory'] ?? []),
            'termijnen'       => [
                'afhandelTermijnEinde' => $deadline,
                'termijnOverschreden'  => $overschreden,
                'dagenResterend'       => $remaining,
            ],
            'mogelijkeActies' => $this->possibleActions(case: $case),
        ];
    }//end toDetail()

    /**
     * Normalise a status history array into timeline entries.
     *
     * @param mixed $history The raw status history.
     *
     * @return array<int, array<string, mixed>> The timeline.
     */
    private function normaliseTimeline(mixed $history): array
    {
        if (is_array($history) === false) {
            return [];
        }

        $timeline = [];
        foreach ($history as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $timeline[] = [
                'datum'       => (string) ($entry['date'] ?? ($entry['datum'] ?? '')),
                'status'      => (string) ($entry['status'] ?? ''),
                'toelichting' => (string) ($entry['explanation'] ?? ($entry['toelichting'] ?? '')),
            ];
        }

        return $timeline;
    }//end normaliseTimeline()

    /**
     * Derive the citizen-available actions for a case.
     *
     * @param array<string, mixed> $case The raw case.
     *
     * @return array<int, string> The action keys.
     */
    private function possibleActions(array $case): array
    {
        $actions = ['bericht-sturen'];

        $deadline = (string) ($case['bezwaarTermijnEindDatum'] ?? '');
        if ($deadline !== '' && $this->deadlinePassed(deadline: $deadline) === false) {
            $actions[] = 'bezwaar-indienen';
        }

        $actions[] = 'klacht-indienen';

        return $actions;
    }//end possibleActions()

    /**
     * Whether a deadline date is strictly in the past.
     *
     * @param string $deadline The deadline (Y-m-d).
     *
     * @return bool True when passed.
     */
    private function deadlinePassed(string $deadline): bool
    {
        try {
            return (new DateTimeImmutable($deadline)) < (new DateTimeImmutable('today'));
        } catch (\Throwable $e) {
            return false;
        }
    }//end deadlinePassed()

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
        $schema   = $this->settingsService->getConfigValue('case_schema');

        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Case schema is not configured');
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
