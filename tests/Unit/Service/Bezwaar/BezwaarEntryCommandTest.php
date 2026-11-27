<?php

/**
 * BezwaarEntryCommand Unit Tests
 *
 * The deletion of the record an entry describes names that record's uuid,
 * register and schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarEntryCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BezwaarEntryCommand.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarEntryCommand
 */
final class BezwaarEntryCommandTest extends TestCase {

	/**
	 * The deletion names the record's uuid, register and schema.
	 *
	 * @return void
	 */
	public function testTheRecordDeletionNamesTheRecord(): void {
		$command = new BezwaarEntryCommand(
			register: 'dossiq',
			schema: 'hearingSession',
			objectUuid: 'session-1',
			event: 'hearing-scheduled',
			payload: [],
			tag: ''
		);

		$deletion = $command->recordDeletion();

		$this->assertSame(
			expected: ['session-1', 'dossiq', 'hearingSession'],
			actual: [$deletion->uuid, $deletion->target->register, $deletion->target->schema]
		);
	}//end testTheRecordDeletionNamesTheRecord()
}//end class
