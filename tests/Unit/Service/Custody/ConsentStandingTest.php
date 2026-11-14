<?php

/**
 * Where one consent stands at a moment: covering, withdrawn, lapsed or not counted.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use DateTimeImmutable;
use OCA\Dossiq\Service\Custody\ConsentStanding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Custody\ConsentStanding
 */
class ConsentStandingTest extends TestCase {

	/**
	 * Consents and the standing each has on 2026-06-15 noon.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function consents(): array {
		return [
			'inside its window' => [['grantedDate' => '2026-01-01', 'validTo' => '2026-12-31'], ConsentStanding::COVERS],
			'no dates at all covers' => [[], ConsentStanding::COVERS],
			'open-ended covers' => [['grantedDate' => '2026-01-01'], ConsentStanding::COVERS],
			'granted later is not counted yet' => [['grantedDate' => '2026-07-01', 'validTo' => '2026-12-31'], ConsentStanding::NOT_COUNTED],
			'expired lapsed' => [['grantedDate' => '2025-01-01', 'validTo' => '2026-06-01'], ConsentStanding::LAPSED],
			'unreadable grant date is not counted' => [['grantedDate' => 'garbage', 'validTo' => '2026-12-31'], ConsentStanding::NOT_COUNTED],
			'unreadable expiry is not counted' => [['grantedDate' => '2026-01-01', 'validTo' => 'not-a-date'], ConsentStanding::NOT_COUNTED],
			'withdrawn true beats the window' => [['withdrawn' => true, 'grantedDate' => '2026-01-01'], ConsentStanding::WITHDRAWN],
			'withdrawn as text' => [['withdrawn' => ' TRUE '], ConsentStanding::WITHDRAWN],
			'withdrawn as 1' => [['withdrawn' => 1], ConsentStanding::WITHDRAWN],
			'withdrawn as "1"' => [['withdrawn' => '1'], ConsentStanding::WITHDRAWN],
			'withdrawn "false" is not withdrawn' => [['withdrawn' => 'false'], ConsentStanding::COVERS],
			'withdrawn "yes" is not a withdrawal' => [['withdrawn' => 'yes'], ConsentStanding::COVERS],
		];
	}//end consents()

	/**
	 * Each consent stands where its dates and withdrawal flag put it.
	 *
	 * @param array<string, mixed> $consent  The consent row.
	 * @param string               $expected The standing.
	 *
	 * @return void
	 */
	#[DataProvider('consents')]
	public function testStandingOf(array $consent, string $expected): void {
		$moment = new DateTimeImmutable('2026-06-15T12:00:00Z');

		self::assertSame($expected, (new ConsentStanding())->standingOf(consent: $consent, moment: $moment));
	}//end testStandingOf()

	public function testTheBoundaryMomentsStillCover(): void {
		$standing = new ConsentStanding();
		$consent = ['grantedDate' => '2026-06-15T12:00:00Z', 'validTo' => '2026-06-15T12:00:00Z'];

		self::assertSame(ConsentStanding::COVERS, $standing->standingOf(consent: $consent, moment: new DateTimeImmutable('2026-06-15T12:00:00Z')));
	}//end testTheBoundaryMomentsStillCover()

	public function testDateOfSeparatesAbsentFromUnreadable(): void {
		$standing = new ConsentStanding();

		self::assertNull($standing->dateOf(value: '   '));
		self::assertFalse($standing->dateOf(value: 'garbage'));
		self::assertSame('2026-03-04', $standing->dateOf(value: ' 2026-03-04 ')->format('Y-m-d'));
	}//end testDateOfSeparatesAbsentFromUnreadable()
}//end class
