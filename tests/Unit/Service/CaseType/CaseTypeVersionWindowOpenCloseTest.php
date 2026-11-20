<?php

/**
 * Unit tests for CaseTypeVersionWindow::open() and ::closePrevious(): when a version starts and its predecessor stops.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for opening a version's window and closing its predecessor's.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeVersionWindow
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseTypeVersionWindowOpenCloseTest extends TestCase {

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
	 * An object service holding the given case types, whose saves fail or are recorded.
	 *
	 * @param array<string, array<string, mixed>> $rows     The case types by id.
	 * @param bool                                $failSave Whether a save throws.
	 *
	 * @return object
	 */
	private function objectService(array $rows, bool $failSave=false): object {
		return new class($rows, $failSave, $this->saves) {
			/**
			 * @param array<string, array<string, mixed>> $rows     The case types by id.
			 * @param bool                                $failSave Whether a save throws.
			 * @param array<int, array<string, mixed>>    $saves    The recorded saves.
			 */
			public function __construct(private array $rows, private bool $failSave, public array &$saves) {
			}//end __construct()

			/**
			 * Read one case type.
			 *
			 * @param string                 $id      The id.
			 * @param ObjectFindOptions|null $options The options.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				return ($this->rows[$id] ?? null);
			}//end find()

			/**
			 * Save, or fail.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options The options.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failSave === true) {
					throw new RuntimeException('database gone');
				}

				$this->saves[] = ['object' => $object, 'register' => $options->target->register, 'schema' => $options->target->schema];
				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * The window over one settings service.
	 *
	 * @param SettingsService      $settings The settings service.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return CaseTypeVersionWindow
	 */
	private function window(SettingsService $settings, ?LoggerInterface $logger=null): CaseTypeVersionWindow {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-15 23:30:00'));

		return new CaseTypeVersionWindow(
			settingsService: $settings,
			store: new CaseTypeStore($settings),
			time: $time,
			logger: ($logger ?? new NullLogger()),
		);
	}//end window()

	/**
	 * Today is the clock's day, spelled as the schema spells a date.
	 *
	 * @return void
	 */
	public function testTodayIsTheClocksDay(): void {
		$this->assertSame('2026-09-15', $this->window($this->settings(null))->today());
	}//end testTodayIsTheClocksDay()

	/**
	 * Blank dates are filled with today.
	 *
	 * @return void
	 */
	public function testOpenFillsBlankDatesWithToday(): void {
		$this->assertSame(
			['id' => 'ct-2', 'validFrom' => '2026-09-15', 'versionDate' => '2026-09-15'],
			$this->window($this->settings(null))->open(caseType: ['id' => 'ct-2', 'validFrom' => '  '])
		);
	}//end testOpenFillsBlankDatesWithToday()

	/**
	 * Dates an author typed are kept, including a future start.
	 *
	 * @return void
	 */
	public function testOpenKeepsTypedDates(): void {
		$caseType = ['validFrom' => '2026-10-01', 'versionDate' => '2026-09-01'];

		$this->assertSame($caseType, $this->window($this->settings(null))->open(caseType: $caseType));
	}//end testOpenKeepsTypedDates()

	/**
	 * The previous version is linked forward and closed on the day the successor takes effect.
	 *
	 * @return void
	 */
	public function testClosePreviousLinksAndClosesOnTheEffectiveDay(): void {
		$settings = $this->settings($this->objectService(['ct-1' => ['id' => 'ct-1', 'isDraft' => false]]));

		$this->window($settings)->closePrevious(caseType: ['previousVersion' => 'ct-1'], caseTypeId: 'ct-2', takesEffect: '2026-10-01');

		$this->assertSame(
			[['object' => ['id' => 'ct-1', 'isDraft' => false, 'supersededBy' => 'ct-2', 'validUntil' => '2026-10-01'], 'register' => 'dossiq', 'schema' => 'caseType']],
			$this->saves
		);
	}//end testClosePreviousLinksAndClosesOnTheEffectiveDay()

	/**
	 * A previous version referenced as an expanded object is found by its id.
	 *
	 * @return void
	 */
	public function testClosePreviousReadsAnExpandedReference(): void {
		$settings = $this->settings($this->objectService(['ct-1' => ['id' => 'ct-1']]));

		$this->window($settings)->closePrevious(caseType: ['previousVersion' => ['id' => 'ct-1']], caseTypeId: 'ct-2', takesEffect: '2026-10-01');

		$this->assertCount(1, $this->saves);
		$this->assertSame('ct-2', $this->saves[0]['object']['supersededBy']);
	}//end testClosePreviousReadsAnExpandedReference()

	/**
	 * A closing day somebody already chose is never moved, and no effective day leaves it open-ended.
	 *
	 * @return void
	 */
	public function testClosePreviousNeverMovesAChosenClosingDay(): void {
		$settings = $this->settings(
			$this->objectService(
				[
					'ct-1' => ['id' => 'ct-1', 'validUntil' => '2026-12-31'],
					'ct-3' => ['id' => 'ct-3'],
				]
			)
		);
		$window = $this->window($settings);

		$window->closePrevious(caseType: ['previousVersion' => 'ct-1'], caseTypeId: 'ct-2', takesEffect: '2026-10-01');
		$window->closePrevious(caseType: ['previousVersion' => 'ct-3'], caseTypeId: 'ct-4', takesEffect: '');

		$this->assertSame(['id' => 'ct-1', 'validUntil' => '2026-12-31', 'supersededBy' => 'ct-2'], $this->saves[0]['object']);
		$this->assertSame(['id' => 'ct-3', 'supersededBy' => 'ct-4'], $this->saves[1]['object']);
	}//end testClosePreviousNeverMovesAChosenClosingDay()

	/**
	 * No previous version, or one pointing at itself, closes nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testNoPreviousVersionClosesNothing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');
		$window = $this->window($settings);

		$window->closePrevious(caseType: [], caseTypeId: 'ct-2', takesEffect: '2026-10-01');
		$window->closePrevious(caseType: ['previousVersion' => ' ct-2 '], caseTypeId: 'ct-2', takesEffect: '2026-10-01');
	}//end testNoPreviousVersionClosesNothing()

	/**
	 * A previous version that cannot be read is logged by id and not written.
	 *
	 * @return void
	 */
	public function testAnUnreadablePreviousVersionIsLoggedNotWritten(): void {
		$settings = $this->settings($this->objectService([]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Case type publish: the previous version could not be read, so it was not closed',
				['caseType' => 'ct-2', 'previousVersion' => 'ct-1']
			);

		$this->window($settings, $logger)->closePrevious(caseType: ['previousVersion' => 'ct-1'], caseTypeId: 'ct-2', takesEffect: '2026-10-01');

		$this->assertSame([], $this->saves);
	}//end testAnUnreadablePreviousVersionIsLoggedNotWritten()

	/**
	 * A failed save of the previous version is logged as still open, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedSaveOfThePreviousVersionIsLogged(): void {
		$settings = $this->settings($this->objectService(['ct-1' => ['id' => 'ct-1']], failSave: true));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Case type version window: save failed', ['exception' => 'database gone']);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Case type publish: the previous version stays open for new cases',
				['caseType' => 'ct-2', 'previousVersion' => 'ct-1']
			);

		$this->window($settings, $logger)->closePrevious(caseType: ['previousVersion' => 'ct-1'], caseTypeId: 'ct-2', takesEffect: '2026-10-01');
	}//end testAFailedSaveOfThePreviousVersionIsLogged()

	/**
	 * A draft has nothing to close, and the version in force cannot be closed before its successor exists.
	 *
	 * @return void
	 */
	public function testDeprecateRefusesADraftAndTheVersionInForce(): void {
		$settings = $this->settings(
			$this->objectService(
				[
					'ct-draft' => ['id' => 'ct-draft', 'isDraft' => true, 'supersededBy' => 'ct-9'],
					'ct-live' => ['id' => 'ct-live', 'isDraft' => false, 'supersededBy' => '  '],
				]
			)
		);
		$window = $this->window($settings);

		$this->assertSame(
			['deprecated' => false, 'findings' => ['A draft is not in use yet, so there is nothing to close. Delete it instead.'], 'validUntil' => null],
			$window->deprecate(caseTypeId: 'ct-draft')
		);
		$this->assertSame(
			['deprecated' => false, 'findings' => ['This is the version new cases are filed under. Publish its successor first.'], 'validUntil' => null],
			$window->deprecate(caseTypeId: 'ct-live')
		);
		$this->assertSame([], $this->saves);
	}//end testDeprecateRefusesADraftAndTheVersionInForce()
}//end class
