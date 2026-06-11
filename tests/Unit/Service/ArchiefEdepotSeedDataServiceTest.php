<?php

/**
 * Unit tests for ArchiefEdepotSeedDataService.
 *
 * Exercises the seed pipeline against an in-memory ObjectService fake,
 * asserts idempotency, and verifies the documented seed shape
 * (omgevingsvergunning 5y, wmo-melding 10y, subsidie-verlening permanent).
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

use OCA\Procest\Service\ArchiefEdepotSeedDataService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ArchiefEdepotSeedDataService
 */
class ArchiefEdepotSeedDataServiceTest extends TestCase
{
    private FakeArchiefObjectService $objects;

    private ArchiefEdepotSeedDataService $service;

    protected function setUp(): void
    {
        $this->objects = new FakeArchiefObjectService();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'bewaar_termijn_regel_schema' => 'bewaarTermijnRegel',
                    default                      => '',
                };
            },
        );

        $this->service = new ArchiefEdepotSeedDataService(
            $settings,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Seed pass should write the three documented BewaarTermijnRegel rows.
     *
     * @return void
     */
    public function testSeedCreatesThreeRegels(): void
    {
        $result = $this->service->seed();

        self::assertTrue($result['success']);
        self::assertSame(3, $result['regels']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(3, $this->objects->store['bewaarTermijnRegel']);
    }

    /**
     * Re-running the seed must not duplicate (idempotency).
     *
     * @return void
     */
    public function testSeedIsIdempotent(): void
    {
        $this->service->seed();
        $second = $this->service->seed();

        self::assertTrue($second['success']);
        self::assertSame(0, $second['regels']);
        self::assertSame(3, $second['skipped']);
        self::assertCount(3, $this->objects->store['bewaarTermijnRegel']);
    }

    /**
     * Subsidie-verlening uses the permanent sentinel 9999.
     *
     * @return void
     */
    public function testPermanentRetentionEncodedAs9999(): void
    {
        $this->service->seed();

        $rows = $this->objects->store['bewaarTermijnRegel'];
        $subsidie = null;
        foreach ($rows as $row) {
            if (($row['zaaktypeKey'] ?? null) === 'subsidie-verlening') {
                $subsidie = $row;
                break;
            }
        }
        self::assertNotNull($subsidie, 'subsidie-verlening seed row must exist');
        self::assertSame(9999, $subsidie['bewaartermijnJaren']);
    }

    /**
     * Disabled ObjectService returns a sane failure shape (no fatal).
     *
     * @return void
     */
    public function testReturnsFailureWhenObjectServiceUnavailable(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $service = new ArchiefEdepotSeedDataService(
            $settings,
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->seed();
        self::assertFalse($result['success']);
        self::assertSame('OpenRegister is not available', $result['message']);
    }
}

/**
 * Tiny in-memory ObjectService fake covering the three calls
 * the seed pipeline performs (saveObject + findObjects via the
 * SearchesObjects trait).
 */
class FakeArchiefObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /**
     * @param string               $register Register.
     * @param string               $schema   Schema.
     * @param array<string, mixed> $object   Object.
     * @return array<string, mixed>
     */
    public function saveObject(string $register, string $schema, array $object): array
    {
        $id = (string) ($object['id'] ?? ('row-'.count($this->store[$schema] ?? [])));
        $object['id'] = $id;
        $this->store[$schema][$id] = $object;
        return $object;
    }

    /**
     * @param string               $register Register.
     * @param string               $schema   Schema.
     * @param array<string, mixed> $filters  Filters.
     * @return array<int, array<string, mixed>>
     */
    public function findObjects(string $register, string $schema, array $filters = []): array
    {
        return array_values($this->store[$schema] ?? []);
    }

    /**
     * Slug-aware search bridge mirroring ObjectService::searchObjectsBySlug().
     *
     * The SearchesObjects trait selects this entry point when register/schema
     * are slugs (as the seed service's config returns), so the fake must expose
     * it or idempotency detection silently fails open.
     *
     * @param string               $registerSlug Register slug.
     * @param string               $schemaSlug   Schema slug.
     * @param array<string, mixed> $filters      Filters.
     * @return array<int, array<string, mixed>>
     */
    public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array
    {
        return $this->findObjects($registerSlug, $schemaSlug, $filters);
    }

    /**
     * Numeric-ID search bridge mirroring ObjectService::searchObjects().
     *
     * @param array<string, mixed> $query Query carrying `@self` register/schema.
     * @return array<int, array<string, mixed>>
     */
    public function searchObjects(array $query = []): array
    {
        $schema = (string) (($query['@self'] ?? [])['schema'] ?? '');
        return $this->findObjects('', $schema);
    }
}
