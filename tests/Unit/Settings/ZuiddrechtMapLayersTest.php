<?php

/**
 * Example data for DqZaakLocatie: the map layers the location dialog offers
 * beside the map, the PDOK aerial photo and the land-use plans, both shown
 * when the dialog opens.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Zuiddrecht set's map layers.
 */
class ZuiddrechtMapLayersTest extends TestCase {

	/**
	 * The set's wmsLayer objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function layers(): array {
		$set    = json_decode(json: (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), associative: true);
		$layers = array_filter(array: $set['objects'], callback: static fn(array $object): bool => $object['schema'] === 'wmsLayer');

		return array_column(array: $layers, column_key: null, index_key: 'key');
	}//end layers()

	/**
	 * The aerial photo and the land-use plans are offered and shown at once.
	 *
	 * @return void
	 */
	public function testTheDialogOffersTheAerialPhotoAndTheLandUsePlans(): void {
		$layers = $this->layers();

		$this->assertSame(expected: ['kaartlaag-luchtfoto', 'kaartlaag-ruimtelijke-plannen'], actual: array_keys(array: $layers));
		foreach ($layers as $layer) {
			$this->assertSame(expected: 'dossiq', actual: $layer['register']);
			$this->assertSame(expected: 'WMS', actual: $layer['data']['type']);
			$this->assertStringStartsWith(prefix: 'https://service.pdok.nl/', string: $layer['data']['url']);
			$this->assertTrue(condition: $layer['data']['isDefault']);
			$this->assertTrue(condition: $layer['data']['active']);
		}

		$this->assertSame(expected: 'Actueel_orthoHR', actual: $layers['kaartlaag-luchtfoto']['data']['layerName']);
		$this->assertSame(expected: 'enkelbestemming', actual: $layers['kaartlaag-ruimtelijke-plannen']['data']['layerName']);
	}//end testTheDialogOffersTheAerialPhotoAndTheLandUsePlans()
}//end class
