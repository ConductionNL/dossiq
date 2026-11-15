<?php

/**
 * Example data for DqDocumentenBeheer: Kapvergunning essen Parkweg has four
 * files, each linked to the case and shipped beside the fragment.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Parkweg permit's documents.
 */
class ZuiddrechtParkwegDocumentsTest extends TestCase {

	/**
	 * Four documents in name order, each file present, each linked to the Parkweg case; the quote is restricted.
	 *
	 * @return void
	 */
	public function testTheCaseHasItsFourDocuments(): void {
		$dir     = __DIR__.'/../../../lib/Settings/example-sets/';
		$set     = json_decode((string)file_get_contents($dir.'zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$names = [];
		foreach ($objects as $object) {
			if ($object['schema'] !== 'zaakinformatieobject' || $object['data']['case'] !== '{{object:kapvergunning-essen-parkweg.id}}') {
				continue;
			}

			preg_match('/^\{\{object:(.+)\.id\}\}$/', $object['data']['informatieobject'], $match);
			$document = $objects[$match[1]];
			$this->assertFileExists(filename: $dir.$document['files'][0]['path']);
			$this->assertSame(expected: basename($document['files'][0]['path']), actual: $document['data']['fileName']);
			$names[$document['data']['fileName']] = $document['data']['vertrouwelijkheidaanduiding'];
		}

		$this->assertSame(
			expected: [
				'Aanvraag kapvergunning.pdf' => 'openbaar',
				'Advies Groenbeheer.docx'    => 'openbaar',
				'Offerte herplant essen.pdf' => 'vertrouwelijk',
				"Situatiefoto's essen.zip"   => 'openbaar',
			],
			actual: $names
		);
	}//end testTheCaseHasItsFourDocuments()

	/**
	 * Board DqDocumentenBeheer: the quote is for Pieter Jansen and team Vergunningen only, with its reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
	 */
	public function testTheQuoteDeclaresItsAccessException(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$this->assertSame(
			expected: [
				'users'  => ['{{cast:pieter.uid}}'],
				'groups' => ['zuiddrecht-vergunningen'],
				'reason' => 'Bedrijfsgevoelige prijzen',
				'setBy'  => '{{cast:pieter.uid}}',
				'setAt'  => '{{datetime:-10 10:12}}',
			],
			actual: $objects['parkweg-doc-offerte-herplant']['data']['accessException'] ?? null
		);
	}//end testTheQuoteDeclaresItsAccessException()
}//end class
