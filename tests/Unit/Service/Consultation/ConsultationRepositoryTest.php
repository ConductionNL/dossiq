<?php

/**
 * Where advice requests are read from and deleted in OpenRegister.
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
use RuntimeException;

/**
 * Each query, its scope, and the answers without OpenRegister or configuration.
 *
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationRepository
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ConsultationRepositoryTest extends TestCase {

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

	public function testTheConsultationsOfACaseAreSearchedInTheConfiguredScope(): void {
		$objects = new FakeConsultationStore([['id' => 'a', 'parentCase' => 'c-1'], ['id' => 'b', 'parentCase' => 'c-2']]);

		$this->assertSame([['id' => 'a', 'parentCase' => 'c-1']], $this->repository($objects)->getConsultationsForCase('c-1'));
		$this->assertSame([['searchObjects', ['parentCase' => 'c-1', '_limit' => 100, '@self' => ['register' => 5, 'schema' => 7]]]], $objects->calls);
	}

	public function testOneConsultationIsFoundOrIsNull(): void {
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'b'];
			}
		};
		$repository = $this->repository(new FakeConsultationStore(byId: ['a' => ['id' => 'a'], 'b' => $serialisable]));

		$this->assertSame(['id' => 'a'], $repository->getConsultation('a'));
		$this->assertSame(['id' => 'b'], $repository->getConsultation('b'));
		$this->assertNull($repository->getConsultation('gone'));
	}

	public function testWithoutOpenRegisterOrConfigurationReadsAreEmpty(): void {
		foreach ([$this->repository(null), $this->repository(new FakeConsultationStore([['status' => 'open']]), ['register' => '5'])] as $repository) {
			$this->assertSame([], $repository->getConsultationsForCase('c-1'));
			$this->assertNull($repository->getConsultation('a'));
			$this->assertSame([], $repository->getOverdueConsultations());
			$this->assertSame([], $repository->getWaitingConsultations());
			$this->assertSame([], $repository->getRecentConsultations(30));
		}
	}

	public function testADeleteIsSentAndLogged(): void {
		$objects = new FakeConsultationStore();
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Consultation deleted: a', ['app' => 'dossiq']);

		$this->assertTrue($this->repository($objects, logger: $logger)->deleteConsultation('a'));
		$this->assertSame([['deleteObject', 'a', '5', '7']], $objects->calls);
	}

	public function testADeleteWithoutOpenRegisterOrSchemaThrows(): void {
		try {
			$this->repository(null)->deleteConsultation('a');
			$this->fail('expected a refusal');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister is not available', $e->getMessage());
		}

		$this->expectExceptionObject(new RuntimeException('Consultation schema not configured'));
		$this->repository(new FakeConsultationStore(), ['register' => '5'])->deleteConsultation('a');
	}

	public function testWaitingMeansOpenReceivedOrInHandling(): void {
		$objects = new FakeConsultationStore(
			[['id' => 'o', 'status' => 'open'], ['id' => 'r', 'status' => 'received'], ['id' => 'h', 'status' => 'in_handling'], ['id' => 'c', 'status' => 'closed']]
		);

		$this->assertSame(['o', 'r', 'h'], array_column($this->repository($objects)->getWaitingConsultations(), 'id'));
		$this->assertSame(['open', 'received', 'in_handling'], array_map(static fn (array $call): string => $call[1]['status'], $objects->calls));
	}

	public function testOverdueIsAWaitingRequestPastItsDeadline(): void {
		$objects = new FakeConsultationStore(
			[
				['id' => 'late', 'status' => 'open', 'latestResponseDate' => date('Y-m-d', strtotime('-2 days'))],
				['id' => 'due', 'status' => 'open', 'latestResponseDate' => date('Y-m-d')],
				['id' => 'none', 'status' => 'received'],
				['id' => 'closed', 'status' => 'closed', 'latestResponseDate' => '2000-01-01'],
			]
		);

		$this->assertSame(['late'], array_column($this->repository($objects)->getOverdueConsultations(), 'id'));
	}

	public function testRecentKeepsTheRequestsCreatedWithinTheWindow(): void {
		$objects = new FakeConsultationStore(
			[
				['id' => 'new', 'createdAt' => date('Y-m-d', strtotime('-1 day')).'T09:00:00+00:00'],
				['id' => 'old', 'createdAt' => date('Y-m-d', strtotime('-40 days')).'T09:00:00+00:00'],
				['id' => 'undated'],
			]
		);

		$this->assertSame(['new'], array_column($this->repository($objects)->getRecentConsultations(30), 'id'));
		$this->assertSame(['_order' => ['createdAt' => 'DESC'], '_limit' => 1000, '@self' => ['register' => 5, 'schema' => 7]], $objects->calls[0][1]);
	}

	public function testTheNextNumberFollowsThisYearsHighest(): void {
		$prefix  = 'ADV-'.date('Y').'-';
		$objects = new FakeConsultationStore([['consultationNumber' => $prefix.'0041']]);

		$this->assertSame($prefix.'0042', $this->repository($objects)->nextConsultationNumber($objects, '5', '7'));
		$this->assertSame(
			['consultationNumber' => $prefix.'%', '_order' => ['consultationNumber' => 'DESC'], '_limit' => 1, '@self' => ['register' => 5, 'schema' => 7]],
			$objects->calls[0][1]
		);
		$this->assertSame($prefix.'0001', $this->repository($objects)->nextConsultationNumber(new FakeConsultationStore(), '5', '7'));
	}
}
