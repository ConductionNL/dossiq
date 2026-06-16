<?php

/**
 * DeelzaakService Unit Tests
 *
 * Covers the parent-dereference contract of getParentCase(): given a CHILD
 * sub-case UUID it must return the case referenced by the child's
 * `parentCase` field — never the child itself.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DeelzaakService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DeelzaakService::getParentCase().
 *
 * @covers \OCA\Procest\Service\DeelzaakService
 */
class DeelzaakServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Service under test.
     *
     * @var DeelzaakService
     */
    private DeelzaakService $service;

    /**
     * Set up fixtures: settings service returns a fixed register/schema and a
     * stub object service whose store is seeded per-test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key, string $default=''): string {
                return match ($key) {
                    'register' => 'procest',
                    'case_schema' => 'case',
                    default => $default,
                };
            }
        );

        $this->service = new DeelzaakService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Build a stub object service backed by an in-memory id => case map.
     *
     * @param array<string, array<string, mixed>> $store Seed objects keyed by UUID.
     *
     * @return object Stub implementing find().
     */
    private function makeObjectService(array $store): object
    {
        return new class($store) {
            /**
             * Construct the stub with its seed map.
             *
             * @param array<string, array<string, mixed>> $store Seed map.
             */
            public function __construct(private array $store)
            {
            }//end __construct()

            /**
             * Mimic OR ObjectService::find — return an object exposing
             * jsonSerialize(), or null when the id is unknown.
             *
             * @param string $id       Object UUID.
             * @param mixed  $register Register (ignored in the stub).
             * @param mixed  $schema   Schema (ignored in the stub).
             *
             * @return object|null
             */
            public function find(string $id, $register=null, $schema=null): ?object
            {
                if (isset($this->store[$id]) === false) {
                    return null;
                }

                $data = $this->store[$id];
                return new class($data) {
                    /**
                     * Construct the case wrapper.
                     *
                     * @param array<string, mixed> $data Case data.
                     */
                    public function __construct(private array $data)
                    {
                    }//end __construct()

                    /**
                     * Serialise the case to an array.
                     *
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return $this->data;
                    }//end jsonSerialize()
                };
            }//end find()
        };
    }//end makeObjectService()

    /**
     * GetParentCase(childUuid) must dereference the child's parentCase and
     * return the PARENT — and the returned object must NOT be the child.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function testGetParentCaseReturnsParentNotChild(): void
    {
        $childUuid  = 'child-111';
        $parentUuid = 'parent-999';

        $store = [
            $childUuid  => ['id' => $childUuid, 'title' => 'Sub-case', 'parentCase' => $parentUuid],
            $parentUuid => ['id' => $parentUuid, 'title' => 'Parent case'],
        ];

        $this->settingsService->method('getObjectService')
            ->willReturn($this->makeObjectService(store: $store));

        $result = $this->service->getParentCase(childCaseUuid: $childUuid);

        $this->assertNotNull(actual: $result);
        $this->assertSame(expected: $parentUuid, actual: $result['id']);
        $this->assertSame(expected: 'Parent case', actual: $result['title']);
        // The core regression: never echo the child back as its own parent.
        $this->assertNotSame(expected: $childUuid, actual: $result['id']);
    }//end testGetParentCaseReturnsParentNotChild()

    /**
     * A case with no parentCase reference (a top-level case, not a sub-case)
     * resolves to null rather than returning itself.
     *
     * @return void
     */
    public function testGetParentCaseReturnsNullWhenNoParentReference(): void
    {
        $uuid  = 'top-level-1';
        $store = [$uuid => ['id' => $uuid, 'title' => 'Top-level case']];

        $this->settingsService->method('getObjectService')
            ->willReturn($this->makeObjectService(store: $store));

        $this->assertNull(actual: $this->service->getParentCase(childCaseUuid: $uuid));
    }//end testGetParentCaseReturnsNullWhenNoParentReference()

    /**
     * A self-referencing parentCase (data-integrity edge) resolves to null,
     * never returning the child as its own parent.
     *
     * @return void
     */
    public function testGetParentCaseGuardsSelfReference(): void
    {
        $uuid  = 'self-ref-1';
        $store = [$uuid => ['id' => $uuid, 'parentCase' => $uuid]];

        $this->settingsService->method('getObjectService')
            ->willReturn($this->makeObjectService(store: $store));

        $this->assertNull(actual: $this->service->getParentCase(childCaseUuid: $uuid));
    }//end testGetParentCaseGuardsSelfReference()

    /**
     * When the child's parentCase points at a now-missing case, getParentCase
     * returns null (the dangling reference does not surface the child).
     *
     * @return void
     */
    public function testGetParentCaseReturnsNullWhenParentMissing(): void
    {
        $childUuid = 'child-222';
        $store     = [$childUuid => ['id' => $childUuid, 'parentCase' => 'gone-000']];

        $this->settingsService->method('getObjectService')
            ->willReturn($this->makeObjectService(store: $store));

        $this->assertNull(actual: $this->service->getParentCase(childCaseUuid: $childUuid));
    }//end testGetParentCaseReturnsNullWhenParentMissing()

    /**
     * The expanded-object parentCase shape ({ id }) is dereferenced too.
     *
     * @return void
     */
    public function testGetParentCaseHandlesExpandedParentReference(): void
    {
        $childUuid  = 'child-333';
        $parentUuid = 'parent-333';

        $store = [
            $childUuid  => ['id' => $childUuid, 'parentCase' => ['id' => $parentUuid]],
            $parentUuid => ['id' => $parentUuid, 'title' => 'Expanded parent'],
        ];

        $this->settingsService->method('getObjectService')
            ->willReturn($this->makeObjectService(store: $store));

        $result = $this->service->getParentCase(childCaseUuid: $childUuid);

        $this->assertNotNull(actual: $result);
        $this->assertSame(expected: $parentUuid, actual: $result['id']);
        $this->assertNotSame(expected: $childUuid, actual: $result['id']);
    }//end testGetParentCaseHandlesExpandedParentReference()

    /**
     * Empty input and missing OpenRegister both degrade to null.
     *
     * @return void
     */
    public function testGetParentCaseReturnsNullOnEmptyOrNoRegister(): void
    {
        $this->assertNull(actual: $this->service->getParentCase(childCaseUuid: ''));

        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->assertNull(actual: $this->service->getParentCase(childCaseUuid: 'whatever'));
    }//end testGetParentCaseReturnsNullOnEmptyOrNoRegister()
}//end class
