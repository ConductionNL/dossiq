<?php

/**
 * The case history's chips as DqZaakHistorie draws them: Status, Documenten,
 * Berichten, Taken and Toewijzing are declared timeline kinds, each with the
 * field that says what happened.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Service\Timeline
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Service\Timeline\TimelineKinds;
use PHPUnit\Framework\TestCase;

/**
 * The five history chips are declared kinds.
 */
class TimelineKindsHistoryChipsTest extends TestCase {

	/**
	 * Each chip is a declared kind with the board's title and its required field.
	 *
	 * @return void
	 */
	public function testTheHistoryChipsAreDeclaredKinds(): void {
		$bySlug = array_column(TimelineKinds::DECLARATIONS, null, 'slug');
		$chips  = [
			TimelineKinds::STATUS_CHANGE => ['Status', 'to'],
			TimelineKinds::DOCUMENT      => ['Documenten', 'act'],
			TimelineKinds::MESSAGE       => ['Berichten', 'direction'],
			TimelineKinds::TASK          => ['Taken', 'act'],
			TimelineKinds::ASSIGNMENT    => ['Toewijzing', 'act'],
		];
		foreach ($chips as $slug => [$title, $required]) {
			$this->assertArrayHasKey(key: $slug, array: $bySlug);
			$this->assertSame(expected: $title, actual: $bySlug[$slug]['title']);
			$this->assertSame(expected: [$required], actual: $bySlug[$slug]['required']);
			$this->assertFalse(condition: $bySlug[$slug]['followUp']);
		}
	}//end testTheHistoryChipsAreDeclaredKinds()

	/**
	 * Every slug is declared once.
	 *
	 * @return void
	 */
	public function testEverySlugIsDeclaredOnce(): void {
		$slugs = array_column(TimelineKinds::DECLARATIONS, 'slug');
		$this->assertSame(expected: count($slugs), actual: count(array_unique($slugs)));
	}//end testEverySlugIsDeclaredOnce()
}//end class
