<?php

/**
 * Example data for DqMijnWerk's "Mijn archiefbeoordelingen": one open
 * destruction list in OpenRegister's destruction-list register, whose entry
 * for "Melding kapot speeltoestel Oosthavenplein" waits for Pieter Jansen.
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
 * @spec openspec/changes/example-set-archival-review/specs/example-set-archival-review/spec.md#requirement-pieter-has-one-archival-review-waiting
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter's pending archival review.
 *
 * @spec openspec/changes/example-set-archival-review/specs/example-set-archival-review/spec.md#requirement-pieter-has-one-archival-review-waiting
 */
class ZuiddrechtArchivalReviewTest extends TestCase {

	/**
	 * One open destruction list, declared after the case it names, with Pieter's pending entry due in nine days.
	 *
	 * @return void
	 */
	public function testPieterHasOneArchivalReviewWaiting(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$keys  = array_column($set['objects'], 'key');
		$lists = array_values(array_filter($set['objects'], static fn (array $o): bool => $o['register'] === 'destruction-lists'));

		$this->assertCount(expectedCount: 1, haystack: $lists);
		$list = $lists[0];
		$this->assertSame(expected: 'destructionList', actual: $list['schema']);
		$this->assertSame(expected: 'in_review', actual: $list['data']['status']);
		$this->assertGreaterThan(
			expected: array_search('melding-speeltoestel-oosthavenplein', $keys, true),
			actual: array_search($list['key'], $keys, true),
			message: 'the list quotes the case, so it comes after it'
		);

		$this->assertCount(expectedCount: 1, haystack: $list['data']['objects']);
		$entry = $list['data']['objects'][0];
		$this->assertSame(expected: '{{object:melding-speeltoestel-oosthavenplein.id}}', actual: $entry['uuid']);
		$this->assertSame(expected: 'Melding kapot speeltoestel Oosthavenplein', actual: $entry['title']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $entry['reviewer']);
		$this->assertSame(expected: '{{date:+9}}', actual: $entry['archiefactiedatum']);
		$this->assertSame(expected: 'vernietigen', actual: $entry['classification']);
		$this->assertNull(actual: ($entry['decision'] ?? null), message: 'pending');
	}//end testPieterHasOneArchivalReviewWaiting()
}//end class
