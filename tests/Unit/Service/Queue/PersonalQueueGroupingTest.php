<?php

/**
 * PersonalQueueService: grouping the ordered queue by priority or by term.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueOrdering;
use OCA\Dossiq\Service\Queue\QueueSourceCatalogue;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Grouped by priority, an item without one is "normal"; grouped by term, an item
 * without a deadline tier is "normal"; groups keep the order items arrive in.
 *
 * @covers \OCA\Dossiq\Service\Queue\PersonalQueueService
 * @uses   \OCA\Dossiq\Service\Queue\PersonalQueueRun
 * @uses   \OCA\Dossiq\Service\Queue\QueueItemLifecycle
 */
class PersonalQueueGroupingTest extends TestCase {

	/**
	 * The ordered items the queue groups.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const ORDERED = [
		['id' => 'a', 'source' => 'assignedCases', 'priority' => 'high', 'deadlineTier' => 'overdue'],
		['id' => 'b', 'source' => 'mentions', 'priority' => '  ', 'deadlineTier' => 'overdue'],
		['id' => 'c', 'source' => 'assignedCases', 'priority' => 'high'],
		['id' => 'd', 'source' => 'mentions'],
	];

	/**
	 * The queue for one grouping, with no sources and the ordering answering ORDERED.
	 *
	 * @param string $groupBy The person's grouping.
	 *
	 * @return array<string, mixed> The queue view.
	 */
	private function viewGroupedBy(string $groupBy): array {
		$catalogue = $this->createMock(QueueSourceCatalogue::class);
		$catalogue->method('resolve')->willReturn(['sources' => [], 'unavailable' => [['source' => 'x', 'label' => 'X', 'reason' => 'down']]]);
		$ordering = $this->createMock(QueueOrdering::class);
		$ordering->method('order')->willReturn(self::ORDERED);
		$preferences = $this->createMock(QueueViewPreferences::class);
		$preferences->method('groupBy')->with('anouk')->willReturn($groupBy);
		$preferences->method('hiddenGroups')->with('anouk', '2026-10-20')->willReturn(['mentions']);

		$service = new PersonalQueueService($catalogue, $ordering, $preferences, new NullLogger());

		return $service->forPerson(userId: 'anouk', now: new DateTimeImmutable('2026-10-20 09:00:00'));
	}//end viewGroupedBy()

	/**
	 * The keys and item ids of each group.
	 *
	 * @param array<string, mixed> $view The queue view.
	 *
	 * @return array<string, list<string>>
	 */
	private function shape(array $view): array {
		$shape = [];
		foreach ($view['groups'] as $group) {
			$shape[$group['key']] = array_column($group['items'], 'id');
		}

		return $shape;
	}//end shape()

	/**
	 * By priority: a blank priority is normal.
	 *
	 * @return void
	 */
	public function testByPriorityABlankPriorityIsNormal(): void {
		$view = $this->viewGroupedBy('priority');

		$this->assertSame(['high' => ['a', 'c'], 'normal' => ['b', 'd']], $this->shape($view));
		$this->assertSame('priority', $view['groupBy']);
		$this->assertSame('high', $view['groups'][0]['label']);
		$this->assertSame('', $view['groups'][0]['closesWhen']);
	}//end testByPriorityABlankPriorityIsNormal()

	/**
	 * By term: no deadline tier is normal; the view carries total, hidden groups and what was unavailable.
	 *
	 * @return void
	 */
	public function testByTermNoTierIsNormal(): void {
		$view = $this->viewGroupedBy('due');

		$this->assertSame(['overdue' => ['a', 'b'], 'normal' => ['c', 'd']], $this->shape($view));
		$this->assertSame(4, $view['total']);
		$this->assertSame(['mentions'], $view['hiddenGroups']);
		$this->assertSame([['source' => 'x', 'label' => 'X', 'reason' => 'down']], $view['unavailable']);
	}//end testByTermNoTierIsNormal()

	/**
	 * By source, the default: each source is its own group.
	 *
	 * @return void
	 */
	public function testBySourceEachSourceIsAGroup(): void {
		$this->assertSame(['assignedCases' => ['a', 'c'], 'mentions' => ['b', 'd']], $this->shape($this->viewGroupedBy('source')));
	}//end testBySourceEachSourceIsAGroup()
}//end class
