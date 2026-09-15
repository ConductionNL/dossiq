<?php

/**
 * A personal stage is private, changes no status, and joins no report.
 *
 * The first test below is the one that makes the other three true rather than
 * merely currently-true: the service has no way to reach the object store, so
 * a personal stage CANNOT be written onto the case, cannot appear on somebody
 * else's screen and cannot be grouped by. That is a property of where the data
 * lives, not of a filter somebody remembered.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\PersonalStageService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\Dossiq\Service\Queue\PersonalStageService
 */
class PersonalStageTest extends TestCase {
	/**
	 * The preferences this run holds, keyed user and key.
	 *
	 * @var array<string, string>
	 */
	private array $prefs = [];

	/**
	 * The service under test.
	 *
	 * @var PersonalStageService
	 */
	private PersonalStageService $stages;

	/**
	 * Build the service over an array-backed config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->prefs = [];

		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string
				=> ($this->prefs[$uid . '|' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->prefs[$uid . '|' . $key] = $value;

				return true;
			}
		);

		$this->stages = new PersonalStageService(
			userConfig: $config,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The service cannot reach the case, so it cannot change one.
	 *
	 * @return void
	 */
	public function testTheServiceCannotReachTheObjectStore(): void {
		$parameters = (new ReflectionClass(PersonalStageService::class))
			->getConstructor()
			->getParameters();

		$types = array_map(
			static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
			$parameters
		);

		self::assertNotContains(
			SettingsService::class,
			$types,
			'A personal stage service that can reach the register can write on the case.'
		);
		self::assertSame(
			[IUserConfig::class, LoggerInterface::class],
			$types,
			'The stage lives in the reader\'s own preferences and nowhere else.'
		);
	}

	/**
	 * A stage the reader set is theirs to read back.
	 *
	 * @return void
	 */
	public function testAStageIsReadBack(): void {
		$this->stages->set(userId: 'alice', caseId: 'case-1', stage: 'Wachten op advies');

		self::assertSame('Wachten op advies', $this->stages->get(userId: 'alice', caseId: 'case-1'));
	}

	/**
	 * Another person sees nothing of it.
	 *
	 * @return void
	 */
	public function testAnotherPersonSeesNothing(): void {
		$this->stages->set(userId: 'alice', caseId: 'case-1', stage: 'Wachten op advies');

		self::assertSame('', $this->stages->get(userId: 'bob', caseId: 'case-1'));
		self::assertSame([], $this->stages->all(userId: 'bob'));
	}

	/**
	 * Clearing a stage removes it rather than storing an empty one.
	 *
	 * @return void
	 */
	public function testClearingAStageRemovesIt(): void {
		$this->stages->set(userId: 'alice', caseId: 'case-1', stage: 'Wachten op advies');
		$this->stages->set(userId: 'alice', caseId: 'case-1', stage: '');

		self::assertSame('', $this->stages->get(userId: 'alice', caseId: 'case-1'));
		self::assertSame([], $this->stages->all(userId: 'alice'));
	}

	/**
	 * One reader can hold stages on several cases without them mixing.
	 *
	 * @return void
	 */
	public function testStagesOnSeveralCasesStaySeparate(): void {
		$this->stages->set(userId: 'alice', caseId: 'case-1', stage: 'Wachten op advies');
		$this->stages->set(userId: 'alice', caseId: 'case-2', stage: 'Bijna klaar');

		self::assertSame(
			['case-1' => 'Wachten op advies', 'case-2' => 'Bijna klaar'],
			$this->stages->all(userId: 'alice')
		);
	}

	/**
	 * A very long stage is cut rather than refused.
	 *
	 * @return void
	 */
	public function testALongStageIsCut(): void {
		$stored = $this->stages->set(userId: 'alice', caseId: 'case-1', stage: str_repeat('x', 400));

		self::assertSame(PersonalStageService::MAX_LENGTH, mb_strlen($stored));
	}

	/**
	 * Unreadable preferences read as no stage rather than as a crash.
	 *
	 * @return void
	 */
	public function testUnreadablePreferencesReadAsNoStage(): void {
		$this->prefs['alice|' . PersonalStageService::PREF_STAGES] = 'not json at all';

		self::assertSame('', $this->stages->get(userId: 'alice', caseId: 'case-1'));
	}
}//end class
