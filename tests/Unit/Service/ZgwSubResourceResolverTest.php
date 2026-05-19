<?php

/**
 * ZgwSubResourceResolver Unit Tests
 *
 * Tests for the ZGW sub-resource resolver service.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ZgwMappingService;
use OCA\Procest\Service\ZgwSubResourceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ZgwSubResourceResolver class.
 *
 * @covers \OCA\Procest\Service\ZgwSubResourceResolver
 */
class ZgwSubResourceResolverTest extends TestCase
{

    /**
     * Test that resolveZaakClosed returns null for a non-zaak sub-resource.
     *
     * Resources that are not in the zaakSubResources list should yield null.
     *
     * @return void
     */
    public function testResolveZaakClosedReturnsNullForNonZaakResource(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveZaakClosed(resource: 'statussen', existingData: []);
        $this->assertNull($result);
    }//end testResolveZaakClosedReturnsNullForNonZaakResource()

    /**
     * Test that resolveZaakClosed returns false for a zaak without einddatum.
     *
     * A zaak resource with no endDate/einddatum in existingData is open (not closed).
     *
     * @return void
     */
    public function testResolveZaakClosedReturnsFalseForOpenZaak(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveZaakClosed(resource: 'zaken', existingData: []);
        $this->assertFalse($result);
    }//end testResolveZaakClosedReturnsFalseForOpenZaak()

    /**
     * Test that resolveZaakClosed returns true for a zaak that has einddatum set.
     *
     * @return void
     */
    public function testResolveZaakClosedReturnsTrueWhenEinddatumSet(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveZaakClosed(
            resource: 'zaken',
            existingData: ['einddatum' => '2024-01-01']
        );
        $this->assertTrue($result);
    }//end testResolveZaakClosedReturnsTrueWhenEinddatumSet()

    /**
     * Test that resolveParentZaaktypeDraft returns null for a non-catalogus sub-resource.
     *
     * Resources not in the zaaktype sub-resources list should yield null.
     *
     * @return void
     */
    public function testResolveParentZaaktypeDraftReturnsNullForUnknownResource(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveParentZaaktypeDraft(resource: 'zaken', existingData: []);
        $this->assertNull($result);
    }//end testResolveParentZaaktypeDraftReturnsNullForUnknownResource()

    /**
     * Test that resolveZaakClosedFromBody returns null for a non-sub-resource.
     *
     * @return void
     */
    public function testResolveZaakClosedFromBodyReturnsNullForZaakResource(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveZaakClosedFromBody(resource: 'zaken', body: []);
        $this->assertNull($result);
    }//end testResolveZaakClosedFromBodyReturnsNullForZaakResource()

    /**
     * Test that resolveParentZaaktypeDraftFromBody returns null when zaaktype field is missing.
     *
     * @return void
     */
    public function testResolveParentZaaktypeDraftFromBodyReturnsNullWhenNoZaaktypeRef(): void
    {
        $service = new ZgwSubResourceResolver(
            objectService: null,
            zgwMappingService: $this->createMock(ZgwMappingService::class),
            logger: $this->createMock(LoggerInterface::class)
        );
        $result = $service->resolveParentZaaktypeDraftFromBody(
            resource: 'statustypen',
            body: []
        );
        $this->assertNull($result);
    }//end testResolveParentZaaktypeDraftFromBodyReturnsNullWhenNoZaaktypeRef()
}//end class
