<?php

/**
 * Golden test for a settings save: which keys are written, what is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the writes and the refusal of SettingsService::updateSettings() per posted body.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 * @covers \OCA\Dossiq\Woo\WooReportSwitches
 * @covers \OCA\Dossiq\Service\Settings\RenamedSettingKeys
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class SettingsSaveGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Posted bodies, with what is stored before the save.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
	 */
	public static function saves(): array {
		return [
			'unrelated key' => [['register' => '5'], []],
			'unknown key is not written' => [['notAKey' => 'x'], []],
			'unrelated key, switch stored on without readers' => [['register' => '5'], ['reviewerThroughputReport' => 'true']],
			'old switch key on, readers exist' => [['wooReviewerThroughputReport' => 'true', 'wooReviewerThroughputReaders' => 'readers'], []],
			'old switch key on, no readers' => [['wooReviewerThroughputReport' => 'true'], []],
			'old switch key on, stored readers' => [['wooReviewerThroughputReport' => '1'], ['reviewerThroughputReaders' => 'readers']],
			'old switch key on, unknown group' => [['wooReviewerThroughputReport' => 'on', 'wooReviewerThroughputReaders' => 'ghosts'], []],
			'current key wins over old key' => [['reviewerThroughputReport' => 'false', 'wooReviewerThroughputReport' => 'true'], []],
			'current switch on, no readers' => [['reviewerThroughputReport' => 'yes'], []],
			'old readers key only' => [['wooReviewerThroughputReaders' => 'readers'], ['reviewerThroughputReport' => 'true']],
			'old readers key blank, switch stored on' => [['wooReviewerThroughputReaders' => ' '], ['reviewerThroughputReport' => 'true']],
			'old parties key' => [['wooCollectionPartiesReport' => 'true'], []],
			'old and current parties key' => [['wooCollectionPartiesReport' => 'true', 'collectionPartiesReport' => 'false'], []],
			'switch off with old key' => [['wooReviewerThroughputReport' => 'false'], []],
		];
	}//end saves()

	/**
	 * The save writes and refuses as recorded.
	 *
	 * @param array<string, mixed>  $posted The posted settings.
	 * @param array<string, string> $stored What is stored before the save.
	 *
	 * @return void
	 *
	 * @dataProvider saves
	 */
	public function testTheSaveWritesAsRecorded(array $posted, array $stored): void {
		$writes = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored, &$writes): bool {
				$writes[] = [$key => $value];
				$stored[$key] = $value;
				return true;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'readers');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => $id === IGroupManager::class ? $groups : null);

		$service = new SettingsService(
			appConfig: $appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		try {
			$answer = array_filter($service->updateSettings(data: $posted), static fn ($v): bool => $v !== '' && $v !== false);
		} catch (RefusedException $e) {
			$answer = ['refused' => $e->getMessage()];
		}

		$this->assertMatchesGolden(
			'settings-save/' . preg_replace('/[^a-z]+/', '-', strtolower((string)$this->dataName())),
			['writes' => $writes, 'answer' => $answer]
		);
	}//end testTheSaveWritesAsRecorded()
}//end class
