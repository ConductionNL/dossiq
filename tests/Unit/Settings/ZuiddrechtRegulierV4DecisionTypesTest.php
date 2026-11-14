<?php

/**
 * The decision types of Omgevingsvergunning regulier v4, as DqZaaktypeStatussen
 * draws them: a granted permit is published, a refusal is not.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example decision types
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The regulier v4 decision types.
 */
class ZuiddrechtRegulierV4DecisionTypesTest extends TestCase {

	/**
	 * Granting is published; refusing is not.
	 *
	 * @return void
	 */
	public function testOnlyTheGrantedPermitIsPublished(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertTrue($objects['besluittype-regulier-v4-verleend']['publicationRequired']);
		$this->assertFalse($objects['besluittype-regulier-v4-geweigerd']['publicationRequired']);
	}//end testOnlyTheGrantedPermitIsPublished()
}//end class
