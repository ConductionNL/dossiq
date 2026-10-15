<?php

/**
 * StufProviderQueryListener answers integriq's StUF provider events from dossiq's cases.
 *
 * Real CaseObjectReader and the real integriq event classes (or their verbatim
 * stubs); OpenRegister's ObjectService is the double, and it answers only the
 * filters a real case schema declares.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\StufProviderQueryListener;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\StufProviderQueryListener
 * @uses \OCA\Dossiq\Service\Deelzaak\CaseObjectReader
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class StufProviderQueryListenerTest extends TestCase {

	/**
	 * The case type the mapped case is of, as the caseType schema stores it:
	 * known to the mapping by its catalogue identifier.
	 *
	 * @var array<string, string>
	 */
	public const CASE_TYPE = ['identifier' => 'kapvergunning', 'title' => 'Kapvergunning'];

	/**
	 * The endpoint's case type mapping.
	 *
	 * @var array<string, string>
	 */
	private array $mappings = ['kapvergunning' => 'Omgevingsvergunning kap'];

	/**
	 * The stored cases.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cases = [];

	/**
	 * Whether the case store fails.
	 *
	 * @var bool
	 */
	private bool $storeBroken = false;

	/**
	 * The filters the case store was asked with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * Seed two cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->storeBroken = false;
		$this->asked       = [];
		$this->cases       = [
			[
				'identifier' => '2026-0042',
				'title' => 'Kapvergunning Dorpsstraat',
				'startDate' => '2026-10-01T09:30:00+02:00',
				'endDate' => '',
				'caseType' => 'ct-uuid-1',
				'initiatorType' => 'person',
				'initiatorSourceId' => '999993653',
				'initiatorDisplayName' => 'J. Jansen',
			],
			['identifier' => '2026-0043', 'title' => 'Andere zaak', 'caseType' => 'ct-uuid-2', 'initiatorType' => 'company', 'initiatorSourceId' => '12345678'],
		];
	}//end setUp()

	/**
	 * A zakLv01 by identificatie is answered with the case and its mapped zaaktype.
	 *
	 * @return void
	 */
	public function testACaseQueryIsAnsweredWithTheMappedZaaktype(): void {
		$event = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$this->listener()->handle($event);

		$this->assertTrue($event->isAnswered());
		$this->assertSame('dossiq', $event->getAnsweredBy());
		$this->assertSame(
			[['zaakidentificatie' => '2026-0042', 'omschrijving' => 'Kapvergunning Dorpsstraat', 'startdatum' => '20261001', 'zaaktype' => 'Omgevingsvergunning kap']],
			$event->getAnswer()['cases']
		);
		$this->assertSame('2026-0042', $this->asked[0]['identifier']);
		$this->assertSame('case', $this->asked[0]['schema']);
	}//end testACaseQueryIsAnsweredWithTheMappedZaaktype()

	/**
	 * The case type row the double returns is one the shipped schema accepts,
	 * and every key on it is declared. The first version of this double carried
	 * a `slug`, which the caseType schema does not declare, so the listener's
	 * lookup passed here and named no zaaktype on a real instance.
	 *
	 * @return void
	 */
	public function testTheCaseTypeFixtureIsARowTheShippedSchemaDeclares(): void {
		$validator = new RealSchemaValidator();
		$declared  = array_keys((array)$validator->schemas['caseType']['properties']);

		$this->assertSame([], array_values(array_diff(array_keys(self::CASE_TYPE), $declared)), 'Every key on the fixture is a declared caseType property');
		$this->assertSame([], $validator->errors(slug: 'caseType', payload: self::CASE_TYPE, creating: false));
	}//end testTheCaseTypeFixtureIsARowTheShippedSchemaDeclares()

	/**
	 * A mapping keyed on the case type's uuid names the zaaktype too.
	 *
	 * @return void
	 */
	public function testAMappingByUuidNamesTheZaaktype(): void {
		$this->mappings = ['ct-uuid-1' => 'Kap op uuid'];
		$event          = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$this->listener()->handle($event);

		$this->assertSame('Kap op uuid', $event->getAnswer()['cases'][0]['zaaktype']);
	}//end testAMappingByUuidNamesTheZaaktype()

	/**
	 * No such case is an answered, empty list: dossiq looked.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseIsAnEmptyAnswer(): void {
		$event = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '1999-0001']);
		$this->listener()->handle($event);

		$this->assertTrue($event->isAnswered());
		$this->assertNull($event->getRefusal());
		$this->assertSame([], $event->getAnswer()['cases']);
	}//end testAnUnknownCaseIsAnEmptyAnswer()

	/**
	 * A query without an identificatie is refused, not answered with every case.
	 *
	 * @return void
	 */
	public function testACaseQueryWithoutIdentificatieIsRefused(): void {
		$event = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['omschrijving' => 'Kap']);
		$this->listener()->handle($event);

		$this->assertNotNull($event->getRefusal());
		$this->assertSame([], $this->asked, 'The case store is not read');
	}//end testACaseQueryWithoutIdentificatieIsRefused()

	/**
	 * A source no stufEndpoint names is not dossiq's: the event stays unanswered.
	 *
	 * @return void
	 */
	public function testAnotherAppsSourceIsLeftUnanswered(): void {
		$event = new StufCaseQueryEvent(sourceId: 'someone-elses', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
		$this->assertSame([], $this->asked);
	}//end testAnotherAppsSourceIsLeftUnanswered()

	/**
	 * A failing case store leaves the event unanswered, so integriq faults instead of answering "none".
	 *
	 * @return void
	 */
	public function testABrokenCaseStoreIsNotAnEmptyAnswer(): void {
		$this->storeBroken = true;
		$event = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
	}//end testABrokenCaseStoreIsNotAnEmptyAnswer()

	/**
	 * An npsLv01 is answered from the case that names the BSN as its initiator.
	 *
	 * @return void
	 */
	public function testAPersonQueryIsAnsweredFromTheInitiator(): void {
		$event = new StufPersonQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-2', bsn: '999993653');
		$this->listener()->handle($event);

		$this->assertSame([['inp.bsn' => '999993653', 'geslachtsnaam' => 'J. Jansen']], $event->getAnswer()['persons']);
		$this->assertSame('person', $this->asked[0]['initiatorType']);

		$unknown = new StufPersonQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-3', bsn: '111222333');
		$this->listener()->handle($unknown);
		$this->assertTrue($unknown->isAnswered());
		$this->assertSame([], $unknown->getAnswer()['persons']);

		$malformed = new StufPersonQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-4', bsn: '12');
		$this->listener()->handle($malformed);
		$this->assertNotNull($malformed->getRefusal());
	}//end testAPersonQueryIsAnsweredFromTheInitiator()

	/**
	 * Case and document changes over StUF are refused, never confirmed.
	 *
	 * @return void
	 */
	public function testMutationsAreRefused(): void {
		$case = new StufCaseMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-5', mutatiesoort: 'W', fields: ['identificatie' => '2026-0042']);
		$document = new StufDocumentMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-6', mutatiesoort: 'T', fields: ['identificatie' => 'DOC-1']);
		$this->listener()->handle($case);
		$this->listener()->handle($document);

		$this->assertNotNull($case->getRefusal());
		$this->assertNotNull($document->getRefusal());
		$this->assertSame([], $case->getAnswer());
	}//end testMutationsAreRefused()

	/**
	 * Build the listener.
	 *
	 * @return StufProviderQueryListener
	 */
	private function listener(): StufProviderQueryListener {
		$register = $this->createMock(StufRegisterAccess::class);
		$mappings = $this->mappings;
		$register->method('findOne')->willReturnCallback(
			static function (string $schema, array $filters) use ($mappings): ?array {
				if ($schema === StufRegisterAccess::SCHEMA_ENDPOINT && $filters === ['integriqSource' => 'src-9']) {
					return ['id' => 'ep-1', 'integriqSource' => 'src-9', 'zaaktypeMappings' => $mappings];
				}

				return null;
			}
		);

		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param StufProviderQueryListenerTest $test The test.
			 */
			public function __construct(private readonly StufProviderQueryListenerTest $test) {
			}

			/**
			 * OpenRegister's findAll.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         RBAC.
			 * @param bool  $_multitenancy Multitenancy.
			 *
			 * @return array The rows.
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				return $this->test->storedCases(filters: $config['filters'], rbac: $_rbac);
			}

			/**
			 * OpenRegister's find, for the case type.
			 *
			 * @param string      $id       The id.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array|null The case type.
			 */
			public function find(string $id, ?string $register=null, ?string $schema=null): ?array {
				if ($schema === 'caseType' && $id === 'ct-uuid-1') {
					return (['id' => 'ct-uuid-1'] + StufProviderQueryListenerTest::CASE_TYPE);
				}

				return null;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn(string $key): string => ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? ''
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new StufProviderQueryListener($register, $settings, new CaseObjectReader($settings, $logger), $logger);
	}//end listener()

	/**
	 * The case store double: matches every filter that is a declared case property.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param bool                 $rbac    Whether RBAC was on.
	 *
	 * @return array<int, array<string, mixed>> The matching cases.
	 */
	public function storedCases(array $filters, bool $rbac): array {
		$this->asked[] = $filters;
		$this->assertFalse($rbac, 'There is no user session on a StUF request');
		if ($this->storeBroken === true) {
			throw new RuntimeException('database down');
		}

		$declared = self::caseProperties();
		$matches  = [];
		foreach ($this->cases as $case) {
			foreach ($filters as $key => $value) {
				if ($key === 'register' || $key === 'schema') {
					continue;
				}

				$this->assertContains($key, $declared, 'A filter on an undeclared case property matches nothing in OpenRegister');
				if (($case[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $case;
		}

		return $matches;
	}//end storedCases()

	/**
	 * The properties the installed case schema declares.
	 *
	 * @return string[] The property names.
	 */
	private static function caseProperties(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);

		return array_keys($register['components']['schemas']['case']['properties']);
	}//end caseProperties()
}//end class
