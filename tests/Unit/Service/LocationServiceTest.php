<?php

/**
 * LocationService Unit Tests
 *
 * Tests for LocationService.validate(), reverseGeocode(), attachToCase(), listForCase().
 * Covers the cross-field validation rules from the gis-integration design.md.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gis-integration/tasks.md#task-23
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LocationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LocationService.
 *
 * @covers \OCA\Procest\Service\LocationService
 */
class LocationServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var LocationService
     */
    private LocationService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new LocationService(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that validate() returns an error when source is missing.
     *
     * @return void
     */
    public function testValidateReturnsMissingSourceError(): void
    {
        $errors = $this->service->validate([
            'case'      => 'case-uuid',
            'latitude'  => 52.3,
            'longitude' => 4.9,
        ]);

        $this->assertContains('source.required', $errors);

    }//end testValidateReturnsMissingSourceError()


    /**
     * Test that validate() returns an error for an invalid source value.
     *
     * @return void
     */
    public function testValidateReturnsInvalidSourceError(): void
    {
        $errors = $this->service->validate([
            'case'      => 'case-uuid',
            'source'    => 'invalid-source',
            'latitude'  => 52.3,
            'longitude' => 4.9,
        ]);

        $this->assertContains('source.invalid', $errors);

    }//end testValidateReturnsInvalidSourceError()


    /**
     * Test that validate() returns error when case UUID is missing.
     *
     * @return void
     */
    public function testValidateReturnsMissingCaseError(): void
    {
        $errors = $this->service->validate([
            'source'    => 'gps',
            'latitude'  => 52.3,
            'longitude' => 4.9,
            'accuracyRadius' => 5,
        ]);

        $this->assertContains('case.required', $errors);

    }//end testValidateReturnsMissingCaseError()


    /**
     * Test that validate() requires nummeraanduidingId for source=bag.
     *
     * @return void
     */
    public function testValidateRequiresNummeraanduidingIdForBagSource(): void
    {
        $errors = $this->service->validate([
            'case'      => 'case-uuid',
            'source'    => 'bag',
            'latitude'  => 52.3,
            'longitude' => 4.9,
        ]);

        $this->assertContains('nummeraanduidingId.required', $errors);

    }//end testValidateRequiresNummeraanduidingIdForBagSource()


    /**
     * Test that validate() returns no errors for a valid BAG payload.
     *
     * @return void
     */
    public function testValidatePassesForValidBagPayload(): void
    {
        $errors = $this->service->validate([
            'case'                => 'case-uuid',
            'source'              => 'bag',
            'nummeraanduidingId'  => '0363200003761521',
            'latitude'            => 52.3,
            'longitude'           => 4.9,
        ]);

        $this->assertEmpty($errors);

    }//end testValidatePassesForValidBagPayload()


    /**
     * Test that validate() returns error for GPS source without accuracyRadius.
     *
     * @return void
     */
    public function testValidateRequiresAccuracyRadiusForGpsSource(): void
    {
        $errors = $this->service->validate([
            'case'      => 'case-uuid',
            'source'    => 'gps',
            'latitude'  => 52.3,
            'longitude' => 4.9,
        ]);

        $this->assertContains('accuracyRadius.required', $errors);

    }//end testValidateRequiresAccuracyRadiusForGpsSource()


    /**
     * Test that validate() returns no errors for a valid GPS payload.
     *
     * @return void
     */
    public function testValidatePassesForValidGpsPayload(): void
    {
        $errors = $this->service->validate([
            'case'           => 'case-uuid',
            'source'         => 'gps',
            'latitude'       => 52.3,
            'longitude'      => 4.9,
            'accuracyRadius' => 5,
        ]);

        $this->assertEmpty($errors);

    }//end testValidatePassesForValidGpsPayload()


    /**
     * Test that validate() requires address OR coordinates for source=free.
     *
     * @return void
     */
    public function testValidateRequiresAddressOrCoordsForFreeSource(): void
    {
        $errors = $this->service->validate([
            'case'   => 'case-uuid',
            'source' => 'free',
            'label'  => 'Some field',
        ]);

        $this->assertContains('formattedAddress-or-coordinates.required', $errors);

    }//end testValidateRequiresAddressOrCoordsForFreeSource()


    /**
     * Test that validate() passes for a free source with coordinates only.
     *
     * @return void
     */
    public function testValidatePassesForFreeSourceWithCoordinatesOnly(): void
    {
        $errors = $this->service->validate([
            'case'      => 'case-uuid',
            'source'    => 'free',
            'latitude'  => 52.0,
            'longitude' => 5.0,
        ]);

        $this->assertEmpty($errors);

    }//end testValidatePassesForFreeSourceWithCoordinatesOnly()


    /**
     * Test that reverseGeocode() returns null for out-of-range latitude.
     *
     * @return void
     */
    public function testReverseGeocodeReturnsNullForInvalidLatitude(): void
    {
        $result = $this->service->reverseGeocode(latitude: 200.0, longitude: 4.9);

        $this->assertNull($result);

    }//end testReverseGeocodeReturnsNullForInvalidLatitude()


    /**
     * Test that reverseGeocode() returns null when PDOK service is unavailable.
     *
     * @return void
     */
    public function testReverseGeocodeReturnsNullWhenPdokUnavailable(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not found'));

        $result = $this->service->reverseGeocode(latitude: 52.3, longitude: 4.9);

        $this->assertNull($result);

    }//end testReverseGeocodeReturnsNullWhenPdokUnavailable()


    /**
     * Test that attachToCase() throws RuntimeException when caseId is empty.
     *
     * @return void
     */
    public function testAttachToCaseThrowsForEmptyCaseId(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->attachToCase(caseId: '', location: []);

    }//end testAttachToCaseThrowsForEmptyCaseId()


    /**
     * Test that attachToCase() throws RuntimeException when validation fails.
     *
     * @return void
     */
    public function testAttachToCaseThrowsWhenValidationFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/validation/');

        // Missing source and coordinates — validation should fail.
        $this->service->attachToCase(caseId: 'case-uuid', location: ['label' => 'no data']);

    }//end testAttachToCaseThrowsWhenValidationFails()


    /**
     * Test that attachToCase() throws RuntimeException when ObjectService is unavailable.
     *
     * @return void
     */
    public function testAttachToCaseThrowsWhenObjectServiceUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenRegister/');

        $this->service->attachToCase(
            caseId: 'case-uuid',
            location: [
                'source'    => 'gps',
                'latitude'  => 52.3,
                'longitude' => 4.9,
                'accuracyRadius' => 5,
            ]
        );

    }//end testAttachToCaseThrowsWhenObjectServiceUnavailable()


    /**
     * Test that listForCase() returns an empty array when ObjectService is unavailable.
     *
     * @return void
     */
    public function testListForCaseReturnsEmptyArrayWhenObjectServiceUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->listForCase(caseId: 'case-uuid');

        $this->assertSame([], $result);

    }//end testListForCaseReturnsEmptyArrayWhenObjectServiceUnavailable()


    /**
     * Test that listForCase() returns location records from ObjectService.
     *
     * @return void
     */
    public function testListForCaseReturnsLocationsFromObjectService(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);

        $expectedLocations = [
            ['id' => 'loc-1', 'case' => 'case-uuid', 'latitude' => 52.3, 'longitude' => 4.9],
        ];

        $mockObjectService
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn($expectedLocations);

        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', 'procest'],
            ['location_schema', 'location'],
        ]);

        $result = $this->service->listForCase(caseId: 'case-uuid');

        $this->assertSame($expectedLocations, $result);

    }//end testListForCaseReturnsLocationsFromObjectService()


}//end class
