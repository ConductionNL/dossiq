<?php

/**
 * Procest TermijnService.
 *
 * Server-authoritative service for the AWB termijnbewaking engine. Owns
 * the TermijnInstance lifecycle: create, get, update, complete. Resolves
 * the active TermijnDefinitie for a zaaktype (version-aware) and binds it
 * to a zaak on creation. Writes immutable TermijnGebeurtenis events for
 * every state change.
 *
 * Money / day amounts: all *daily impact* values are integers (days);
 * money values live on DwangsomBerekening / DwangsomUitbetaling and use
 * integer EUR cents (ADR-031).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-authoritative TermijnInstance lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TermijnService
{
    use SearchesObjects;

    /** @var array<string, array<string, mixed>> */
    private array $definitieCache = [];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings + ObjectService access.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new TermijnInstance for a zaak.
     *
     * Resolves the active TermijnDefinitie for the zaaktype, computes
     * einddatumBerekend = startDatum + standaardDuurDagen, persists the
     * instance, and writes a `start` TermijnGebeurtenis. Throws if no
     * matching definition exists (REQ-TERM-001-A).
     *
     * @param string                 $zaakId    The case id.
     * @param string                 $zaaktype  The zaaktype slug.
     * @param DateTimeImmutable|null $startDate Optional start (defaults to now).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When no TermijnDefinitie matches the zaaktype.
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function createTermijnInstance(string $zaakId, string $zaaktype, ?DateTimeImmutable $startDate = null): array
    {
        $startDate  = ($startDate ?? new DateTimeImmutable());
        $definitie  = $this->getTermijnDefinitie($zaaktype);
        if ($definitie === null) {
            throw new RuntimeException(
                'No active TermijnDefinitie configured for zaaktype "'.$zaaktype.'" (REQ-TERM-001-A)'
            );
        }

        $durationDays = (int) ($definitie['standaardDuurDagen'] ?? 0);
        $einddatum    = $startDate->modify('+'.$durationDays.' days')->format('Y-m-d');

        $instance = [
            'zaak'                   => $zaakId,
            'termijnDefinitie'       => (string) ($definitie['id'] ?? ''),
            'startDatum'             => $startDate->format('Y-m-d\TH:i:sP'),
            'einddatumBerekend'      => $einddatum,
            'einddatumActueel'       => $einddatum,
            'status'                 => 'lopend',
            'aantalVerlengingen'     => 0,
            'notificatiesVerstuurd'  => [],
        ];

        $saved = $this->save('termijn_instance_schema', $instance);

        $this->recordEvent(
            termijnInstanceId: (string) ($saved['id'] ?? ''),
            type: 'start',
            grondslag: (string) ($definitie['wettelijkeGrondslag'] ?? 'AWB 4:13'),
            motivering: 'Termijn gestart bij zaak-aanmaak',
            dagenImpact: $durationDays,
            tijdstip: $startDate,
        );

        return $saved;
    }//end createTermijnInstance()

    /**
     * Get TermijnInstance by id.
     *
     * @param string $termijnInstanceId Instance id.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function getTermijnInstance(string $termijnInstanceId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue('termijn_instance_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $row = $objectService->find($termijnInstanceId, register: $register, schema: $schema);
            return is_array($row) === true ? $row : null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TermijnService.getTermijnInstance failed',
                ['id' => $termijnInstanceId, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end getTermijnInstance()

    /**
     * Fetch the active TermijnInstance bound to a zaak (latest by start).
     *
     * @param string $zaakId Case id.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function getTermijnInstanceForZaak(string $zaakId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue('termijn_instance_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $rows = $this->searchObjectsAsArrays($objectService, $register, $schema, ['zaak' => $zaakId]);
        } catch (\Throwable $e) {
            return null;
        }

        $rows = is_array($rows) === true ? $rows : [];
        if (count($rows) === 0) {
            return null;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                strcmp((string) ($b['startDatum'] ?? ''), (string) ($a['startDatum'] ?? ''))
        );

        return $rows[0];
    }//end getTermijnInstanceForZaak()

    /**
     * Update a TermijnInstance (partial; merged on top of existing).
     *
     * @param string               $termijnInstanceId Instance id.
     * @param array<string, mixed> $patch             Partial patch.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function updateTermijnInstance(string $termijnInstanceId, array $patch): ?array
    {
        $current = $this->getTermijnInstance($termijnInstanceId);
        if ($current === null) {
            return null;
        }

        $merged       = array_merge($current, $patch);
        $merged['id'] = $termijnInstanceId;
        return $this->save('termijn_instance_schema', $merged);
    }//end updateTermijnInstance()

    /**
     * Resolve the active TermijnDefinitie for a zaaktype.
     *
     * Version-aware: returns the definition with the latest validFrom that
     * is <= today, where validUntil is null or > today.
     *
     * @param string $zaaktype Zaaktype slug.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function getTermijnDefinitie(string $zaaktype): ?array
    {
        if (isset($this->definitieCache[$zaaktype]) === true) {
            return $this->definitieCache[$zaaktype];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue('termijn_definitie_schema');
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $rows = $this->searchObjectsAsArrays($objectService, $register, $schema, ['zaaktype' => $zaaktype]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TermijnService.getTermijnDefinitie lookup failed',
                ['zaaktype' => $zaaktype, 'error' => $e->getMessage()]
            );
            return null;
        }

        $today  = (new DateTimeImmutable())->format('Y-m-d');
        $active = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $validFrom  = (string) ($row['validFrom']  ?? '1970-01-01');
            $validUntil = (string) ($row['validUntil'] ?? '');
            if ($validFrom <= $today && ($validUntil === '' || $validUntil >= $today)) {
                $active[] = $row;
            }
        }

        if (count($active) === 0) {
            return null;
        }

        usort(
            $active,
            static fn (array $a, array $b): int =>
                strcmp((string) ($b['validFrom'] ?? ''), (string) ($a['validFrom'] ?? ''))
        );

        $this->definitieCache[$zaaktype] = $active[0];
        return $active[0];
    }//end getTermijnDefinitie()

    /**
     * Mark a TermijnInstance as completed.
     *
     * @param string                 $termijnInstanceId Instance id.
     * @param DateTimeImmutable|null $voltooiDatum      When completed (default now).
     * @param string                 $documentLink      Optional document ref.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
     */
    public function markTermijnCompleted(
        string $termijnInstanceId,
        ?DateTimeImmutable $voltooiDatum = null,
        string $documentLink = ''
    ): ?array {
        $voltooiDatum = ($voltooiDatum ?? new DateTimeImmutable());

        $updated = $this->updateTermijnInstance(
            $termijnInstanceId,
            ['status' => 'voltooid', 'voltooiDatum' => $voltooiDatum->format('Y-m-d')]
        );

        if ($updated !== null) {
            $this->recordEvent(
                termijnInstanceId: $termijnInstanceId,
                type: 'voltooi',
                grondslag: 'AWB 4:13',
                motivering: 'Termijn voltooid door beschikking',
                dagenImpact: 0,
                tijdstip: $voltooiDatum,
                documentLink: $documentLink,
            );
        }

        return $updated;
    }//end markTermijnCompleted()

    /**
     * Append an immutable TermijnGebeurtenis row.
     *
     * @param string                 $termijnInstanceId Instance id.
     * @param string                 $type              Event type.
     * @param string                 $grondslag         Legal basis.
     * @param string                 $motivering        Reason.
     * @param int                    $dagenImpact       Days impact.
     * @param DateTimeImmutable|null $tijdstip          When (default now).
     * @param string                 $documentLink      Optional document ref.
     * @param string                 $actor             Optional actor (default 'system').
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
     */
    public function recordEvent(
        string $termijnInstanceId,
        string $type,
        string $grondslag,
        string $motivering,
        int $dagenImpact,
        ?DateTimeImmutable $tijdstip = null,
        string $documentLink = '',
        string $actor = 'system',
    ): ?array {
        $tijdstip = ($tijdstip ?? new DateTimeImmutable());
        $event    = [
            'termijnInstance' => $termijnInstanceId,
            'type'            => $type,
            'tijdstip'        => $tijdstip->format('Y-m-d\TH:i:sP'),
            'actor'           => $actor,
            'grondslag'       => $grondslag,
            'motivering'      => $motivering,
            'dagenImpact'     => $dagenImpact,
        ];
        if ($documentLink !== '') {
            $event['documentLink'] = $documentLink;
        }

        return $this->save('termijn_gebeurtenis_schema', $event);
    }//end recordEvent()

    /**
     * Persist an object to a configured schema.
     *
     * @param string               $schemaConfigKey The schema config key (e.g. 'termijn_instance_schema').
     * @param array<string, mixed> $object          The payload.
     *
     * @return array<string, mixed>|null
     */
    private function save(string $schemaConfigKey, array $object): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue($schemaConfigKey);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $object);
            return is_array($saved) === true ? $saved : null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'TermijnService persist failed',
                ['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end save()
}//end class
