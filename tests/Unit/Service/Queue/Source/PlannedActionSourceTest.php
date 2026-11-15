<?php

/**
 * PlannedActionSource tests.
 *
 * Pins how a planned action becomes a queue item: its label or else its
 * type, the day it is planned for or none, and a route to its case or to
 * the personal queue when it is on none.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Service\Queue\Source\PlannedActionSource;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PlannedActionSource::itemsFor().
 */
class PlannedActionSourceTest extends TestCase {
	/**
	 * Each planned action of the person becomes one item, in order.
	 *
	 * @return void
	 */
	public function testEachPlannedActionBecomesAnItem(): void {
		$actions = $this->createMock(PlannedActionService::class);
		$actions->method('ownedBy')->with('anouk')->willReturn(
			[
				['id' => 'pa-1', 'case' => 'case-1', 'label' => ' Bel terug ', 'actionType' => 'call', 'plannedFor' => '2026-10-20T09:00:00+02:00'],
				['uuid' => 'pa-2', 'label' => '', 'actionType' => ' visit '],
			]
		);
		$source = new PlannedActionSource($actions, $this->createMock(IL10N::class));

		$items = $source->itemsFor('anouk');

		self::assertCount(2, $items);
		self::assertSame(
			['planned-actions', 'plannedAction', 'pa-1', 'Bel terug', '', '2026-10-20', null, ['name' => 'CaseDetail', 'params' => ['id' => 'case-1']]],
			[$items[0]->source, $items[0]->subjectType, $items[0]->subjectId, $items[0]->title, $items[0]->priority, $items[0]->dueAt, $items[0]->coveredFor, $items[0]->route]
		);
		self::assertSame(
			['pa-2', 'visit', null, ['name' => 'PersonalQueue']],
			[$items[1]->subjectId, $items[1]->title, $items[1]->dueAt, $items[1]->route]
		);
		self::assertSame([], $source->itemsFor(''));
	}//end testEachPlannedActionBecomesAnItem()
}//end class
