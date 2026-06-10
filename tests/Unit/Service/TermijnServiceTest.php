<?php

/**
 * Unit tests for TermijnService.
 *
 * Drives termijn instance creation, definition version resolution,
 * completion, and error handling against an in-memory ObjectService fake.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\TermijnService
 */
class TermijnServiceTest extends TestCase
{
    private FakeTermijnStore $objects;

    private TermijnService $service;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_definitie_schema'   => 'termijnDefinitie',
                    'termijn_instance_schema'    => 'termijnInstance',
                    'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
                    default                      => '',
                };
            },
        );

        $this->service = new TermijnService($settings, $this->createMock(LoggerInterface::class));

        // Seed two definitions: omgevingsvergunning 56d (active) + Wmo 42d (active).
        $this->objects->saveObject('procest', 'termijnDefinitie', [
            'id'                  => 'td-omgevingsvergunning-regulier',
            'zaaktype'            => 'omgevingsvergunning-regulier',
            'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
            'standaardDuurDagen'  => 56,
            'aantalVerlengingen'  => 1,
            'validFrom'           => '2026-01-01',
        ]);
        $this->objects->saveObject('procest', 'termijnDefinitie', [
            'id'                  => 'td-wmo-aanvraag',
            'zaaktype'            => 'wmo-melding',
            'wettelijkeGrondslag' => 'Wmo 2015 art 2.3.5',
            'standaardDuurDagen'  => 42,
            'aantalVerlengingen'  => 0,
            'validFrom'           => '2026-01-01',
        ]);
    }

    /**
     * @return void
     */
    public function testCreateTermijnInstanceForOmgevingsvergunningHas56DayDeadline(): void
    {
        $start    = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $instance = $this->service->createTermijnInstance('Z/2026/123', 'omgevingsvergunning-regulier', $start);

        self::assertSame('Z/2026/123', $instance['zaak']);
        self::assertSame('td-omgevingsvergunning-regulier', $instance['termijnDefinitie']);
        self::assertSame('lopend', $instance['status']);
        self::assertSame('2026-07-27', $instance['einddatumBerekend']);
        self::assertSame('2026-07-27', $instance['einddatumActueel']);

        // Start event recorded.
        $events = $this->objects->store['termijnGebeurtenis'] ?? [];
        self::assertCount(1, $events);
        $event = array_values($events)[0];
        self::assertSame('start', $event['type']);
        self::assertSame(56, $event['dagenImpact']);
        self::assertSame('Wabo 3.9 lid 1', $event['grondslag']);
    }

    /**
     * @return void
     */
    public function testCreateTermijnInstanceForWmoHas42DayDeadline(): void
    {
        $start    = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $instance = $this->service->createTermijnInstance('Z/2026/124', 'wmo-melding', $start);

        self::assertSame('2026-07-13', $instance['einddatumBerekend']);
    }

    /**
     * @return void
     */
    public function testCreateTermijnInstanceFailsWithoutMatchingDefinition(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REQ-TERM-001-A');

        $this->service->createTermijnInstance('Z/2026/125', 'unknown-zaaktype');
    }

    /**
     * @return void
     */
    public function testGetTermijnDefinitieReturnsLatestActiveVersion(): void
    {
        // Add a newer version of the omgevingsvergunning definition.
        $this->objects->saveObject('procest', 'termijnDefinitie', [
            'id'                  => 'td-omgevingsvergunning-regulier-v2',
            'zaaktype'            => 'omgevingsvergunning-regulier',
            'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
            'standaardDuurDagen'  => 70,
            'validFrom'           => '2026-03-01',
        ]);

        // Reset cache by creating a new service.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_definitie_schema'   => 'termijnDefinitie',
                    'termijn_instance_schema'    => 'termijnInstance',
                    'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
                    default                      => '',
                };
            },
        );
        $service = new TermijnService($settings, $this->createMock(LoggerInterface::class));

        $resolved = $service->getTermijnDefinitie('omgevingsvergunning-regulier');
        self::assertNotNull($resolved);
        self::assertSame('td-omgevingsvergunning-regulier-v2', $resolved['id']);
        self::assertSame(70, $resolved['standaardDuurDagen']);
    }

    /**
     * @return void
     */
    public function testMarkTermijnCompletedRecordsVoltooiEvent(): void
    {
        $instance = $this->service->createTermijnInstance('Z/2026/126', 'omgevingsvergunning-regulier');
        $id       = (string) $instance['id'];

        $voltooid = $this->service->markTermijnCompleted($id, new DateTimeImmutable('2026-07-01'));
        self::assertNotNull($voltooid);
        self::assertSame('voltooid', $voltooid['status']);
        self::assertSame('2026-07-01', $voltooid['voltooiDatum']);

        $events    = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
        $voltooiEv = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'voltooi'));
        self::assertCount(1, $voltooiEv);
    }

    /**
     * @return void
     */
    public function testGetTermijnInstanceForZaakReturnsLatest(): void
    {
        $first = $this->service->createTermijnInstance(
            'Z/2026/127',
            'wmo-melding',
            new DateTimeImmutable('2026-05-01T10:00:00+00:00')
        );
        $second = $this->service->createTermijnInstance(
            'Z/2026/127',
            'wmo-melding',
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );

        $resolved = $this->service->getTermijnInstanceForZaak('Z/2026/127');
        self::assertNotNull($resolved);
        self::assertSame($second['id'], $resolved['id']);
    }
}

/**
 * Tiny in-memory ObjectService fake reused by all termijnbewaking unit tests.
 */
class FakeTermijnStore
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var int */
    private int $seq = 0;

    /**
     * @param string $id       Id.
     * @param string $register Register.
     * @param string $schema   Schema.
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register = '', string $schema = ''): ?array
    {
        return ($this->store[$schema][$id] ?? null);
    }

    /**
     * @param string               $register Register.
     * @param string               $schema   Schema.
     * @param array<string, mixed> $filters  Filters.
     * @return array<int, array<string, mixed>>
     */
    public function findObjects(string $register, string $schema, array $filters = []): array
    {
        $rows = array_values($this->store[$schema] ?? []);
        if (count($filters) === 0) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($filters): bool {
                foreach ($filters as $key => $value) {
                    if (($row[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            },
        ));
    }

    /**
     * @param string               $register Register.
     * @param string               $schema   Schema.
     * @param array<string, mixed> $object   Object.
     * @return array<string, mixed>
     */
    public function saveObject(string $register, string $schema, array $object): array
    {
        if (empty($object['id']) === true) {
            $this->seq++;
            $object['id'] = $schema.'-'.$this->seq;
        }
        $this->store[$schema][$object['id']] = $object;
        return $object;
    }
}
