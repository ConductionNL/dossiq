<?php

/**
 * Unit tests for TriageChannel.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\TriageChannel;
use PHPUnit\Framework\TestCase;

/**
 * A triage item names the channel it came in on, and the queue narrows to one.
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
class TriageChannelTest extends TestCase {

	/**
	 * A mail entry carries no channel of its own and is e-mail; a channel entry keeps its own.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAChannelCameInByEmail(): void {
		$items = (new TriageChannel())->annotate(
			items: [
				['id' => 'm1', 'mailMessageId' => '<a@b>'],
				['id' => 'p1', 'channel' => 'portal'],
				['id' => 'w1', 'channel' => '  '],
			]
		);

		$this->assertSame(expected: ['email', 'portal', 'email'], actual: array_column($items, 'channel'));
	}//end testAnEntryWithoutAChannelCameInByEmail()

	/**
	 * Narrowing keeps the items of the asked channel, and of several when the list sends a comma list.
	 *
	 * @return void
	 */
	public function testNarrowingKeepsTheItemsOfTheAskedChannels(): void {
		$channels = new TriageChannel();
		$items = $channels->annotate(
			items: [
				['id' => 'm1'],
				['id' => 'p1', 'channel' => 'portal'],
				['id' => 'w1', 'channel' => 'webform'],
			]
		);

		$this->assertSame(
			expected: ['p1'],
			actual: array_column($channels->narrow(items: $items, channel: 'portal'), 'id')
		);
		$this->assertSame(
			expected: ['m1', 'w1'],
			actual: array_column($channels->narrow(items: $items, channel: 'Email, webform'), 'id')
		);
	}//end testNarrowingKeepsTheItemsOfTheAskedChannels()

	/**
	 * No channel, or "all", keeps the whole queue: the default lens is every channel.
	 *
	 * @return void
	 */
	public function testNoChannelOrAllKeepsEveryItem(): void {
		$channels = new TriageChannel();
		$items = $channels->annotate(items: [['id' => 'm1'], ['id' => 'p1', 'channel' => 'portal']]);

		$this->assertCount(expectedCount: 2, haystack: $channels->narrow(items: $items, channel: ''));
		$this->assertCount(expectedCount: 2, haystack: $channels->narrow(items: $items, channel: 'all'));
	}//end testNoChannelOrAllKeepsEveryItem()

	/**
	 * The facet counts each channel, the largest first.
	 *
	 * @return void
	 */
	public function testTheFacetCountsEachChannel(): void {
		$channels = new TriageChannel();
		$items = $channels->annotate(
			items: [
				['id' => 'm1'],
				['id' => 'p1', 'channel' => 'portal'],
				['id' => 'm2'],
			]
		);

		$this->assertSame(
			expected: [['value' => 'email', 'count' => 2], ['value' => 'portal', 'count' => 1]],
			actual: $channels->facet(items: $items)
		);
	}//end testTheFacetCountsEachChannel()
}//end class
