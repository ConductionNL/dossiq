<?php

/**
 * The Zuiddrecht example set seeds DqTermijnen's premature notice of default
 * on the case the board draws it on: Kapvergunning Molenweg 4, received
 * while its statutory term still runs.
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
 * @spec openspec/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-premature-notice-of-default-sits-on-kapvergunning-molenweg-4
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The premature notice: on Molenweg 4, inside a running term.
 *
 * @spec openspec/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-premature-notice-of-default-sits-on-kapvergunning-molenweg-4
 */
class ZuiddrechtPrematureNoticeOfDefaultTest extends TestCase {

	/**
	 * The set's objects by key, in order.
	 *
	 * @return array<string, array{index: int, object: array<string, mixed>}> The objects.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);
		$out = [];
		foreach ((array)$set['objects'] as $index => $object) {
			$out[(string)$object['key']] = ['index' => $index, 'object' => $object];
		}

		return $out;
	}//end objects()

	/**
	 * The one premature notice points at Molenweg 4's running statutory term,
	 * received before that term ends, and each object comes after what it names.
	 *
	 * @return void
	 */
	public function testThePrematureNoticeIsOnMolenweg4(): void {
		$objects   = $this->objects();
		$premature = [];
		foreach ($objects as $key => $entry) {
			if (($entry['object']['schema'] ?? '') === 'noticeOfDefault' && ($entry['object']['data']['validityStatus'] ?? '') === 'premaat') {
				$premature[] = $key;
			}
		}

		$this->assertSame(expected: ['ingebrekestelling-kapvergunning-molenweg'], actual: $premature);

		$notice = $objects['ingebrekestelling-kapvergunning-molenweg'];
		$term   = $objects['termijn-kapvergunning-molenweg'];
		$case   = $objects['kapvergunning-molenweg'];
		$this->assertSame(expected: '{{object:termijn-kapvergunning-molenweg.id}}', actual: $notice['object']['data']['deadlineInstance']);
		$this->assertSame(expected: '{{object:kapvergunning-molenweg.id}}', actual: $term['object']['data']['case']);
		$this->assertSame(expected: 'Kapvergunning Molenweg 4', actual: $case['object']['data']['title']);
		$this->assertSame(expected: 'lopend', actual: $term['object']['data']['status']);
		$this->assertGreaterThan(expected: $case['index'], actual: $term['index']);
		$this->assertGreaterThan(expected: $term['index'], actual: $notice['index']);

		$ends     = (int)preg_replace('/\D+/', '', (string)$term['object']['data']['endDateCurrent']);
		$received = (int)preg_replace('/[^\d-]+/', '', (string)$notice['object']['data']['receiptDate']);
		$this->assertGreaterThan(expected: 0, actual: $ends, message: 'the term still runs');
		$this->assertLessThan(expected: 0, actual: $received, message: 'the notice came in before today, inside the term');
	}//end testThePrematureNoticeIsOnMolenweg4()
}//end class
