<?php

/**
 * InboxCriteria Unit Tests
 *
 * Named filters land on the part of the engine's criteria that owns them
 * (audience, filter, window, anchor, order), and a filter no part knows is
 * passed on, so the engine itself refuses it as an unknown parameter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use DateTime;
use Error;
use OCA\Dossiq\Service\Task\InboxCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InboxCriteria.
 *
 * @covers \OCA\Dossiq\Service\Task\InboxCriteria
 */
final class InboxCriteriaTest extends TestCase {

	/**
	 * Every named filter reaches the criteria through the part that owns it.
	 *
	 * @return void
	 */
	public function testEveryFilterReachesItsPart(): void {
		$due = new DateTime('2026-12-01');

		$criteria = (new InboxCriteria())->forFilters(
			criteria: [
				'uid' => 'behandelaar',
				'groupIds' => ['team-a'],
				'scope' => 'pooled',
				'states' => ['open'],
				'priority' => 'high',
				'dueBefore' => $due,
				'objectUuid' => 'case-1',
				'sort' => 'dueAt',
				'openFirst' => true,
			]
		);

		$this->assertSame(
			expected: ['behandelaar', ['team-a'], 'pooled', ['open'], 'high', $due, 'case-1', 'dueAt', true],
			actual: [
				$criteria->uid,
				$criteria->groupIds,
				$criteria->scope,
				$criteria->states,
				$criteria->priority,
				$criteria->dueBefore,
				$criteria->objectUuid,
				$criteria->sort,
				$criteria->openFirst,
			]
		);
	}//end testEveryFilterReachesItsPart()

	/**
	 * A filter none of the parts knows is passed on, and the criteria refuse it by name.
	 *
	 * @return void
	 */
	public function testAnUnknownFilterIsPassedOnToBeRefused(): void {
		$this->expectException(exception: Error::class);
		$this->expectExceptionMessage(message: 'Unknown named parameter $colour');

		(new InboxCriteria())->forFilters(criteria: ['uid' => 'behandelaar', 'colour' => 'red']);
	}//end testAnUnknownFilterIsPassedOnToBeRefused()
}//end class
