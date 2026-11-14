<?php

/**
 * Unit tests for InstalledPackageExamples.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\InstalledPackageExamples;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\CaseDefinition\PackageSchemaReferences;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Writing the examples of an installed package: refused when the package is
 * unknown, invalid, not the installed version or misses a schema; current
 * when it has no rows; failed when the write or the ledger fails, with the
 * rows taken back; installed with its counts otherwise.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\InstalledPackageExamples
 * @uses   \OCA\Dossiq\Service\CaseDefinition\PackageExamples
 * @uses   \OCA\Dossiq\Service\CaseDefinition\PackageExampleDates
 * @uses   \OCA\Dossiq\Service\CaseDefinition\PackageInstallRun
 * @uses   \OCA\Dossiq\Service\Support\JsonEncodedStringProperties
 */
class InstalledPackageExamplesTest extends TestCase {
	/**
	 * The package directory.
	 *
	 * @var PackageDirectory&MockObject
	 */
	private PackageDirectory&MockObject $packages;

	/**
	 * The validator.
	 *
	 * @var PackageValidator&MockObject
	 */
	private PackageValidator&MockObject $validator;

	/**
	 * The writer.
	 *
	 * @var PackageWriter&MockObject
	 */
	private PackageWriter&MockObject $writer;

	/**
	 * The shipped-set ledger.
	 *
	 * @var ShippedConfigurationService&MockObject
	 */
	private ShippedConfigurationService&MockObject $ledger;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The schema references.
	 *
	 * @var PackageSchemaReferences&MockObject
	 */
	private PackageSchemaReferences&MockObject $references;

	/**
	 * The service under test.
	 *
	 * @var InstalledPackageExamples
	 */
	private InstalledPackageExamples $examples;

	/**
	 * Build the service over a valid, current package `pkg` 1.2.0 with one example.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->packages = $this->createMock(PackageDirectory::class);
		$this->validator = $this->createMock(PackageValidator::class);
		$this->writer = $this->createMock(PackageWriter::class);
		$this->ledger = $this->createMock(ShippedConfigurationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->references = $this->createMock(PackageSchemaReferences::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(DemoDataService::DECIDED_INSTALLED);

		$this->packages->method('read')->willReturnCallback(
			static fn (string $id): ?array => ($id === 'pkg' ? ['files' => ['examples/a.json' => '{}'], 'digest' => 'd1'] : null)
		);
		$this->packages->method('rowsUnder')->willReturn([['schema' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]]]);
		$this->validator->method('validateShipped')->willReturn(['valid' => true, 'manifest' => ['version' => '1.2.0', 'parts' => ['examples']]]);

		$this->examples = new InstalledPackageExamples(
			packages: $this->packages,
			validator: $this->validator,
			writer: $this->writer,
			ledger: $this->ledger,
			appConfig: $appConfig,
			logger: $this->logger,
			references: $this->references,
		);
	}//end setUp()

	/**
	 * The seed group each example becomes.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return void
	 */
	private function seedsAs(array $group): void {
		$this->references->method('seedGroup')->willReturn($group);
	}//end seedsAs()

	/**
	 * An unknown package is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownPackageIsRefused(): void {
		$this->assertSame(
			['status' => PackageInstaller::REFUSED, 'package' => 'nope', 'version' => '', 'message' => "No package named 'nope' is shipped."],
			$this->examples->forPackage(id: 'nope')
		);
	}//end testAnUnknownPackageIsRefused()

	/**
	 * An invalid package is refused with its version.
	 *
	 * @return void
	 */
	public function testAnInvalidPackageIsRefused(): void {
		$validator = $this->createMock(PackageValidator::class);
		$validator->method('validateShipped')->willReturn(['valid' => false, 'manifest' => ['version' => '0.9.0']]);
		$examples = new InstalledPackageExamples(
			packages: $this->packages,
			validator: $validator,
			writer: $this->writer,
			ledger: $this->ledger,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			references: $this->references,
		);
		$this->ledger->expects($this->never())->method('isPackageCurrent');

		$this->assertSame(
			['status' => PackageInstaller::REFUSED, 'package' => 'pkg', 'version' => '0.9.0', 'message' => "Package 'pkg' is not valid."],
			$examples->forPackage(id: 'pkg')
		);
	}//end testAnInvalidPackageIsRefused()

	/**
	 * A package that is not the installed version is refused, checked by version and digest.
	 *
	 * @return void
	 */
	public function testAPackageThatIsNotInstalledHereIsRefused(): void {
		$this->ledger->expects($this->once())->method('isPackageCurrent')->with('pkg', '1.2.0', 'd1')->willReturn(false);
		$this->writer->expects($this->never())->method('writePackage');

		$this->assertSame(
			[
				'status' => PackageInstaller::REFUSED,
				'package' => 'pkg',
				'version' => '1.2.0',
				'message' => "Package 'pkg' 1.2.0 is not what is installed here; its next install writes its examples.",
			],
			$this->examples->forPackage(id: 'pkg')
		);
	}//end testAPackageThatIsNotInstalledHereIsRefused()

	/**
	 * A package whose examples have no rows is current.
	 *
	 * @return void
	 */
	public function testAPackageWithoutRowsIsCurrent(): void {
		$this->ledger->method('isPackageCurrent')->willReturn(true);
		$this->seedsAs(group: ['schemaKey' => 'case_schema', 'rows' => []]);
		$this->writer->expects($this->never())->method('writePackage');

		$this->assertSame(
			['status' => PackageInstaller::CURRENT, 'package' => 'pkg', 'version' => '1.2.0', 'message' => "Package 'pkg' has no example rows to write."],
			$this->examples->forPackage(id: 'pkg')
		);
	}//end testAPackageWithoutRowsIsCurrent()

	/**
	 * Examples that miss a schema are refused with the reason and what is missing.
	 *
	 * @return void
	 */
	public function testExamplesMissingASchemaAreRefused(): void {
		$this->ledger->method('isPackageCurrent')->willReturn(true);
		$this->seedsAs(group: ['schemaKey' => '', 'rows' => [['identifier' => 'Z-1']], 'missing' => 'woo_schema', 'reason' => 'the schema woo_schema']);
		$this->writer->expects($this->never())->method('writePackage');

		$this->assertSame(
			[
				'status' => PackageInstaller::REFUSED,
				'package' => 'pkg',
				'version' => '1.2.0',
				'message' => "The examples of package 'pkg' need the schema woo_schema.",
				'missing' => ['woo_schema'],
			],
			$this->examples->forPackage(id: 'pkg')
		);
	}//end testExamplesMissingASchemaAreRefused()

	/**
	 * A failed write is reported with the writer's message.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReported(): void {
		$this->ledger->method('isPackageCurrent')->willReturn(true);
		$this->seedsAs(group: ['schemaKey' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]]);
		$this->writer->method('writePackage')->willReturn(['status' => 'error', 'message' => 'No register configured.']);
		$this->ledger->expects($this->never())->method('stamp');

		$this->assertSame(
			['status' => PackageInstaller::FAILED, 'package' => 'pkg', 'version' => '1.2.0', 'message' => 'No register configured.'],
			$this->examples->forPackage(id: 'pkg')
		);
	}//end testAFailedWriteIsReported()

	/**
	 * A row the ledger cannot record takes every written row back.
	 *
	 * @return void
	 */
	public function testALedgerFailureTakesTheRowsBack(): void {
		$this->ledger->method('isPackageCurrent')->willReturn(true);
		$this->seedsAs(group: ['schemaKey' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]]);
		$written = [['schema' => 's-case', 'id' => 'o-1', 'row' => ['identifier' => 'Z-1'], 'schemaKey' => 'case_schema']];
		$this->writer->method('writePackage')->willReturn(['status' => 'success', 'message' => 'ok', 'written' => $written, 'adopted' => [], 'skipped' => []]);
		$this->ledger->method('stamp')->willReturn(false);
		$this->writer->expects($this->once())->method('undo')->with($written);

		$this->assertSame(
			[
				'status' => PackageInstaller::FAILED,
				'package' => 'pkg',
				'version' => '1.2.0',
				'message' => "The examples of package 'pkg' were not written: a row could not be recorded in the shipped-set ledger,"
					. ' so every row was taken back.',
			],
			$this->examples->forPackage(id: 'pkg')
		);
	}//end testALedgerFailureTakesTheRowsBack()

	/**
	 * Written rows and rows adopted from elsewhere are stamped; adopted rows
	 * already in this package are not; the counts are logged and returned.
	 *
	 * @return void
	 */
	public function testWrittenExamplesAreStampedAndCounted(): void {
		$this->ledger->method('isPackageCurrent')->willReturn(true);
		$this->seedsAs(group: ['schemaKey' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]]);
		$written = [['schema' => 's-case', 'id' => 'o-1', 'row' => ['identifier' => 'Z-1'], 'schemaKey' => 'case_schema']];
		$adopted = [
			['schema' => 's-case', 'id' => 'o-2', 'row' => ['identifier' => 'Z-2']],
			['schema' => 's-case', 'id' => 'o-3', 'row' => ['identifier' => 'Z-3']],
		];
		$this->writer->method('writePackage')->with('pkg', $this->anything())->willReturn(
			['status' => 'success', 'message' => '3 rows', 'written' => $written, 'adopted' => $adopted, 'skipped' => [['id' => 'o-4']]]
		);
		$this->ledger->method('stateOf')->willReturnCallback(
			static fn (string $targetSchema, string $targetObject): array => ['set' => ($targetObject === 'o-2' ? 'pkg' : 'other')]
		);
		$stamped = [];
		$this->ledger->method('stamp')->willReturnCallback(
			static function (string $set, string $targetSchema, string $targetObject, array $object, ?string $version = null, ?array $shipped = null, string $objectsKey = '') use (&$stamped): bool {
				$stamped[] = [$set, $targetObject, $version, $objectsKey];
				return true;
			}
		);
		$this->logger->expects($this->once())->method('info')->with(
			'Wrote the examples of package {id} {version}: {written} row(s) written, {adopted} adopted, {skipped} already present',
			['id' => 'pkg', 'version' => '1.2.0', 'written' => 1, 'adopted' => 2, 'skipped' => 1]
		);
		$this->writer->expects($this->never())->method('undo');

		$result = $this->examples->forPackage(id: 'pkg');

		$this->assertSame([['pkg', 'o-1', '1.2.0', 'case_schema'], ['pkg', 'o-3', '1.2.0', '']], $stamped);
		$this->assertSame(
			['status' => PackageInstaller::INSTALLED, 'package' => 'pkg', 'version' => '1.2.0', 'message' => '3 rows', 'written' => 1, 'adopted' => 2, 'skipped' => 1],
			$result
		);
	}//end testWrittenExamplesAreStampedAndCounted()

	/**
	 * Only installed packages are visited.
	 *
	 * @return void
	 */
	public function testOnlyInstalledPackagesAreVisited(): void {
		$this->packages->method('ids')->willReturn(['nope', 'gone']);
		$this->ledger->method('installedVersion')->willReturnCallback(
			static fn (string $set): string => ($set === 'nope' ? '1.0.0' : '')
		);

		$this->assertSame(
			[['status' => PackageInstaller::REFUSED, 'package' => 'nope', 'version' => '', 'message' => "No package named 'nope' is shipped."]],
			$this->examples->forInstalled()
		);
	}//end testOnlyInstalledPackagesAreVisited()
}//end class
