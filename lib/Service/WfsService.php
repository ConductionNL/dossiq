<?php

/**
 * Procest WFS Service
 *
 * Renders OGC Web Feature Service (WFS) 2.0.0 XML documents for the public
 * `/wfs/cases` endpoint. This service is the XML face of the existing
 * {@see WfsExportService}: it REUSES that service for all data access
 * (feature collection + capabilities descriptor) and only owns the XML
 * serialisation the JSON `WfsExportController` does not provide.
 *
 * Operations:
 *   - GetCapabilities    — advertises the procest:cases feature type.
 *   - DescribeFeatureType — XSD describing the case-location feature schema.
 *   - GetFeature         — GML 3.2 wfs:FeatureCollection of case locations,
 *                          honouring the BBOX filter and CRS (EPSG:4326).
 *
 * Graceful degradation: when OpenRegister is unavailable WfsExportService
 * returns an empty collection, so GetFeature renders an empty (but valid)
 * FeatureCollection rather than failing the request.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

/**
 * Generates OGC WFS 2.0.0 XML responses from case-location data.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
class WfsService
{

    /**
     * WFS protocol version this service implements.
     */
    public const WFS_VERSION = '2.0.0';

    /**
     * Default CRS URN (WGS84 lon/lat).
     */
    public const DEFAULT_CRS = 'urn:ogc:def:crs:EPSG::4326';

    /**
     * Constructor.
     *
     * @param WfsExportService $wfsExportService The GeoJSON feature/capability provider (reused).
     *
     * @return void
     */
    public function __construct(
        private readonly WfsExportService $wfsExportService,
    ) {
    }//end __construct()

    /**
     * Render an OGC WFS 2.0.0 GetCapabilities document.
     *
     * @param string $baseUrl Absolute URL of the WFS endpoint.
     *
     * @return string WFS_Capabilities XML.
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function getCapabilities(string $baseUrl): string
    {
        $caps        = $this->wfsExportService->buildCapabilities(baseUrl: $baseUrl);
        $featureType = ($caps['featureTypes'][0] ?? []);
        $name        = $this->xmlEscape(value: (string) ($featureType['name'] ?? WfsExportService::TYPE_NAME_CASES));
        $title       = $this->xmlEscape(value: (string) ($featureType['title'] ?? 'Case Locations'));
        $abstract    = $this->xmlEscape(value: (string) ($caps['abstract'] ?? ''));
        $serviceTtl  = $this->xmlEscape(value: (string) ($caps['title'] ?? 'Procest Case Locations WFS'));
        $escapedUrl  = $this->xmlEscape(value: $baseUrl);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<wfs:WFS_Capabilities version="'.self::WFS_VERSION.'"'
            .' xmlns:wfs="http://www.opengis.net/wfs/2.0"'
            .' xmlns:ows="http://www.opengis.net/ows/1.1"'
            .' xmlns:xlink="http://www.w3.org/1999/xlink"'
            .' xmlns:procest="http://procest.nl/wfs">'."\n";

        $xml .= '  <ows:ServiceIdentification>'."\n";
        $xml .= '    <ows:Title>'.$serviceTtl.'</ows:Title>'."\n";
        $xml .= '    <ows:Abstract>'.$abstract.'</ows:Abstract>'."\n";
        $xml .= '    <ows:ServiceType>WFS</ows:ServiceType>'."\n";
        $xml .= '    <ows:ServiceTypeVersion>'.self::WFS_VERSION.'</ows:ServiceTypeVersion>'."\n";
        $xml .= '  </ows:ServiceIdentification>'."\n";

        $xml .= '  <ows:OperationsMetadata>'."\n";
        foreach (['GetCapabilities', 'DescribeFeatureType', 'GetFeature'] as $op) {
            $xml .= '    <ows:Operation name="'.$op.'">'."\n";
            $xml .= '      <ows:DCP><ows:HTTP><ows:Get xlink:href="'.$escapedUrl.'"/></ows:HTTP></ows:DCP>'."\n";
            $xml .= '    </ows:Operation>'."\n";
        }

        $xml .= '  </ows:OperationsMetadata>'."\n";

        $xml .= '  <wfs:FeatureTypeList>'."\n";
        $xml .= '    <wfs:FeatureType>'."\n";
        $xml .= '      <wfs:Name>'.$name.'</wfs:Name>'."\n";
        $xml .= '      <wfs:Title>'.$title.'</wfs:Title>'."\n";
        $xml .= '      <wfs:DefaultCRS>'.self::DEFAULT_CRS.'</wfs:DefaultCRS>'."\n";
        $xml .= '      <wfs:OutputFormats><wfs:Format>application/gml+xml; version=3.2</wfs:Format></wfs:OutputFormats>'."\n";
        $xml .= '    </wfs:FeatureType>'."\n";
        $xml .= '  </wfs:FeatureTypeList>'."\n";

        $xml .= '</wfs:WFS_Capabilities>'."\n";

        return $xml;
    }//end getCapabilities()

    /**
     * Render an OGC DescribeFeatureType XSD for the case-location feature type.
     *
     * @return string XSD schema XML.
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    public function describeFeatureType(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:gml="http://www.opengis.net/gml/3.2"'
            .' xmlns:procest="http://procest.nl/wfs"'
            .' targetNamespace="http://procest.nl/wfs"'
            .' elementFormDefault="qualified" version="1.0">'."\n";
        $xml .= '  <xsd:import namespace="http://www.opengis.net/gml/3.2"/>'."\n";
        $xml .= '  <xsd:element name="cases" type="procest:casesType"'
            .' substitutionGroup="gml:AbstractFeature"/>'."\n";
        $xml .= '  <xsd:complexType name="casesType">'."\n";
        $xml .= '    <xsd:complexContent>'."\n";
        $xml .= '      <xsd:extension base="gml:AbstractFeatureType">'."\n";
        $xml .= '        <xsd:sequence>'."\n";
        $xml .= '          <xsd:element name="geometry" type="gml:PointPropertyType" minOccurs="0"/>'."\n";

        foreach (['caseId', 'caseIdentifier', 'caseTitle', 'caseStatus', 'caseType', 'formattedAddress'] as $field) {
            $xml .= '          <xsd:element name="'.$field.'" type="xsd:string" minOccurs="0"/>'."\n";
        }

        $xml .= '        </xsd:sequence>'."\n";
        $xml .= '      </xsd:extension>'."\n";
        $xml .= '    </xsd:complexContent>'."\n";
        $xml .= '  </xsd:complexType>'."\n";
        $xml .= '</xsd:schema>'."\n";

        return $xml;
    }//end describeFeatureType()

    /**
     * Render an OGC GetFeature GML 3.2 FeatureCollection of case locations.
     *
     * @param array|null  $bbox        Optional [minLon, minLat, maxLon, maxLat] WGS84 filter.
     * @param int         $maxFeatures Maximum features to return.
     * @param string|null $status      Optional case status filter.
     * @param string|null $caseType    Optional case type filter.
     *
     * @return string wfs:FeatureCollection XML.
     *
     * @spec openspec/specs/gis-integration/spec.md
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function getFeature(
        ?array $bbox=null,
        int $maxFeatures=WfsExportService::DEFAULT_MAX_FEATURES,
        ?string $status=null,
        ?string $caseType=null,
    ): string {
        $collection = $this->wfsExportService->buildFeatureCollection(
            maxFeatures: $maxFeatures,
            bbox: $bbox,
            status: $status,
            caseType: $caseType,
        );

        $features = ($collection['features'] ?? []);
        if (is_array($features) === false) {
            $features = [];
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<wfs:FeatureCollection'
            .' xmlns:wfs="http://www.opengis.net/wfs/2.0"'
            .' xmlns:gml="http://www.opengis.net/gml/3.2"'
            .' xmlns:procest="http://procest.nl/wfs"'
            .' numberMatched="'.count($features).'"'
            .' numberReturned="'.count($features).'"'
            .' timeStamp="'.gmdate('Y-m-d\TH:i:s\Z').'">'."\n";

        foreach ($features as $feature) {
            if (is_array($feature) === false) {
                continue;
            }

            $xml .= $this->renderMember(feature: $feature);
        }

        $xml .= '</wfs:FeatureCollection>'."\n";

        return $xml;
    }//end getFeature()

    /**
     * Render a single wfs:member (GML) from a GeoJSON Point Feature.
     *
     * @param array<string, mixed> $feature The GeoJSON Feature.
     *
     * @return string The wfs:member XML fragment.
     */
    private function renderMember(array $feature): string
    {
        $id    = $this->xmlEscape(value: (string) ($feature['id'] ?? ''));
        $props = ($feature['properties'] ?? []);
        if (is_array($props) === false) {
            $props = [];
        }

        $gmlId = 'case';
        if ($id !== '') {
            $gmlId = $id;
        }

        $coords = ($feature['geometry']['coordinates'] ?? null);
        $lng    = null;
        $lat    = null;
        if (is_array($coords) === true && count($coords) >= 2) {
            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
        }

        $member  = '  <wfs:member>'."\n";
        $member .= '    <procest:cases gml:id="'.$gmlId.'">'."\n";

        if ($lat !== null && $lng !== null) {
            // GML 3.2 uses lat lon ordering for urn:ogc:def:crs:EPSG::4326.
            $member .= '      <procest:geometry>'."\n";
            $member .= '        <gml:Point srsName="'.self::DEFAULT_CRS.'">'."\n";
            $member .= '          <gml:pos>'.$lat.' '.$lng.'</gml:pos>'."\n";
            $member .= '        </gml:Point>'."\n";
            $member .= '      </procest:geometry>'."\n";
        }

        foreach (['caseId', 'caseIdentifier', 'caseTitle', 'caseStatus', 'caseType', 'formattedAddress'] as $field) {
            if (isset($props[$field]) === false) {
                continue;
            }

            $value = (string) $props[$field];
            if ($value === '') {
                continue;
            }

            $member .= '      <procest:'.$field.'>'.$this->xmlEscape(value: $value).'</procest:'.$field.'>'."\n";
        }

        $member .= '    </procest:cases>'."\n";
        $member .= '  </wfs:member>'."\n";

        return $member;
    }//end renderMember()

    /**
     * Escape a string for inclusion in XML text/attributes.
     *
     * @param string $value The raw value.
     *
     * @return string The escaped value.
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, (ENT_QUOTES | ENT_XML1), 'UTF-8');
    }//end xmlEscape()
}//end class
