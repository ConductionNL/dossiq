<?php

/**
 * Every contact moment of the Zuiddrecht set names the person or company it
 * is about (`contact`), the same record its case names as requester, so the
 * contact column and the contact's own history are filled.
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
 *
 * @spec openspec/specs/example-set-contact-moments-name-their-contact/spec.md#requirement-a-contact-moment-names-its-contact
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Contact moments carry their contact.
 *
 * @spec openspec/specs/example-set-contact-moments-name-their-contact/spec.md#requirement-a-contact-moment-names-its-contact
 */
class ZuiddrechtContactMomentsNameTheirContactTest extends TestCase {

	/**
	 * Each contact moment on a case names that case's requester as its contact.
	 *
	 * @return void
	 */
	public function testEveryContactMomentNamesItsCasesRequester(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$moments = array_filter($objects, static fn (array $object): bool => $object['schema'] === 'contactmoment');
		$this->assertNotEmpty(actual: $moments);
		foreach ($moments as $key => $moment) {
			$caseKey = substr($moment['data']['case'], strlen('{{object:'), -strlen('.id}}'));
			$this->assertSame(expected: $objects[$caseKey]['data']['requester'], actual: ($moment['data']['contact'] ?? null), message: $key);
		}
	}//end testEveryContactMomentNamesItsCasesRequester()
}//end class
