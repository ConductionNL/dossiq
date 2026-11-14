<?php

/**
 * RealignStatutoryVocabulary Tests
 *
 * The step moves mis-translated ZGW and StUF values back to their statutory
 * Dutch spelling, one schema and property at a time. These tests pin which
 * searches it sends, in which order, which rows it writes, and what it reports.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RealignStatutoryVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Restoring the statutory vocabularies.
 *
 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
 */
class RealignStatutoryVocabularyTest extends TestCase {
	/**
	 * Rows the fake register holds, per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every search the step sent, as [schema, filters].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $searches = [];

	/**
	 * Rows the step wrote back, as [schema, row].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $written = [];

	/**
	 * Whether the fake register honours the value filter.
	 *
	 * @var bool
	 */
	private bool $honoursFilter = true;

	/**
	 * How often the work ran under the system identity.
	 *
	 * @var int
	 */
	private int $systemRuns = 0;

	/**
	 * A fake object service over the rows above.
	 *
	 * @param bool $fails Whether every search throws.
	 *
	 * @return object The object service.
	 */
	private function objectService(bool $fails=false): object {
		$test = $this;

		return new class($test, $fails) {
			/**
			 * @param RealignStatutoryVocabularyTest $test  The test holding the state.
			 * @param bool                           $fails Whether every search throws.
			 */
			public function __construct(private RealignStatutoryVocabularyTest $test, private bool $fails) {
			}

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->test->recordSystemRun();
				$work();
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->fails === true) {
					throw new RuntimeException('register down');
				}

				return $this->test->search(register: $register, schema: $schema, filters: $filters);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param array|null           $extend   Relations to expand (ignored).
			 * @param string|int|null      $register The register.
			 * @param string|int|null      $schema   The schema.
			 * @param string|null          $uuid     The uuid to update.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			): array {
				return $this->test->save(schema: (string)$schema, object: $object);
			}
		};
	}//end objectService()

	/**
	 * Record a run under the system identity.
	 *
	 * @return void
	 */
	public function recordSystemRun(): void {
		$this->systemRuns++;
	}//end recordSystemRun()

	/**
	 * Answer a search the way the backend would.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function search(string $register, string $schema, array $filters): array {
		$this->assertSame('dossiq', $register);
		$this->searches[] = [$schema, $filters];
		$rows = ($this->rows[$schema] ?? []);
		if ($this->honoursFilter === false) {
			return $rows;
		}

		$wanted = array_diff_key($filters, ['_limit' => true]);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => array_intersect_assoc($wanted, $row) === $wanted
			)
		);
	}//end search()

	/**
	 * Record a write.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $object The row.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function save(string $schema, array $object): array {
		$this->written[] = [$schema, $object];

		return $object;
	}//end save()

	/**
	 * The step over an object service.
	 *
	 * @param object|null          $objectService The object service, or null when OpenRegister is absent.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return RealignStatutoryVocabulary The step.
	 */
	private function step(?object $objectService, ?LoggerInterface $logger=null): RealignStatutoryVocabulary {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new RealignStatutoryVocabulary($settings, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end step()

	/**
	 * Every schema and value pair is searched in declaration order, and only mis-translated rows are written.
	 *
	 * @return void
	 */
	public function testEveryPairIsSearchedInOrderAndOnlyWrongRowsAreWritten(): void {
		$this->rows = [
			'case' => [
				['id' => 'c-1', 'confidentiality' => 'secret'],
				['id' => 'c-2', 'confidentiality' => 'openbaar'],
			],
			'stufMessage' => [['id' => 's-1', 'status' => 'sent']],
			'zaaksysteemMapping' => [['id' => 'z-1', 'synchronisationStatus' => 'waiting']],
		];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Dossiq: realigned the statutory vocabulary on 3 row(s).');

		$this->step(objectService: $this->objectService())->run($output);

		$this->assertSame(1, $this->systemRuns, 'the work runs under the system identity');
		$this->assertCount(37, $this->searches);
		$this->assertSame(['case', ['confidentiality' => 'public', '_limit' => 5000]], $this->searches[0]);
		$this->assertSame(['caseType', ['confidentiality' => 'public', '_limit' => 5000]], $this->searches[5]);
		$this->assertSame(['informatieobject', ['vertrouwelijkheidaanduiding' => 'public', '_limit' => 5000]], $this->searches[20]);
		$this->assertSame(['stufMessage', ['status' => 'sent', '_limit' => 5000]], $this->searches[30]);
		$this->assertSame(['zaaksysteemMapping', ['synchronisationStatus' => 'waiting', '_limit' => 5000]], $this->searches[36]);
		$this->assertSame(
			[
				['case', ['id' => 'c-1', 'confidentiality' => 'geheim']],
				['stufMessage', ['id' => 's-1', 'status' => 'verzonden']],
				['zaaksysteemMapping', ['id' => 'z-1', 'synchronisationStatus' => 'wacht']],
			],
			$this->written
		);
	}//end testEveryPairIsSearchedInOrderAndOnlyWrongRowsAreWritten()

	/**
	 * A backend that ignores the filter does not relabel rows holding another value.
	 *
	 * @return void
	 */
	public function testAnIgnoredFilterRewritesOnlyTheWrongValue(): void {
		$this->honoursFilter = false;
		$this->rows = ['stufMessage' => [['id' => 's-1', 'status' => 'error'], ['id' => 's-2', 'status' => 'verzonden']]];

		$this->step(objectService: $this->objectService())->run($this->createMock(IOutput::class));

		$this->assertSame([['stufMessage', ['id' => 's-1', 'status' => 'fout']]], $this->written);
	}//end testAnIgnoredFilterRewritesOnlyTheWrongValue()

	/**
	 * Nothing to do, OpenRegister absent, and a failing register are each reported, never thrown.
	 *
	 * @return void
	 */
	public function testEveryOutcomeIsReportedNotThrown(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Dossiq: no rows needed the statutory vocabulary realignment.');
		$this->step(objectService: $this->objectService())->run($output);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Dossiq: statutory vocabulary realignment skipped, OpenRegister unavailable.');
		$this->step(objectService: null)->run($output);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: statutory vocabulary realignment failed',
			['exception' => 'register down']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->expects($this->once())->method('warning')->with('Dossiq: statutory vocabulary realignment could not run: register down');
		$this->step(objectService: $this->objectService(fails: true), logger: $logger)->run($output);

		$this->assertSame([], $this->written);
	}//end testEveryOutcomeIsReportedNotThrown()
}//end class
