<?php

/**
 * The declaration, read live, and held against the three sources that repeat it.
 *
 * 🔴 THE DRIFT HALF IS THE POINT. `check()` is small enough to read; what was
 * actually broken is that four files stated dossiq's requirements and three of
 * them were wrong. So `testSourcesAgree` parses `composer.json`,
 * `appinfo/info.xml` and the README table and holds each to
 * `Prerequisites`. A test that only exercised `check()` would have let the
 * README go on saying Nextcloud 28 forever.
 *
 * The extension reading is driven through the injected checker rather than
 * through `extension_loaded()`, because a test that asserts zip is present on
 * a rig that has zip cannot fail. Both answers are asked for by name.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit
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
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit;

use OCA\Dossiq\Prerequisites;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * One declaration, read live and mirrored nowhere else.
 *
 * @covers \OCA\Dossiq\Prerequisites
 */
class PrerequisitesTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 2);
	}//end root()

	/**
	 * An app manager that answers for a named set of installed apps.
	 *
	 * Doubled with `onlyMethods`, so the double cannot grow a method the real
	 * interface does not have.
	 *
	 * @param array<int, string> $installed The apps that are there.
	 *
	 * @return IAppManager The double.
	 */
	private function appManager(array $installed): IAppManager {
		$appManager = $this->getMockBuilder(IAppManager::class)
			->disableOriginalConstructor()
			->onlyMethods(['isInstalled'])
			->getMockForAbstractClass();

		$appManager->method('isInstalled')
			->willReturnCallback(
				static fn (string $appId): bool => in_array($appId, $installed, true)
			);

		return $appManager;
	}//end appManager()

	/**
	 * One extension row out of a reading.
	 *
	 * @param array<string, mixed> $report The reading.
	 * @param string               $name   The extension.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function extension(array $report, string $name): array {
		foreach ($report['extensions'] as $row) {
			if ($row['name'] === $name) {
				return $row;
			}
		}

		$this->fail(sprintf('"%s" must be a declared extension', $name));
	}//end extension()

	/**
	 * A missing extension is named, and a present one is not.
	 *
	 * @return void
	 */
	public function testAMissingExtensionIsNamed(): void {
		$report = (new Prerequisites())->check(
			$this->appManager(['openregister']),
			static fn (string $name): bool => $name !== 'zip',
		);

		$this->assertFalse(
			$this->extension($report, 'zip')['present'],
			'an absent extension must report missing'
		);
		// The control. Without it "false" could mean the checker is never
		// called, and every extension would read missing on every instance.
		$this->assertTrue(
			$this->extension($report, 'mbstring')['present'],
			'a loaded extension must report present'
		);
		$this->assertNotSame(
			'',
			$this->extension($report, 'zip')['why'],
			'every extension must say what it is for'
		);
	}//end testAMissingExtensionIsNamed()

	/**
	 * The required app is reported missing when it is not installed.
	 *
	 * @return void
	 */
	public function testTheRequiredAppIsReported(): void {
		$report = (new Prerequisites())->check($this->appManager([]));
		$required = $report['apps']['required'];

		$this->assertSame('openregister', $required[0]['id']);
		$this->assertFalse($required[0]['present']);

		$withIt = (new Prerequisites())->check($this->appManager(['openregister']));
		$this->assertTrue($withIt['apps']['required'][0]['present']);
	}//end testTheRequiredAppIsReported()

	/**
	 * An optional app that is absent is listed with what it would have added.
	 *
	 * @return void
	 */
	public function testAnOptionalAppSaysWhatItUnlocks(): void {
		$report = (new Prerequisites())->check($this->appManager(['openregister']));

		$rows = array_column($report['apps']['optional'], null, 'id');
		$this->assertArrayHasKey('hrmq', $rows, 'the hours app must be listed');
		$this->assertFalse($rows['hrmq']['present']);
		$this->assertNotSame('', $rows['hrmq']['unlocks']);

		foreach ($report['apps']['optional'] as $row) {
			$this->assertNotSame(
				'',
				$row['unlocks'],
				sprintf('"%s" must say what it unlocks', $row['id'])
			);
		}
	}//end testAnOptionalAppSaysWhatItUnlocks()

	/**
	 * A PHP older than the declared minimum reports missing.
	 *
	 * @return void
	 */
	public function testAnOldPhpIsReported(): void {
		$prerequisites = new Prerequisites();

		$tooOld = $prerequisites->check(
			$this->appManager([]),
			static fn (): bool => true,
			80200
		);
		$this->assertFalse($tooOld['php']['present']);

		$new = $prerequisites->check(
			$this->appManager([]),
			static fn (): bool => true,
			80300
		);
		$this->assertTrue($new['php']['present']);
	}//end testAnOldPhpIsReported()

	/**
	 * The declared minimum converts to the PHP_VERSION_ID the check compares.
	 *
	 * @return void
	 */
	public function testThePhpMinimumConverts(): void {
		$this->assertSame(80300, Prerequisites::phpMinVersionId());
	}//end testThePhpMinimumConverts()

	/**
	 * `composer.json` requires exactly what the declaration names.
	 *
	 * @return void
	 */
	public function testComposerAgrees(): void {
		$composer = json_decode(
			file_get_contents($this->root() . '/composer.json'),
			true
		);
		$require = $composer['require'];

		$this->assertSame(
			'^' . Prerequisites::PHP_MIN,
			$require['php'],
			'composer.json must require the declared PHP'
		);

		$declared = array_keys(Prerequisites::EXTENSIONS);
		sort($declared);

		$required = [];
		foreach (array_keys($require) as $package) {
			if (str_starts_with($package, 'ext-')) {
				$required[] = substr($package, 4);
			}
		}
		sort($required);

		$this->assertSame(
			$declared,
			$required,
			'composer.json and the declaration must name the same extensions; a '
			. 'declared extension composer does not require lets an install '
			. 'succeed and then report itself broken'
		);
	}//end testComposerAgrees()

	/**
	 * `appinfo/info.xml` declares the same PHP and the same Nextcloud range.
	 *
	 * @return void
	 */
	public function testInfoXmlAgrees(): void {
		$xml = simplexml_load_file($this->root() . '/appinfo/info.xml');
		$dependencies = $xml->dependencies;

		$this->assertSame(
			Prerequisites::PHP_MIN,
			(string)$dependencies->php['min-version'],
			'info.xml must declare the declared PHP minimum'
		);
		$this->assertSame(
			Prerequisites::NEXTCLOUD_MIN,
			(string)$dependencies->nextcloud['min-version'],
			'info.xml must declare the declared Nextcloud minimum'
		);
		$this->assertSame(
			Prerequisites::NEXTCLOUD_MAX,
			(string)$dependencies->nextcloud['max-version'],
			'info.xml must declare the declared Nextcloud maximum'
		);
	}//end testInfoXmlAgrees()

	/**
	 * The README Requirements table says what the declaration says.
	 *
	 * The table is delimited by marker comments, so this reads the generated
	 * block and never the whole README: a Nextcloud version named in prose
	 * elsewhere is somebody's sentence, not a requirement, and matching it
	 * here would make the test pass on the wrong text.
	 *
	 * @return void
	 */
	public function testTheReadmeTableAgrees(): void {
		$readme = file_get_contents($this->root() . '/README.md');

		$start = strpos($readme, '<!-- prerequisites:start -->');
		$end = strpos($readme, '<!-- prerequisites:end -->');
		$this->assertNotFalse($start, 'the README must carry the generated block');
		$this->assertNotFalse($end, 'the generated block must be closed');

		$table = substr($readme, $start, ($end - $start));

		$this->assertStringContainsString(
			sprintf(
				'| Nextcloud | %s – %s |',
				Prerequisites::NEXTCLOUD_MIN,
				Prerequisites::NEXTCLOUD_MAX
			),
			$table,
			'the README must state the declared Nextcloud range'
		);
		$this->assertStringContainsString(
			sprintf('| PHP | %s+ |', Prerequisites::PHP_MIN),
			$table,
			'the README must state the declared PHP minimum'
		);
		$this->assertStringContainsString(
			sprintf(
				'| PHP extensions | %s |',
				implode(', ', array_keys(Prerequisites::EXTENSIONS))
			),
			$table,
			'the README must list the declared extensions'
		);
		$this->assertStringNotContainsString(
			'| Nextcloud | 28',
			$table,
			'the README said 28, which is a Nextcloud that does not run PHP 8.3'
		);
	}//end testTheReadmeTableAgrees()
}//end class
