<?php

/**
 * A form submission opens a case, or it opens nothing at all.
 *
 * 🔴 THE GUARD IS SHOWN ABLE TO SAY NO, and that is the assertion this file
 * exists for. A listener on every Forms submission that creates a case is one
 * wrong condition away from turning the staff lunch poll into work somebody is
 * measured on, and a test that only proved the bound form works would pass
 * against a service that created a case for every submission on the instance.
 * So the unbound case is asserted first and the bound one is its control.
 *
 * 🔴 THE FILTER IS ASKED FOR AND THEN CHECKED. The fake store here DELIBERATELY
 * ignores the `intakeFormRef` filter and answers every case type, because a
 * store that ignores an unknown filter key is exactly what OpenRegister does
 * with the wrong filter grammar: it answers the whole register, confidently,
 * with no error. A service that trusted the store would open a case of the
 * first case type it found. The `filter is honoured by the store` test pins
 * the other direction.
 *
 * The clock is the other half. `startDate` is what the materialised `deadline`
 * is computed from, so a case written without one has no statutory clock at
 * all, silently.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FormsIntakeService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A bound form opens a clocked case; anything else opens nothing.
 *
 * @covers \OCA\Dossiq\Service\FormsIntakeService
 */
class FormsIntakeServiceTest extends TestCase {

	/**
	 * Everything the fake store was asked to write.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * The filters the fake store was asked to read by.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Reset the logs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writes = [];
		$this->queries = [];
	}//end setUp()

	/**
	 * Build the service over an in-memory store.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The case types the store holds.
	 * @param bool                             $honourFilter Whether the store applies
	 *                                                       the `intakeFormRef` filter.
	 *
	 * @return FormsIntakeService The service under test.
	 */
	private function makeService(array $caseTypes, bool $honourFilter = false): FormsIntakeService {
		$writes = &$this->writes;
		$queries = &$this->queries;

		$objectService = new class($caseTypes, $honourFilter, $writes, $queries) {
			/**
			 * @param array<int, array<string, mixed>> $caseTypes    The stored case types.
			 * @param bool                             $honourFilter Whether to filter.
			 * @param array<int, array<string, mixed>> $writes       Write log.
			 * @param array<int, array<string, mixed>> $queries      Read log.
			 */
			public function __construct(
				private array $caseTypes,
				private bool $honourFilter,
				private array &$writes,
				private array &$queries,
			) {
			}//end __construct()

			/**
			 * Answer the case types, filtered or not.
			 *
			 * @param array<string, mixed> $params The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				$this->queries[] = $filters;

				if ($this->honourFilter === false) {
					return $this->caseTypes;
				}

				$wanted = (string)($filters['intakeFormRef'] ?? '');

				return array_values(
					array_filter(
						$this->caseTypes,
						static fn (array $row): bool => ($row['intakeFormRef'] ?? '') === $wanted
					)
				);
			}//end findAll()

			/**
			 * Record a write and answer it back with an id.
			 *
			 * @param array<string, mixed> $object   The object written.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->writes[] = ['schema' => $schema, 'object' => $object];

				return array_merge($object, ['id' => 'case-new']);
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					default => $default,
				};
			}
		);

		return new FormsIntakeService($settings, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * One case type bound to a form.
	 *
	 * @param string $formHash The form it is bound to.
	 *
	 * @return array<string, mixed> The case type row.
	 */
	private function boundType(string $formHash): array {
		return [
			'id' => 'type-verhuizing',
			'title' => 'Verhuizing doorgeven',
			'intakeFormRef' => $formHash,
			'initialStatus' => 'status-received',
			'processingDeadline' => 'P8W',
		];
	}//end boundType()

	/**
	 * A submission of a form nothing bound creates nothing.
	 *
	 * @return void
	 */
	public function testAnUnboundFormOpensNothing(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$caseId = $service->caseFor(
			formHash: 'form-lunch-poll',
			answers: ['Wat eet je?' => 'Soep'],
			submitted: '2026-09-18'
		);

		$this->assertNull($caseId, 'an unbound form must open no case');
		$this->assertSame([], $this->writes, 'an unbound form must write nothing at all');
	}//end testAnUnboundFormOpensNothing()

	/**
	 * A submission of a bound form opens a case of that type.
	 *
	 * The control for the test above: without it, "nothing was written" could
	 * mean the service never writes anything.
	 *
	 * @return void
	 */
	public function testABoundFormOpensACaseOfThatType(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$caseId = $service->caseFor(
			formHash: 'form-abc',
			answers: ['Waar gaat het over?' => 'Verhuizing naar Dorpsstraat 12'],
			submitted: '2026-09-18'
		);

		$this->assertSame('case-new', $caseId);
		$this->assertCount(1, $this->writes);
		$this->assertSame('case', $this->writes[0]['schema']);
		$this->assertSame('type-verhuizing', $this->writes[0]['object']['caseType']);
	}//end testABoundFormOpensACaseOfThatType()

	/**
	 * The case opens with the clock running from the submission.
	 *
	 * @return void
	 */
	public function testTheClockStartsAtTheSubmission(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$service->caseFor(
			formHash: 'form-abc',
			answers: ['Waar gaat het over?' => 'Verhuizing'],
			submitted: '2026-09-18'
		);

		$written = $this->writes[0]['object'];
		// The materialised `deadline` is dateAdd(startDate, processingDeadline),
		// so a case written without a startDate has no statutory clock at all
		// and nothing anywhere says so.
		$this->assertSame('2026-09-18', $written['startDate']);
		$this->assertSame('forms', $written['intakeChannel']);
		// A case with no status is off every status-filtered lens and has no
		// available transitions. The case type's prefill block fills a FORM and
		// does not run on a write, so the status is written here or never.
		$this->assertSame('status-received', $written['status']);
	}//end testTheClockStartsAtTheSubmission()

	/**
	 * A store that ignores the filter does not get to choose the case type.
	 *
	 * @return void
	 */
	public function testAStoreThatIgnoresTheFilterOpensNothing(): void {
		// The fake answers EVERY case type, which is what OpenRegister does
		// with a filter grammar it does not recognise: the whole register,
		// confidently, with no error. A service that trusted the answer would
		// open a case of whichever type came back first.
		$service = $this->makeService(
			[$this->boundType('form-abc'), ['id' => 'type-other', 'intakeFormRef' => 'form-xyz']],
			honourFilter: false
		);

		$this->assertNull(
			$service->caseFor(formHash: 'form-nobody-bound', answers: [], submitted: '2026-09-18'),
			'a row the store handed back must still be checked against the form hash'
		);
		$this->assertSame([], $this->writes);

		// And the same store DOES open a case for a hash one of its rows names,
		// so the assertion above is about the check and not about the fake.
		$this->assertSame(
			'case-new',
			$service->caseFor(formHash: 'form-abc', answers: [], submitted: '2026-09-18')
		);
	}//end testAStoreThatIgnoresTheFilterOpensNothing()

	/**
	 * The lookup asks the store by the form hash.
	 *
	 * @return void
	 */
	public function testTheLookupFiltersByTheFormHash(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);
		$service->caseFor(formHash: 'form-abc', answers: [], submitted: '2026-09-18');

		$this->assertNotSame([], $this->queries, 'the store must actually be asked');
		// A BARE key. The objects endpoint reads a bare property name and
		// answers the empty set for a `filter[...]` one, with no error either
		// way (openregister#3611).
		$this->assertSame('form-abc', $this->queries[0]['intakeFormRef']);
		$this->assertArrayNotHasKey('filter[intakeFormRef]', $this->queries[0]);
	}//end testTheLookupFiltersByTheFormHash()

	/**
	 * The answers reach the case, question first.
	 *
	 * @return void
	 */
	public function testTheAnswersReachTheCase(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$service->caseFor(
			formHash: 'form-abc',
			answers: [
				'Waar gaat het over?' => 'Verhuizing naar Dorpsstraat 12',
				'Wanneer?' => '2026-10-01',
			],
			submitted: '2026-09-18'
		);

		$written = $this->writes[0]['object'];
		$this->assertSame('Verhuizing naar Dorpsstraat 12', $written['title']);
		$this->assertStringContainsString('Waar gaat het over?', $written['description']);
		$this->assertStringContainsString('2026-10-01', $written['description']);
	}//end testTheAnswersReachTheCase()

	/**
	 * A submission with no readable answer still gets a title.
	 *
	 * @return void
	 */
	public function testASubmissionWithNothingToNameItStillOpens(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$service->caseFor(formHash: 'form-abc', answers: [], submitted: '2026-09-18');

		$this->assertNotSame(
			'',
			$this->writes[0]['object']['title'],
			'a case with an empty title is refused by the schema, so it needs a fallback'
		);
	}//end testASubmissionWithNothingToNameItStillOpens()

	/**
	 * An empty form hash is not a lookup.
	 *
	 * @return void
	 */
	public function testAnEmptyFormHashOpensNothing(): void {
		$service = $this->makeService([$this->boundType('form-abc')], honourFilter: true);

		$this->assertNull($service->caseFor(formHash: '  ', answers: [], submitted: ''));
		$this->assertSame([], $this->queries, 'an empty hash must not even reach the store');
	}//end testAnEmptyFormHashOpensNothing()
}//end class
