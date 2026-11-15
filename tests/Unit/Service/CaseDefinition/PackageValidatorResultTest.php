<?php

/**
 * The whole answer PackageValidator::validatePackage gives, for each way out.
 *
 * Pins the result's keys and values for an archive that does not open, a
 * package without a manifest, a manifest that is not JSON, and a package
 * that validates, plus the one log line a full validation writes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageValidator
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageIds
 */
class PackageValidatorResultTest extends TestCase {

	/**
	 * The info lines written.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * Each early way out answers the whole result and logs nothing.
	 *
	 * @return void
	 */
	public function testEveryEarlyRefusalAnswersTheWholeResult(): void {
		$refused = static fn (array $errors): array => ['valid' => false, 'errors' => $errors, 'warnings' => [], 'manifest' => null, 'conflicts' => []];

		$notZip = tempnam(sys_get_temp_dir(), 'pv_');
		file_put_contents((string)$notZip, 'plain text');
		self::assertSame($refused(['Failed to open ZIP archive: error code ' . ZipArchive::ER_NOZIP]), $this->validator()->validatePackage((string)$notZip));
		unlink((string)$notZip);

		self::assertSame($refused(['Missing required file: manifest.json']), $this->validator()->validatePackage($this->package(['other.json' => '{}'])));
		self::assertSame($refused(['Invalid JSON in manifest.json: Syntax error']), $this->validator()->validatePackage($this->package(['manifest.json' => '{nope'])));
		self::assertSame([], $this->logged);
	}//end testEveryEarlyRefusalAnswersTheWholeResult()

	/**
	 * A manifest that parses is validated, answered with its issues, and logged.
	 *
	 * @return void
	 */
	public function testAParsedManifestIsAnsweredAndLogged(): void {
		$manifest = ['version' => '1.0', 'exportDate' => '2026-09-18T00:00:00+00:00', 'caseType' => ['id' => 'ct-1', 'slug' => 'x'], 'components' => ['statuses']];
		$result = $this->validator()->validatePackage($this->package(['manifest.json' => json_encode($manifest), 'statuses.json' => '{}']));

		self::assertSame(['valid', 'errors', 'warnings', 'manifest', 'conflicts'], array_keys($result));
		self::assertSame($manifest, $result['manifest']);
		self::assertSame([], $result['conflicts']);
		self::assertSame(($result['errors'] === []), $result['valid']);
		self::assertSame(
			[[
				'Validated case definition package: {valid}, errors: {errorCount}, warnings: {warningCount}',
				['valid' => ($result['valid'] === true ? 'true' : 'false'), 'errorCount' => count($result['errors']), 'warningCount' => count($result['warnings'])],
			]],
			$this->logged
		);
	}//end testAParsedManifestIsAnsweredAndLogged()

	/**
	 * A ZIP holding these entries.
	 *
	 * @param array<string, string> $entries Name to content.
	 *
	 * @return string The path.
	 */
	private function package(array $entries): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'pv_');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();

		return $path;
	}//end package()

	/**
	 * The validator over a recording logger.
	 *
	 * @return PackageValidator
	 */
	private function validator(): PackageValidator {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new PackageValidator($logger, new PackageIds());
	}//end validator()
}//end class
