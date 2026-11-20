<?php

/**
 * Golden record of the Awb proceeding scan behind the legal hold backfill.
 *
 * Records, per world, the candidate cases, what the scan prints, the queries
 * it makes and the scan errors, so a refactor cannot change which cases the
 * backfill would hold.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command\Backfill
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command\Backfill;

use OCA\Dossiq\Command\Backfill\AwbProceedingScanner;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A fake object service answering per schema and recording every query.
 */
class AwbScanObjectService {

	/**
	 * Rows per schema; a string makes that schema's query fail with it.
	 *
	 * @var array<string, mixed>
	 */
	public array $rows = [];

	/**
	 * The queries made.
	 *
	 * @var list<string>
	 */
	public array $queries = [];

	/**
	 * The schema of the next query.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Set the register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->queries[] = 'register ' . $register;
		return $this;
	}//end setRegister()

	/**
	 * Set the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Find all objects of the schema.
	 *
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $rbac          RBAC.
	 * @param bool                 $multitenancy  Multitenancy.
	 *
	 * @return mixed
	 */
	public function findAll(array $config, bool $rbac = true, bool $multitenancy = true): mixed {
		$this->queries[] = 'findAll ' . $this->schema . ' ' . json_encode([$config, $rbac, $multitenancy]);
		$rows = ($this->rows[$this->schema] ?? []);
		if (is_string($rows) === true) {
			throw new RuntimeException($rows);
		}

		return $rows;
	}//end findAll()
}//end class

/**
 * Golden test for AwbProceedingScanner::scan.
 *
 * @covers \OCA\Dossiq\Command\Backfill\AwbProceedingScanner
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class AwbProceedingScannerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every world the scan distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testScanAcrossWorlds(): void {
		$open = [
			'objectionProceeding' => [
				['uuid' => 'obj-1', 'case' => 'case-1'],
				['uuid' => 'obj-2', 'case' => ' case-2 '],
				['uuid' => 'obj-3', 'case' => ['id' => 'case-3']],
				['uuid' => 'obj-4', 'case' => ['@self' => ['id' => 'case-4']]],
				['uuid' => 'obj-5'],
			],
			'objection' => [
				['@self' => ['uuid' => 'bz-1'], 'case' => 'case-1'],
			],
			'beroep' => [
				['id' => 'ber-1', 'case' => 'case-1'],
				['case' => 'case-6'],
			],
		];
		$worlds = [
			'nothing on the instance' => [[], false],
			'open proceedings, none concluded' => [$open, false],
			'some concluded' => [$open + ['bezwaarDecision' => [['uuid' => 'd-1', 'objectionProceeding' => 'obj-1'], ['uuid' => 'd-2', 'objection' => 'bz-1', 'beroep' => '']], 'appealDecision' => [['uuid' => 'd-3', 'appeal' => 'ber-1', 'beroep' => 7]]], false],
			'deleted proceedings included' => [$open, true],
			'a schema that fails' => [['objection' => 'schema not found', 'appealDecision' => 'register offline'] + $open, false],
			'a schema that answers no list' => [['beroep' => null] + $open, false],
		];

		$record = [];
		foreach ($worlds as $name => [$rows, $includeDeleted]) {
			$service = new AwbScanObjectService();
			$service->rows = $rows;
			$output = new BufferedOutput();
			$scanner = new AwbProceedingScanner(normaliser: new OpenRegisterRowNormaliser());
			$record[$name] = [
				'candidates' => $scanner->scan(objectService: $service, includeDeleted: $includeDeleted, output: $output),
				'printed' => explode("\n", trim($output->fetch())),
				'errors' => $scanner->getScanErrors(),
				'queries' => $service->queries,
			];
		}

		$this->assertMatchesGolden(name: 'awb-proceeding-scan', value: $record);
	}//end testScanAcrossWorlds()
}//end class
