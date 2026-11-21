<?php

/**
 * Unit tests for PlannedEventFields.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Calendar;

use OCA\Dossiq\Service\Calendar\PlannedEventFields;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The start is read or refused with a warning; the description names what is known.
 *
 * @covers \OCA\Dossiq\Service\Calendar\PlannedEventFields
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PlannedEventFieldsTest extends TestCase {

	/**
	 * A readable date is the start; an unreadable one is none, and says so once.
	 *
	 * @return void
	 */
	public function testTheStartIsReadOrRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Hearing has no readable date, no calendar event written: next tuesday-ish',
			['app' => 'dossiq']
		);
		$fields = new PlannedEventFields(logger: $logger);

		self::assertSame(expected: '2026-12-01 10:30', actual: $fields->start(data: ['date' => '2026-12-01T10:30:00'])?->format('Y-m-d H:i'));
		self::assertNull(actual: $fields->start(data: ['date' => 'next tuesday-ish']));
	}//end testTheStartIsReadOrRefused()

	/**
	 * Only the parts the data holds are written, and only text participants are named, trimmed.
	 *
	 * @return void
	 */
	public function testTheDescriptionNamesWhatIsKnown(): void {
		$fields = new PlannedEventFields(logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertSame(
			expected: "Hoorgesprek over klacht K-1\nVorm: fysiek\nGespreksruimte: https://talk/r\nDeelnemers: Anna, Bob",
			actual: $fields->description(
				data: ['complaint' => 'K-1', 'type' => 'fysiek', 'talkRoomUrl' => 'https://talk/r'],
				participants: [' Anna ', '', ['uid' => 'x'], 'Bob', 7]
			)
		);
		self::assertSame(expected: 'Hoorgesprek over klacht ', actual: $fields->description(data: [], participants: []));
	}//end testTheDescriptionNamesWhatIsKnown()
}//end class
