<?php

/**
 * Tests for the zrc-021 archival base date resolver.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Archival;

use OCA\Dossiq\Service\Archival\ArchivalBaseDateResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each afleidingswijze resolves its base date, falling back to the case end date.
 *
 * @covers \OCA\Dossiq\Service\Archival\ArchivalBaseDateResolver
 * @covers \OCA\Dossiq\Service\Archival\ReadsConfiguredRows
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ArchivalBaseDateResolverTest extends TestCase {

	/**
	 * The parent case's UUID.
	 *
	 * @var string
	 */
	private const PARENT = '11111111-2222-3333-4444-555555555555';

	/**
	 * The case end date every fallback answers.
	 *
	 * @var string
	 */
	private const END = '2026-09-08';

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The configuration the resolver reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'case_property_schema' => 'caseProperty',
		'decision_schema' => 'decision',
	];

	/**
	 * Build the in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
	}//end setUp()

	/**
	 * The resolver under test, over the given object service.
	 *
	 * @param object|null $objectService The object service; the in-memory register when omitted.
	 * @param bool        $withService   Whether OpenRegister is available at all.
	 *
	 * @return ArchivalBaseDateResolver
	 */
	private function resolver(?object $objectService = null, bool $withService = true): ArchivalBaseDateResolver {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($withService === true ? ($objectService ?? $this->register) : null));
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new ArchivalBaseDateResolver(settingsService: $settings);
	}//end resolver()

	/**
	 * The end-date methods answer the end date, without reading anything.
	 *
	 * @return void
	 */
	public function testEndDateMethodsAnswerTheEndDate(): void {
		$resolver = $this->resolver(withService: false);

		foreach (['afgehandeld', 'handled', 'termijn'] as $method) {
			$this->assertSame(self::END, $resolver->resolve(method: $method, endDate: self::END, case: [], brondatum: []), $method);
		}
	}//end testEndDateMethodsAnswerTheEndDate()

	/**
	 * An external or unknown method resolves to null rather than a guess.
	 *
	 * @return void
	 */
	public function testExternalAndUnknownMethodsAnswerNull(): void {
		$resolver = $this->resolver();

		$this->assertNull($resolver->resolve(method: 'ander_datumkenmerk', endDate: self::END, case: [], brondatum: []));
		$this->assertNull($resolver->resolve(method: 'gerelateerde_zaak', endDate: self::END, case: [], brondatum: []));
		$this->assertNull($resolver->resolve(method: '', endDate: self::END, case: [], brondatum: []));
	}//end testExternalAndUnknownMethodsAnswerNull()

	/**
	 * Hoofdzaak reads the parent named by a ZGW URL under any of its field names.
	 *
	 * @return void
	 */
	public function testHoofdzaakReadsTheParentFromAUrlInAnyField(): void {
		$this->register->seed(schema: 'case', uuid: self::PARENT, row: ['endDate' => '2020-06-30T12:00:00+00:00']);
		$url = 'https://zaken.test/api/v1/zaken/' . self::PARENT;

		foreach (['parentCase', 'mainCase', 'hoofdzaak'] as $field) {
			$this->assertSame(
				'2020-06-30',
				$this->resolver()->resolve(method: 'hoofdzaak', endDate: self::END, case: [$field => $url], brondatum: []),
				$field
			);
		}
	}//end testHoofdzaakReadsTheParentFromAUrlInAnyField()

	/**
	 * Hoofdzaak without a usable parent, or a parent without an end date, falls back.
	 *
	 * @return void
	 */
	public function testHoofdzaakFallsBackToTheEndDate(): void {
		$this->register->seed(schema: 'case', uuid: self::PARENT, row: ['endDate' => '']);

		$resolver = $this->resolver();
		$this->assertSame(self::END, $resolver->resolve(method: 'hoofdzaak', endDate: self::END, case: [], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => 'not-a-uuid'], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => self::PARENT], brondatum: []));
		$this->assertSame(
			self::END,
			$resolver->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], brondatum: [])
		);
	}//end testHoofdzaakFallsBackToTheEndDate()

	/**
	 * Eigenschap reads the named property of this case.
	 *
	 * @return void
	 */
	public function testEigenschapReadsTheNamedCaseProperty(): void {
		$this->register->seed(schema: 'caseProperty', uuid: 'p-1', row: ['case' => 'case-2', 'name' => 'vervaldatum', 'value' => '2030-01-01']);
		$this->register->seed(schema: 'caseProperty', uuid: 'p-2', row: ['case' => 'case-1', 'name' => 'andere', 'value' => '2031-01-01']);
		$this->register->seed(schema: 'caseProperty', uuid: 'p-3', row: ['case' => 'case-1', 'name' => 'vervaldatum', 'value' => '2029-03-04']);

		$resolver = $this->resolver();
		$this->assertSame(
			'2029-03-04',
			$resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['id' => 'case-1'], brondatum: ['objectAttribute' => 'vervaldatum'])
		);
		$this->assertSame(
			'2029-03-04',
			$resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['@self' => ['id' => 'case-1']], brondatum: ['datumkenmerk' => 'vervaldatum'])
		);
	}//end testEigenschapReadsTheNamedCaseProperty()

	/**
	 * Eigenschap without an attribute, a case id, a property or a date value falls back.
	 *
	 * @return void
	 */
	public function testEigenschapFallsBackToTheEndDate(): void {
		$this->register->seed(schema: 'caseProperty', uuid: 'p-1', row: ['case' => 'case-1', 'name' => 'kenmerk', 'value' => 'geen datum']);

		$resolver = $this->resolver();
		$this->assertSame(self::END, $resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['id' => 'case-1'], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'eigenschap', endDate: self::END, case: [], brondatum: ['objectAttribute' => 'kenmerk']));
		$this->assertSame(self::END, $resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['id' => 'case-1'], brondatum: ['objectAttribute' => 'missing']));
		$this->assertSame(self::END, $resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['id' => 'case-1'], brondatum: ['objectAttribute' => 'kenmerk']));
	}//end testEigenschapFallsBackToTheEndDate()

	/**
	 * The decision methods read the first decision field that carries a date, in field order.
	 *
	 * @return void
	 */
	public function testDecisionMethodsReadTheFirstDatedDecisionField(): void {
		$this->register->seed(schema: 'decision', uuid: 'd-0', row: ['case' => 'case-9', 'effectiveDate' => '2010-01-01', 'expiryDate' => '2011-01-01']);
		$this->register->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'case-1', 'effectiveDate' => '', 'ingangsdatum' => '2027-02-01', 'vervaldatum' => '2028-02-01']);
		$this->register->seed(schema: 'decision', uuid: 'd-2', row: ['case' => 'case-1', 'effectiveDate' => '2027-05-01', 'expiryDate' => '2029-05-01']);

		$resolver = $this->resolver();
		$this->assertSame(
			'2027-02-01',
			$resolver->resolve(method: 'ingangsdatum_besluit', endDate: self::END, case: ['id' => 'case-1'], brondatum: [])
		);
		$this->assertSame(
			'2028-02-01',
			$resolver->resolve(method: 'vervaldatum_besluit', endDate: self::END, case: ['id' => 'case-1'], brondatum: [])
		);
	}//end testDecisionMethodsReadTheFirstDatedDecisionField()

	/**
	 * The decision methods fall back without a case id or without a dated decision.
	 *
	 * @return void
	 */
	public function testDecisionMethodsFallBackToTheEndDate(): void {
		$this->register->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'case-1', 'effectiveDate' => 'nooit']);

		$resolver = $this->resolver();
		$this->assertSame(self::END, $resolver->resolve(method: 'ingangsdatum_besluit', endDate: self::END, case: [], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'ingangsdatum_besluit', endDate: self::END, case: ['id' => 'case-1'], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'vervaldatum_besluit', endDate: self::END, case: ['id' => 'case-2'], brondatum: []));
	}//end testDecisionMethodsFallBackToTheEndDate()

	/**
	 * Without OpenRegister or a configured schema, every read method falls back.
	 *
	 * @return void
	 */
	public function testUnconfiguredReadsFallBackToTheEndDate(): void {
		$this->register->seed(schema: 'case', uuid: self::PARENT, row: ['endDate' => '2020-06-30']);

		$this->assertSame(
			self::END,
			$this->resolver(withService: false)->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => self::PARENT], brondatum: [])
		);

		$this->config['case_schema'] = '';
		$this->assertSame(
			self::END,
			$this->resolver()->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => self::PARENT], brondatum: [])
		);
	}//end testUnconfiguredReadsFallBackToTheEndDate()

	/**
	 * A register that fails on read or search costs the derived date, not the resolution.
	 *
	 * @return void
	 */
	public function testAFailingRegisterFallsBackToTheEndDate(): void {
		$failing = new class {
			/**
			 * Fail every find.
			 *
			 * @return never
			 */
			public function find(): never {
				throw new RuntimeException('register down');
			}

			/**
			 * Fail every search.
			 *
			 * @return never
			 */
			public function searchObjectsBySlug(): never {
				throw new RuntimeException('register down');
			}
		};

		$resolver = $this->resolver(objectService: $failing);
		$this->assertSame(self::END, $resolver->resolve(method: 'hoofdzaak', endDate: self::END, case: ['parentCase' => self::PARENT], brondatum: []));
		$this->assertSame(self::END, $resolver->resolve(method: 'ingangsdatum_besluit', endDate: self::END, case: ['id' => 'case-1'], brondatum: []));
		$this->assertSame(
			self::END,
			$resolver->resolve(method: 'eigenschap', endDate: self::END, case: ['id' => 'case-1'], brondatum: ['objectAttribute' => 'x'])
		);
	}//end testAFailingRegisterFallsBackToTheEndDate()

}//end class
