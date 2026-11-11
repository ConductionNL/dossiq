<?php

/**
 * Characterisation tests for the resultaattype create rules (ztc-002): which
 * external references are fetched, the error for each that cannot be read,
 * and the fields derived from what was read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\BrondatumArchiefValidator;
use OCA\Dossiq\Service\Zgw\ZgwZtcResultaattypeRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ResultaattypeEnrichmentGoldenTest extends TestCase {

	private const SELECTIE = 'https://selectielijst.example/klassen/1';

	private const RTO = 'https://ztc.example/omschrijvingen/2';

	/**
	 * The URLs fetched, in order.
	 *
	 * @var array<int, string>
	 */
	public array $fetched = [];

	private function rules(array $answers): ZgwZtcResultaattypeRules {
		$brondatum = $this->createMock(BrondatumArchiefValidator::class);
		$brondatum->method('validate')->willReturn([]);
		$test = $this;

		return new class(new NullLogger(), $this->createMock(SettingsService::class), new FieldValidator(), $brondatum, $answers, $test) extends ZgwZtcResultaattypeRules {
			public function __construct($logger, $settings, $validator, $brondatum, private array $answers, private ResultaattypeEnrichmentGoldenTest $test) {
				parent::__construct($logger, $settings, $validator, $brondatum);
			}

			protected function fetchExternalUrl(string $url): ?array {
				$this->test->fetched[] = $url;

				return ($this->answers[$url] ?? null);
			}
		};
	}//end rules()

	public function testNothingIsFetchedOrDerivedWithoutReferences(): void {
		$result = $this->rules([])->rulesResultaattypenCreate(['omschrijving' => 'x']);

		self::assertTrue($result['valid']);
		self::assertSame(['omschrijving' => 'x'], $result['enrichedBody']);
		self::assertSame([], $this->fetched);
	}//end testNothingIsFetchedOrDerivedWithoutReferences()

	public function testTheDerivedFieldsAreFilledOnlyWhereTheBodyLeftThemEmpty(): void {
		$answers = [
			self::SELECTIE => ['waardering' => 'blijvend_bewaren', 'bewaartermijn' => 'P10Y'],
			self::RTO => ['omschrijving' => 'Verleend'],
		];
		$result = $this->rules($answers)->rulesResultaattypenCreate(['selectielijstklasse' => self::SELECTIE, 'resultaattypeomschrijving' => [self::RTO, 'ignored']]);

		self::assertSame([self::SELECTIE, self::RTO], array_slice($this->fetched, 0, 2));
		self::assertSame('Verleend', $result['enrichedBody']['omschrijvingGeneriek']);
		self::assertSame('blijvend_bewaren', $result['enrichedBody']['archiefnominatie']);
		self::assertSame('P10Y', $result['enrichedBody']['archiefactietermijn']);

		$kept = $this->rules($answers)->rulesResultaattypenCreate(
			['selectielijstklasse' => self::SELECTIE, 'resultaattypeomschrijving' => self::RTO, 'omschrijvingGeneriek' => 'Eigen', 'archiefnominatie' => 'vernietigen', 'archiefactietermijn' => 'P1Y']
		);
		self::assertSame(['Eigen', 'vernietigen', 'P1Y'], [$kept['enrichedBody']['omschrijvingGeneriek'], $kept['enrichedBody']['archiefnominatie'], $kept['enrichedBody']['archiefactietermijn']]);
	}//end testTheDerivedFieldsAreFilledOnlyWhereTheBodyLeftThemEmpty()

	public function testMissingSourceValuesDeriveNothingButAnEmptyDescription(): void {
		$result = $this->rules([self::SELECTIE => ['other' => 1], self::RTO => []])->rulesResultaattypenCreate(
			['selectielijstklasse' => self::SELECTIE, 'resultaattypeomschrijving' => self::RTO]
		);

		self::assertSame('', $result['enrichedBody']['omschrijvingGeneriek']);
		self::assertArrayNotHasKey('archiefnominatie', $result['enrichedBody']);
		self::assertArrayNotHasKey('archiefactietermijn', $result['enrichedBody']);
	}//end testMissingSourceValuesDeriveNothingButAnEmptyDescription()

	public function testEachUnreadableReferenceIsAnErrorInFieldOrder(): void {
		$both = $this->rules([])->rulesResultaattypenCreate(['selectielijstklasse' => self::SELECTIE, 'resultaattypeomschrijving' => self::RTO]);

		self::assertFalse($both['valid']);
		self::assertSame(400, $both['status']);
		self::assertSame('De selectielijstklasse URL is ongeldig of niet bereikbaar.', $both['detail']);
		self::assertSame(
			[
				['name' => 'selectielijstklasse', 'code' => 'invalid', 'reason' => 'De selectielijstklasse URL is ongeldig of niet bereikbaar.'],
				['name' => 'resultaattypeomschrijving', 'code' => 'invalid', 'reason' => 'De resultaattypeomschrijving URL is ongeldig of niet bereikbaar.'],
			],
			$both['invalidParams']
		);
		self::assertSame([self::SELECTIE, self::RTO], $this->fetched);

		$rto = $this->rules([self::SELECTIE => ['waardering' => 'x']])->rulesResultaattypenCreate(['selectielijstklasse' => self::SELECTIE, 'resultaattypeomschrijving' => [self::RTO]]);
		self::assertSame('De resultaattypeomschrijving URL is ongeldig of niet bereikbaar.', $rto['detail']);
	}//end testEachUnreadableReferenceIsAnErrorInFieldOrder()
}//end class
