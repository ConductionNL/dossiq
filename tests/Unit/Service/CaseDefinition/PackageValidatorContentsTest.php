<?php

/**
 * Package Validator Contents Test
 *
 * Pins what the package validator reports about a manifest that parses:
 * missing required fields, declared components without their file or with
 * invalid JSON, a declared workflows component without workflow files, and a
 * warning per dependency the package references but does not carry. Also
 * pins that a shipped package is checked by the shipped rules and logged.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Tests for PackageValidator's checks of a parsed manifest.
 */
#[CoversClass(PackageValidator::class)]
#[UsesClass(PackageIds::class)]
#[UsesClass(ShippedPackageRules::class)]
final class PackageValidatorContentsTest extends TestCase {
	/**
	 * Temporary archives to remove.
	 *
	 * @var string[]
	 */
	private array $paths = [];

	/**
	 * Remove the archives written.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A ZIP holding these entries.
	 *
	 * @param array<string, string> $entries Name to content.
	 *
	 * @return string The path.
	 */
	private function package(array $entries): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'pvc_');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$this->paths[] = $path;

		return $path;
	}//end package()

	/**
	 * The validator.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PackageValidator
	 */
	private function validator(?LoggerInterface $logger = null): PackageValidator {
		return new PackageValidator(logger: ($logger ?? new NullLogger()), ids: new PackageIds());
	}//end validator()

	/**
	 * Every content problem is reported: errors make the package invalid,
	 * an empty workflows component and uncarried dependencies only warn.
	 *
	 * @return void
	 */
	public function testEveryContentProblemIsReported(): void {
		$manifest = [
			'version' => '1.0',
			'components' => ['workflows', 'statuses', 'fields'],
			'dependencies' => ['dep-a', ['name' => 'dep-b'], ['id' => 'st-1'], '  ', ['type' => 'nameless']],
		];

		$result = $this->validator()->validatePackage(
			$this->package(entries: ['manifest.json' => json_encode($manifest), 'statuses.json' => '{bad'])
		);

		self::assertFalse(condition: $result['valid']);
		self::assertSame(
			expected: [
				'Missing required manifest field: exportDate',
				'Missing required manifest field: caseType',
				"Component 'fields' declared in manifest but file 'fields.json' not found",
				'Invalid JSON in statuses.json: Syntax error',
			],
			actual: $result['errors']
		);
		$uncarried = ' is referenced by this package but not carried in it, so the target instance has to hold it already.';
		self::assertSame(
			expected: [
				'Component "workflows" declared but no workflow files found',
				"Dependency 'dep-a'" . $uncarried,
				"Dependency 'dep-b'" . $uncarried,
				"Dependency 'st-1'" . $uncarried,
			],
			actual: $result['warnings']
		);
	}//end testEveryContentProblemIsReported()

	/**
	 * A complete package with workflow files and only carried dependencies is clean.
	 *
	 * @return void
	 */
	public function testACompletePackageCarryingItsDependenciesIsClean(): void {
		$manifest = [
			'version' => '1.0',
			'exportDate' => '2026-09-18T00:00:00+00:00',
			'caseType' => ['id' => 'ct-1'],
			'components' => ['workflows', 'statuses'],
			'dependencies' => ['st-1'],
		];

		$result = $this->validator()->validatePackage(
			$this->package(
				entries: [
					'manifest.json' => json_encode($manifest),
					'statuses.json' => json_encode([['id' => 'st-1']]),
					'workflows/intake.json' => '{}',
				]
			)
		);

		self::assertTrue(condition: $result['valid']);
		self::assertSame(expected: [], actual: $result['errors']);
		self::assertSame(expected: [], actual: $result['warnings']);
	}//end testACompletePackageCarryingItsDependenciesIsClean()

	/**
	 * A shipped package is judged by the shipped rules, and its error count is logged.
	 *
	 * @return void
	 */
	public function testAShippedPackageIsCheckedAndLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('Validated shipped package {id}: {errorCount} error(s)', ['id' => 'empty-package', 'errorCount' => 1]);

		$result = $this->validator(logger: $logger)->validateShipped(['id' => 'empty-package', 'files' => []]);

		self::assertFalse(condition: $result['valid']);
		self::assertSame(expected: ['package.json is missing.'], actual: $result['errors']);
	}//end testAShippedPackageIsCheckedAndLogged()
}//end class
