<?php

/**
 * TermsEndingFirst tests: the running statutory terms, soonest end first,
 * counted the way the case page counts them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\CaseTermSources;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\Termijn\TermsEndingFirst;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TermsEndingFirst.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
 */
class TermsEndingFirstTest extends TestCase {

	/**
	 * The filters the last search was asked with.
	 *
	 * @var array<string, mixed>
	 */
	public array $asked = [];

	/**
	 * Running statutory terms come back soonest first, named by their case,
	 * with the share passed and the days left; other kinds and stopped terms
	 * are left out, and the limit holds.
	 *
	 * @return void
	 */
	public function testListsRunningStatutoryTermsSoonestFirst(): void {
		$rows = [
			[
				'id' => 't1',
				'case' => 'c1',
				'kind' => 'statutory',
				'status' => 'lopend',
				'startDate' => '2026-11-01',
				'endDateCurrent' => '2026-11-21',
			],
			['id' => 't2', 'case' => 'c2', 'status' => 'verlengd', 'startDate' => '2026-10-01', 'endDateCurrent' => '2026-11-06'],
			['id' => 't3', 'case' => 'c3', 'kind' => 'internal', 'status' => 'lopend', 'startDate' => '2026-10-01', 'endDateCurrent' => '2026-11-05'],
			[
				'id' => 't4',
				'case' => 'c4',
				'kind' => 'statutory',
				'status' => 'voltooid',
				'startDate' => '2026-10-01',
				'endDateCurrent' => '2026-11-05',
			],
			[
				'id' => 't5',
				'case' => 'c5',
				'kind' => 'statutory',
				'status' => 'paused',
				'startDate' => '2026-10-01',
				'endDateCurrent' => '2026-12-30',
			],
		];
		$cases = [
			'c1' => ['identifier' => '2026-0110', 'title' => 'Verlichting fietspad Lindelaan'],
			'c2' => ['identifier' => '2026-0042', 'title' => 'Kapvergunning'],
		];

		$answer = $this->service(rows: $rows, cases: $cases)->list(limit: 2, now: new DateTimeImmutable('2026-11-11'));

		$this->assertTrue(condition: $answer['available']);
		$this->assertSame(expected: ['in' => ['lopend', 'verlengd', 'paused']], actual: $this->asked['status']);
		$this->assertSame(expected: ['c2', 'c1'], actual: array_column($answer['terms'], 'caseId'));
		$this->assertSame(
			expected: [
				'caseId' => 'c1',
				'caseLabel' => '2026-0110 Verlichting fietspad Lindelaan',
				'endDate' => '2026-11-21',
				'progressPercent' => 50,
				'daysLeft' => 10,
			],
			actual: $answer['terms'][1]
		);
		$this->assertSame(expected: -5, actual: $answer['terms'][0]['daysLeft']);
	}//end testListsRunningStatutoryTermsSoonestFirst()

	/**
	 * The whole question asked and the whole answer, with the limit clamped.
	 *
	 * @return void
	 */
	public function testTheWholeQuestionAndAnswer(): void {
		$rows = [
			['id' => 't1', 'case' => 'c1', 'status' => 'lopend', 'startDate' => '2026-11-01', 'endDateCalculated' => '2026-11-21'],
			['id' => 't2', 'case' => '', 'status' => 'paused', 'startDate' => '2026-11-01', 'endDateCurrent' => '2026-11-15'],
			['id' => 't3', 'case' => 'c3', 'status' => 'lopend', 'startDate' => '2026-11-01'],
		];
		$now = new DateTimeImmutable('2026-11-11');

		$one = $this->service(rows: $rows, cases: ['c1' => ['title' => 'Alleen titel']])->list(limit: 0, now: $now);
		$this->assertSame(['status' => ['in' => ['lopend', 'verlengd', 'paused']], '_order' => ['endDateCurrent' => 'ASC'], '_limit' => 100, '@self' => ['register' => 1, 'schema' => 2]], $this->asked);
		$this->assertSame(['available' => true, 'terms' => [['caseId' => '', 'caseLabel' => '', 'endDate' => '2026-11-15', 'progressPercent' => 71, 'daysLeft' => 4]]], $one);

		$all = $this->service(rows: $rows, cases: ['c1' => ['title' => 'Alleen titel']])->list(limit: 99, now: $now);
		$this->assertSame(['', 'c1'], array_column($all['terms'], 'caseId'));
		$this->assertSame('Alleen titel', $all['terms'][1]['caseLabel']);
	}//end testTheWholeQuestionAndAnswer()

	/**
	 * Without the term schema the answer says it is not available, so the card is not drawn.
	 *
	 * @return void
	 */
	public function testUnavailableWithoutTheTermSchema(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$service = new TermsEndingFirst(settingsService: $settings, terms: $this->terms());

		$this->assertSame(expected: ['available' => false, 'terms' => []], actual: $service->list());
	}//end testUnavailableWithoutTheTermSchema()

	/**
	 * The service over a fake object store.
	 *
	 * @param list<array<string, mixed>>          $rows  The term instances.
	 * @param array<string, array<string, mixed>> $cases The cases by id.
	 *
	 * @return TermsEndingFirst
	 */
	private function service(array $rows, array $cases): TermsEndingFirst {
		$test = $this;
		$store = new class($rows, $cases, $test) {
			/**
			 * @param list<array<string, mixed>>          $rows  Instances.
			 * @param array<string, array<string, mixed>> $cases Cases.
			 * @param TermsEndingFirstTest                $test  The test, to record the filters.
			 */
			public function __construct(private array $rows, private array $cases, private TermsEndingFirstTest $test) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return list<array<string, mixed>>
			 */
			public function searchObjects(array $query): array {
				$this->test->asked = $query;
				return $this->rows;
			}

			/**
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 * @return array<string, mixed>
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				return ($this->cases[$id] ?? []);
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '1'], ['termijn_instance_schema', '', '2'], ['case_schema', '', '3']]);

		return new TermsEndingFirst(settingsService: $settings, terms: $this->terms());
	}//end service()

	/**
	 * A real CaseTermsService; standingOf reads nothing from its sources.
	 *
	 * @return CaseTermsService
	 */
	private function terms(): CaseTermsService {
		$sources = new CaseTermSources(
			termService: $this->createMock(originalClassName: TermijnService::class),
			declarations: $this->createMock(originalClassName: TermDeclarationReader::class),
			timers: $this->createMock(originalClassName: TermijnTimerService::class),
		);

		return new CaseTermsService(sources: $sources, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end terms()
}//end class
