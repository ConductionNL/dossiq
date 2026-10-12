<?php

/**
 * A backfilled template gets a step per open status and a transition to each next status.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Workflow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\BackfilledTemplateShape;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Workflow\BackfilledTemplateShape
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class BackfilledTemplateShapeTest extends TestCase {

	private const STATUSES = [
		['id' => 's-1', 'name' => ['nl' => 'Ontvangen'], 'description' => 'Binnen', 'order' => 1],
		['id' => 's-2', 'name' => 'In behandeling', 'order' => 2],
		['id' => 's-3', 'name' => 'Afgehandeld', 'order' => 3, 'isFinal' => true],
	];

	/**
	 * Every status that is not final becomes a step, named as text, with its own identifier.
	 *
	 * @return void
	 */
	public function testEveryOpenStatusBecomesAStep(): void {
		$steps = (new BackfilledTemplateShape())->steps(statuses: self::STATUSES);

		self::assertSame(['s-1', 's-2'], array_column($steps, 'status'));
		self::assertSame(['Ontvangen', 'In behandeling'], array_column($steps, 'title'));
		self::assertSame('Binnen', $steps[0]['description']);
		self::assertSame([1, 2], array_column($steps, 'order'));
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $steps[0]['id']);
		self::assertNotSame($steps[0]['id'], $steps[1]['id']);
	}//end testEveryOpenStatusBecomesAStep()

	/**
	 * Each status leads to the next, labelled with the name of the status it reaches.
	 *
	 * @return void
	 */
	public function testEachStatusLeadsToTheNext(): void {
		$transitions = (new BackfilledTemplateShape())->transitions(statuses: self::STATUSES);

		self::assertSame(['s-1', 's-2'], array_column($transitions, 'fromStatus'));
		self::assertSame(['s-2', 's-3'], array_column($transitions, 'toStatus'));
		self::assertSame(['In behandeling', 'Afgehandeld'], array_column($transitions, 'label'));
		self::assertSame([], (new BackfilledTemplateShape())->transitions(statuses: [self::STATUSES[0]]));
	}//end testEachStatusLeadsToTheNext()

	/**
	 * A status without a name falls back to a plain word, never to "Array".
	 *
	 * @return void
	 */
	public function testANamelessStatusFallsBack(): void {
		$shape = new BackfilledTemplateShape();

		self::assertSame('Stap', $shape->steps(statuses: [['id' => 's-1']])[0]['title']);
		self::assertSame('Door', $shape->transitions(statuses: [['id' => 's-1'], ['id' => 's-2']])[0]['label']);
	}//end testANamelessStatusFallsBack()
}//end class
