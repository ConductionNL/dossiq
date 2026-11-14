<?php

/**
 * ContactMomentPayload on create with an end time: the duration is measured, never negative.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\ContactMomentPayload;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactMomentPayload's create-time duration.
 *
 * @covers \OCA\Dossiq\Service\Kcc\ContactMomentPayload
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class ContactMomentPayloadDurationTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The payload builder, its clock frozen.
	 *
	 * @return ContactMomentPayload
	 */
	private function payload(): ContactMomentPayload {
		return new ContactMomentPayload(dates: $this->caseDatesFrozenAt('2026-10-12T10:00:00+02:00'));
	}//end payload()

	/**
	 * A create with a start and an end stores both and the seconds between them.
	 *
	 * @return void
	 */
	public function testACreateWithAnEndMeasuresTheDuration(): void {
		$payload = $this->payload()->forCreate(
			['channel' => 'phone', 'startedAt' => '2026-10-12T08:00:00Z', 'endedAt' => '2026-10-12T08:07:30Z'],
			'agent1'
		);

		self::assertArrayHasKey('endedAt', $payload);
		self::assertSame(450, $payload['durationSeconds']);
	}//end testACreateWithAnEndMeasuresTheDuration()

	/**
	 * An end before the start is a zero duration, not a negative one.
	 *
	 * @return void
	 */
	public function testAnEndBeforeTheStartIsZero(): void {
		$payload = $this->payload()->forCreate(
			['channel' => 'phone', 'startedAt' => '2026-10-12T08:10:00Z', 'endedAt' => '2026-10-12T08:00:00Z'],
			'agent1'
		);

		self::assertSame(0, $payload['durationSeconds']);
	}//end testAnEndBeforeTheStartIsZero()

	/**
	 * Without an end, a create has no duration at all.
	 *
	 * @return void
	 */
	public function testACreateWithoutAnEndHasNoDuration(): void {
		$payload = $this->payload()->forCreate(['channel' => 'phone'], 'agent1');

		self::assertArrayNotHasKey('endedAt', $payload);
		self::assertArrayNotHasKey('durationSeconds', $payload);
	}//end testACreateWithoutAnEndHasNoDuration()
}//end class
