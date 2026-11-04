<?php

/**
 * How the template library reads a templates directory, including broken ones.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\TemplateLibrary
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\TemplateLibrary;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TemplateLibraryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lists and loads templates from a temporary directory.
 *
 * @covers \OCA\Dossiq\Service\TemplateLibraryService
 */
class TemplateLibraryDirectoryTest extends TestCase {

	/**
	 * The temporary templates directory.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Every warning the service logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Create an empty temporary directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dossiq-templates-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * Remove the temporary directory and everything in it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (scandir($this->dir) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				unlink($this->dir . '/' . $entry);
			}
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * A service reading the given directory, recording its warnings.
	 *
	 * @param string $dir The templates directory.
	 *
	 * @return TemplateLibraryService
	 */
	private function service(string $dir): TemplateLibraryService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new TemplateLibraryService(
			settingsService: $this->createMock(SettingsService::class),
			logger: $logger,
			templatesDir: $dir
		);
	}//end service()

	/**
	 * A missing directory lists nothing and loads nothing.
	 *
	 * @return void
	 */
	public function testAMissingDirectoryListsAndLoadsNothing(): void {
		$service = $this->service(dir: $this->dir . '/missing');

		self::assertSame([], $service->listTemplates());
		self::assertNull($service->loadTemplate(templateId: 'a'));
		self::assertSame([], $this->warnings);
	}//end testAMissingDirectoryListsAndLoadsNothing()

	/**
	 * An empty directory lists nothing and loads nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyDirectoryListsAndLoadsNothing(): void {
		$service = $this->service(dir: $this->dir);

		self::assertSame([], $service->listTemplates());
		self::assertNull($service->loadTemplate(templateId: 'a'));
	}//end testAnEmptyDirectoryListsAndLoadsNothing()

	/**
	 * Unreadable, invalid and non-object files are skipped; only the list warns
	 * about the invalid ones. A file without an id goes by its file name.
	 *
	 * @return void
	 */
	public function testBrokenFilesAreSkipped(): void {
		file_put_contents($this->dir . '/a.json', json_encode(['id' => 'alpha', 'title' => 'Alpha', 'category' => 'permits', 'version' => '2.0.0', 'fills' => ['x' => 1]]));
		file_put_contents($this->dir . '/b.json', '{"title": "No id"}');
		file_put_contents($this->dir . '/c.json', '{not json');
		file_put_contents($this->dir . '/d.json', '5');
		file_put_contents($this->dir . '/e.txt', '{"id": "text"}');
		symlink($this->dir . '/nowhere', $this->dir . '/f.json');
		$service = $this->service(dir: $this->dir);

		self::assertSame(
			[
				[
					'id' => 'alpha',
					'title' => 'Alpha',
					'description' => '',
					'category' => 'permits',
					'version' => '2.0.0',
					'file' => 'a.json',
					'fills' => [1],
				],
				[
					'id' => 'b',
					'title' => 'No id',
					'description' => '',
					'category' => 'general',
					'version' => '1.0.0',
					'file' => 'b.json',
					'fills' => [],
				],
			],
			@$service->listTemplates()
		);
		self::assertSame(
			[
				['Invalid template file: c.json', ['app' => 'dossiq']],
				['Invalid template file: d.json', ['app' => 'dossiq']],
			],
			$this->warnings
		);

		self::assertSame('Alpha', (@$service->loadTemplate(templateId: 'alpha'))['title']);
		self::assertSame(['title' => 'No id'], @$service->loadTemplate(templateId: 'b'));
		self::assertNull(@$service->loadTemplate(templateId: 'c'));
		self::assertNull(@$service->loadTemplate(templateId: 'd'));
		self::assertNull(@$service->loadTemplate(templateId: 'text'));
		self::assertNull(@$service->loadTemplate(templateId: 'f'));
		self::assertCount(2, $this->warnings);
	}//end testBrokenFilesAreSkipped()
}//end class
