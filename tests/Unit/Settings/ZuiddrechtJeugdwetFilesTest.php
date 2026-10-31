<?php

/**
 * The Jeugdwet example case's four files, as DqSociaalDomein counts them
 * ("Bestanden 4"): the GP's report, the medical report Fatma Kaya opened, the
 * parents' consent and her note of the talk with the parents.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example files
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Jeugdwet case's files.
 */
class ZuiddrechtJeugdwetFilesTest extends TestCase {

	/**
	 * Four confidential documents, each with its file on disk, each linked to the case.
	 *
	 * @return void
	 */
	public function testTheCaseHasFourFiles(): void {
		$dir     = __DIR__.'/../../../lib/Settings/example-sets/';
		$set     = json_decode((string)file_get_contents($dir.'zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$linked = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'zaakinformatieobject' && $object['data']['case'] === 'e0b306d2-c523-5938-9b5c-3474d292dc01') {
				$linked[] = substr($object['data']['informatieobject'], strlen('{{object:'), -strlen('.id}}'));
			}
		}

		$this->assertCount(4, $linked);
		foreach ($linked as $key) {
			$document = $objects[$key];
			$this->assertSame('vertrouwelijk', $document['data']['vertrouwelijkheidaanduiding'], $key);
			$this->assertFileExists($dir.$document['files'][0]['path']);
			$this->assertSame($document['data']['fileName'], basename($document['files'][0]['path']));
		}

		$this->assertContains('Medisch verslag', array_map(static fn (string $key): string => $objects[$key]['data']['title'], $linked));
	}//end testTheCaseHasFourFiles()
}//end class
