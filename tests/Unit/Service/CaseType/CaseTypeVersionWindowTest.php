<?php

/**
 * Unit tests for CaseTypeVersionWindow::deprecate(): closing a superseded version.
 *
 * 🔴 WHAT THESE GUARD IS A VERSION REPORTED CLOSED THAT IS STILL OPEN. A
 * deprecation that could not be written must say so, and must not hand back a
 * `validUntil` the store never received: the caller shows that date as the day
 * the version stopped taking cases.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use DateTime;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionWindow;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for CaseTypeVersionWindow.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeVersionWindow
 */
class CaseTypeVersionWindowTest extends TestCase {

	/**
	 * The saves the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * A settings service over the given object service.
	 *
	 * @param object|null $objectService The object service, or null for none.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				default => $default,
			}
		);

		return $settings;
	}//end settings()

	/**
	 * An object service holding one case type, whose saves fail or are recorded.
	 *
	 * @param array<string, mixed> $row      The case type.
	 * @param bool                 $failSave Whether a save throws.
	 *
	 * @return object
	 */
	private function objectService(array $row, bool $failSave=false): object {
		return new class($row, $failSave, $this->saves) {
			/**
			 * @param array<string, mixed>             $row      The case type.
			 * @param bool                             $failSave Whether a save throws.
			 * @param array<int, array<string, mixed>> $saves    The recorded saves.
			 */
			public function __construct(private array $row, private bool $failSave, public array &$saves) {
			}//end __construct()

			/**
			 * Read the one case type.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register=null, $schema=null): ?array {
				return ($id === ($this->row['id'] ?? null) ? $this->row : null);
			}//end find()

			/**
			 * Save, or fail.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register=null, $schema=null): array {
				if ($this->failSave === true) {
					throw new RuntimeException('database gone');
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * The window, reading through one settings service and writing through another.
	 *
	 * @param SettingsService      $reads  What the store reads through.
	 * @param SettingsService      $writes What the window writes through.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CaseTypeVersionWindow
	 */
	private function window(SettingsService $reads, SettingsService $writes, ?LoggerInterface $logger=null): CaseTypeVersionWindow {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-15 10:00:00'));

		return new CaseTypeVersionWindow(
			settingsService: $writes,
			store: new CaseTypeStore($reads),
			time: $time,
			logger: ($logger ?? new NullLogger()),
		);
	}//end window()

	/**
	 * A superseded version closes today, and the store receives that day.
	 *
	 * @return void
	 */
	public function testASupersededVersionClosesToday(): void {
		$settings = $this->settings($this->objectService(['id' => 'ct-1', 'isDraft' => false, 'supersededBy' => 'ct-2']));

		$result = $this->window($settings, $settings)->deprecate(caseTypeId: 'ct-1');

		$this->assertSame(['deprecated' => true, 'findings' => [], 'validUntil' => '2026-09-15'], $result);
		$this->assertCount(1, $this->saves);
		$this->assertSame('2026-09-15', $this->saves[0]['object']['validUntil']);
		$this->assertSame('dossiq', $this->saves[0]['register']);
		$this->assertSame('caseType', $this->saves[0]['schema']);
	}//end testASupersededVersionClosesToday()

	/**
	 * A case type that cannot be read is refused by name, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIsRefused(): void {
		$settings = $this->settings($this->objectService(['id' => 'ct-1']));

		$this->assertSame(
			['deprecated' => false, 'findings' => ['This case type could not be read.'], 'validUntil' => null],
			$this->window($settings, $settings)->deprecate(caseTypeId: 'ct-missing')
		);
		$this->assertSame([], $this->saves);
	}//end testAnUnreadableCaseTypeIsRefused()

	/**
	 * A failed save is logged and reported, and no closing day is handed back.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsReportedNotClosed(): void {
		$settings = $this->settings($this->objectService(['id' => 'ct-1', 'supersededBy' => 'ct-2'], failSave: true));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Case type version window: save failed', ['exception' => 'database gone']);

		$this->assertSame(
			['deprecated' => false, 'findings' => ['The case type could not be saved.'], 'validUntil' => null],
			$this->window($settings, $settings, $logger)->deprecate(caseTypeId: 'ct-1')
		);
	}//end testAFailedSaveIsReportedNotClosed()

	/**
	 * Without a store to write to, the deprecation is reported as not saved.
	 *
	 * @return void
	 */
	public function testNoStoreToWriteToIsReportedNotClosed(): void {
		$reads = $this->settings($this->objectService(['id' => 'ct-1', 'supersededBy' => 'ct-2']));

		$this->assertSame(
			['deprecated' => false, 'findings' => ['The case type could not be saved.'], 'validUntil' => null],
			$this->window($reads, $this->settings(null))->deprecate(caseTypeId: 'ct-1')
		);
		$this->assertSame([], $this->saves);
	}//end testNoStoreToWriteToIsReportedNotClosed()
}//end class
