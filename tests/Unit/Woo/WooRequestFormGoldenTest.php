<?php

/**
 * Characterisation tests for WooRequestForm::normalise: the `wooRequest` it
 * keeps, and every refusal with its reason, detail and field, in the order the
 * checks run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Woo\WooRequestForm;
use OCA\Dossiq\Woo\WooRequestRefused;
use PHPUnit\Framework\TestCase;

class WooRequestFormGoldenTest extends TestCase {

	private const VALID = [
		'subjectRef' => ' res-1 ',
		'onderwerp' => ' Bomenkap ',
		'origin' => 'portal',
	];

	/**
	 * The refusal normalise throws.
	 *
	 * @param array $request The request.
	 *
	 * @return array{0: string, 1: string, 2: string} Reason, detail and field.
	 */
	private function refusal(array $request): array {
		try {
			(new WooRequestForm())->normalise($request);
		} catch (WooRequestRefused $e) {
			return [$e->getReason(), $e->getDetail(), $e->getField()];
		}

		self::fail('The request was accepted.');

		return [];
	}//end refusal()

	public function testAFullRequestIsKeptInItsOwnOrder(): void {
		$kept = (new WooRequestForm())->normalise(
			[
				'subjectRef' => 'res-1',
				'onderwerp' => 'Bomenkap',
				'omschrijving' => ' Alles over de bomen ',
				'origin' => 'pipelinq',
				'periodeVan' => '1-3-2026',
				'periodeTot' => '2026-10-14',
				'originReference' => 7,
				'collectionId' => 'col',
				'toelichting' => 'T',
				'verzoekerNaam' => 'Anna',
				'verzoekerEmail' => 'anna@example.org',
				'verzoekerType' => 'inwoner',
				'verzoekerTelefoon' => '06',
				'verzoekerAdres' => 'Straat 1',
				'documentSoorten' => ['besluiten'],
				'extra' => 'dropped',
			]
		);

		self::assertSame(
			[
				'onderwerp' => 'Bomenkap',
				'omschrijving' => 'Alles over de bomen',
				'periodeVan' => '2026-03-01',
				'periodeTot' => '2026-10-14',
				'origin' => 'pipelinq',
				'originReference' => '7',
				'collectionId' => 'col',
				'toelichting' => 'T',
				'verzoekerNaam' => 'Anna',
				'verzoekerEmail' => 'anna@example.org',
				'verzoekerType' => 'burger',
				'verzoekerTelefoon' => '06',
				'verzoekerAdres' => 'Straat 1',
				'documentSoorten' => ['besluiten'],
			],
			$kept
		);
	}//end testAFullRequestIsKeptInItsOwnOrder()

	public function testAMinimalRequestLeavesThePeriodsOut(): void {
		$kept = (new WooRequestForm())->normalise(self::VALID + ['subjectRef' => ['not', 'text'], 'periodeVan' => '']);

		self::assertSame('Bomenkap', $kept['onderwerp']);
		self::assertArrayNotHasKey('periodeVan', $kept);
		self::assertArrayNotHasKey('periodeTot', $kept);
		self::assertSame(
			['onderwerp' => 'Bomenkap', 'omschrijving' => '', 'origin' => 'portal', 'originReference' => '', 'collectionId' => ''],
			$kept
		);
	}//end testAMinimalRequestLeavesThePeriodsOut()

	public function testASubjectlessOriginNeedsNoResident(): void {
		foreach (['portal-form', 'opencatalogi'] as $origin) {
			$kept = (new WooRequestForm())->normalise(['onderwerp' => 'X', 'origin' => $origin]);
			self::assertSame($origin, $kept['origin']);
		}
	}//end testASubjectlessOriginNeedsNoResident()

	public function testEachRefusalInTheOrderTheChecksRun(): void {
		$cases = [
			[[], ['invalid', 'The origin must be one of: portal, pipelinq, portal-form, opencatalogi.', '']],
			[['origin' => 'mail', 'onderwerp' => ''], ['invalid', 'The origin must be one of: portal, pipelinq, portal-form, opencatalogi.', '']],
			[['origin' => 'portal', 'onderwerp' => ''], ['invalid', 'The request names no resident.', '']],
			[['origin' => 'portal', 'subjectRef' => 'r'], ['invalid', 'Say what the request is about.', 'onderwerp']],
			[self::VALID + ['periodeVan' => '2026-05-01', 'periodeTot' => '2026-04-30'], ['invalid', 'The end date lies before the start date.', 'periodeTot']],
			[self::VALID + ['verzoekerEmail' => 'geen', 'verzoekerType' => 'robot', 'documentSoorten' => ['x']], ['invalid', 'Fill in an e-mail address, for example naam@voorbeeld.nl.', 'verzoekerEmail']],
		];
		foreach ($cases as [$request, $expected]) {
			self::assertSame($expected, $this->refusal($request));
		}
	}//end testEachRefusalInTheOrderTheChecksRun()

	public function testABadDateAndTheLaterFieldsRefuseAsBefore(): void {
		$date = $this->refusal(self::VALID + ['periodeVan' => 'gisteren', 'periodeTot' => 'morgen']);
		self::assertSame('periodeVan', $date[2]);

		$kind = $this->refusal(self::VALID + ['verzoekerType' => 'robot', 'documentSoorten' => ['x']]);
		self::assertSame('verzoekerType', $kind[2]);

		$kinds = $this->refusal(self::VALID + ['documentSoorten' => ['x']]);
		self::assertSame('documentSoorten', $kinds[2]);
	}//end testABadDateAndTheLaterFieldsRefuseAsBefore()
}//end class
