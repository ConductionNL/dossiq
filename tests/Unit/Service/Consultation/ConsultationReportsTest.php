<?php

/**
 * The due-soon and bottleneck reports over waiting and recent advice requests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Service\Consultation\ConsultationReports;
use OCA\Dossiq\Service\Consultation\ConsultationTerms;

/**
 * What each report is built from, and the reader filter.
 *
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationReports
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationRepository
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationTerms
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ConsultationReportsTest extends TestCase {

	/**
	 * A repository over the given stand-in, or over no OpenRegister at all.
	 *
	 * @param array<string, string> $config App config values.
	 */
	private function repository(?FakeConsultationStore $objects, array $config = ['register' => '5', 'consultation_schema' => '7'], ?LoggerInterface $logger = null): ConsultationRepository {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($objects !== null);
		$apps->method('isEnabledForUser')->willReturn($objects !== null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$appConfig->method('hasKey')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$logger ??= $this->createMock(LoggerInterface::class);

		return new ConsultationRepository(new SettingsService($appConfig, $apps, $container, $logger), $logger);
	}

	private function reports(FakeConsultationStore $objects): ConsultationReports {
		$repository = $this->repository($objects);
		$appConfig  = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$appConfig->method('hasKey')->willReturn(true);
		$settings = new SettingsService($appConfig, $this->createMock(IAppManager::class), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		return new ConsultationReports($repository, new ConsultationTerms($settings));
	}

	private function day(int $offset): string {
		return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
	}

	public function testDueSoonListsWaitingRequestsDueWithinTheWarningOffset(): void {
		$objects = new FakeConsultationStore(
			[
				['id' => 'soon', 'status' => 'open', 'latestResponseDate' => $this->day(2), 'adviceAuthority' => 'brandweer'],
				['id' => 'later', 'status' => 'received', 'latestResponseDate' => $this->day(20)],
				['id' => 'done', 'status' => 'closed', 'latestResponseDate' => $this->day(1)],
			]
		);

		$this->assertSame(
			['results' => [['id' => 'soon', 'status' => 'open', 'latestResponseDate' => $this->day(2), 'adviceAuthority' => 'brandweer']], 'warningOffsetDays' => 5],
			$this->reports($objects)->getDueSoonConsultations()
		);
	}

	public function testDueSoonKeepsOnlyWhatTheReaderMayRead(): void {
		$objects = new FakeConsultationStore(
			[['id' => 'mine', 'status' => 'open', 'latestResponseDate' => $this->day(1)], ['id' => 'theirs', 'status' => 'open', 'latestResponseDate' => $this->day(1)]]
		);

		$report = $this->reports($objects)->getDueSoonConsultations(static fn (array $c): bool => $c['id'] === 'mine');

		$this->assertSame(['mine'], array_column($report['results'], 'id'));
	}

	public function testBottlenecksAreComputedOverTheRecentRequests(): void {
		$objects = new FakeConsultationStore(
			[
				['id' => 'a', 'adviceAuthority' => 'welstand', 'createdAt' => $this->day(-3), 'status' => 'open', 'latestResponseDate' => $this->day(-1)],
				['id' => 'b', 'adviceAuthority' => 'welstand', 'createdAt' => $this->day(-2), 'status' => 'open', 'latestResponseDate' => $this->day(5)],
				['id' => 'old', 'adviceAuthority' => 'ggd', 'createdAt' => $this->day(-60), 'status' => 'open', 'latestResponseDate' => $this->day(-40)],
			]
		);

		$report = $this->reports($objects)->getBottlenecks();

		$this->assertSame(30, $report['windowDays']);
		$this->assertSame([['adviceAuthority' => 'welstand', 'total' => 2, 'late' => 1, 'lateShare' => 0.5, 'flagged' => true]], $report['bodies']);
	}
}
