<?php

/**
 * WfsService Unit Tests
 *
 * Tests for the OGC WFS 2.0.0 XML renderer that wraps WfsExportService
 * (gis-integration spec): GetCapabilities, DescribeFeatureType, GetFeature.
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
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\WfsExportService;
use OCA\Procest\Service\WfsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WfsService.
 *
 * @covers \OCA\Procest\Service\WfsService
 */
class WfsServiceTest extends TestCase
{

    /**
     * @var WfsExportService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WfsExportService $exportService;

    /**
     * @var WfsService
     */
    private WfsService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->exportService = $this->createMock(originalClassName: WfsExportService::class);
        $this->service       = new WfsService(wfsExportService: $this->exportService);
    }//end setUp()

    /**
     * GetCapabilities renders well-formed WFS 2.0.0 XML advertising the feature type.
     *
     * @return void
     */
    public function testGetCapabilitiesRendersWellFormedXml(): void
    {
        $this->exportService->method('buildCapabilities')->willReturn(
            [
                'version'      => '2.0.0',
                'title'        => 'Procest Case Locations WFS',
                'abstract'     => 'Case locations',
                'featureTypes' => [
                    ['name' => 'procest:cases', 'title' => 'Case Locations'],
                ],
            ]
        );

        $xml = $this->service->getCapabilities('https://example.org/wfs/cases');

        $this->assertStringContainsString('<wfs:WFS_Capabilities', $xml);
        $this->assertStringContainsString('version="2.0.0"', $xml);
        $this->assertStringContainsString('<wfs:Name>procest:cases</wfs:Name>', $xml);
        $this->assertStringContainsString('GetFeature', $xml);
        $this->assertWellFormed($xml);
    }//end testGetCapabilitiesRendersWellFormedXml()

    /**
     * DescribeFeatureType renders a well-formed XSD describing the case fields.
     *
     * @return void
     */
    public function testDescribeFeatureTypeRendersXsd(): void
    {
        $xml = $this->service->describeFeatureType();

        $this->assertStringContainsString('<xsd:schema', $xml);
        $this->assertStringContainsString('name="cases"', $xml);
        $this->assertStringContainsString('name="caseStatus"', $xml);
        $this->assertWellFormed($xml);
    }//end testDescribeFeatureTypeRendersXsd()

    /**
     * GetFeature renders a GML FeatureCollection with a member per feature.
     *
     * @return void
     */
    public function testGetFeatureRendersGmlMembers(): void
    {
        $this->exportService->method('buildFeatureCollection')->willReturn(
            [
                'type'     => 'FeatureCollection',
                'features' => [
                    [
                        'type'       => 'Feature',
                        'id'         => 'loc-1',
                        'geometry'   => ['type' => 'Point', 'coordinates' => [5.1, 52.1]],
                        'properties' => ['caseId' => 'c1', 'caseStatus' => 'open', 'caseTitle' => 'Test & Co'],
                    ],
                ],
            ]
        );

        $xml = $this->service->getFeature();

        $this->assertStringContainsString('<wfs:FeatureCollection', $xml);
        $this->assertStringContainsString('numberReturned="1"', $xml);
        $this->assertStringContainsString('<gml:pos>52.1 5.1</gml:pos>', $xml);
        $this->assertStringContainsString('<procest:caseStatus>open</procest:caseStatus>', $xml);
        // XML special chars are escaped.
        $this->assertStringContainsString('Test &amp; Co', $xml);
        $this->assertWellFormed($xml);
    }//end testGetFeatureRendersGmlMembers()

    /**
     * GetFeature passes the bbox/status/caseType filters straight through to the
     * export service.
     *
     * @return void
     */
    public function testGetFeatureForwardsFilters(): void
    {
        $this->exportService->expects($this->once())
            ->method('buildFeatureCollection')
            ->with(
                maxFeatures: 50,
                bbox: [4.8, 52.3, 4.9, 52.4],
                status: 'open',
                caseType: 'subsidie',
            )
            ->willReturn(['type' => 'FeatureCollection', 'features' => []]);

        $xml = $this->service->getFeature(
            bbox: [4.8, 52.3, 4.9, 52.4],
            maxFeatures: 50,
            status: 'open',
            caseType: 'subsidie',
        );

        $this->assertStringContainsString('numberReturned="0"', $xml);
    }//end testGetFeatureForwardsFilters()

    /**
     * An empty (degraded) collection still renders a valid FeatureCollection.
     *
     * @return void
     */
    public function testGetFeatureHandlesEmptyCollectionGracefully(): void
    {
        $this->exportService->method('buildFeatureCollection')->willReturn(
            ['type' => 'FeatureCollection', 'features' => []]
        );

        $xml = $this->service->getFeature();

        $this->assertStringContainsString('numberReturned="0"', $xml);
        $this->assertWellFormed($xml);
    }//end testGetFeatureHandlesEmptyCollectionGracefully()

    /**
     * Assert that a string parses as well-formed XML.
     *
     * @param string $xml The XML string.
     *
     * @return void
     */
    private function assertWellFormed(string $xml): void
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($doc, 'XML should be well-formed');
    }//end assertWellFormed()
}//end class
