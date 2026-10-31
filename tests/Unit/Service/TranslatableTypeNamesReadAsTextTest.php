<?php

/**
 * Translatable type names read as text where a service reads them off a stored row.
 *
 * A case type title and a status name are declared `translatable`, so OpenRegister
 * answers them as a language map (`{"nl": "Woo-verzoek"}`). A `(string)` cast of
 * that map is the literal "Array". These are the private readers that cast it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DemoCaseloadGateway;
use OCA\Dossiq\Service\DemoCaseloadSeedDataService;
use OCA\Dossiq\Service\KpiAggregationService;
use OCA\Dossiq\Service\Queue\QueueFigures;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the type-name readers that cast a translatable field.
 *
 * @covers \OCA\Dossiq\Service\KpiAggregationService
 * @covers \OCA\Dossiq\Service\DemoCaseloadSeedDataService
 */
class TranslatableTypeNamesReadAsTextTest extends TestCase {

	/**
	 * The team queue names a case type by its title, not "Array".
	 *
	 * @return void
	 */
	public function testTheQueueFiguresNameACaseTypeByItsTitle(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('5');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$objectService = new class {
			/**
			 * The stored case types.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<string, mixed>
			 */
			public function findAll(array $config): array {
				return ['results' => [['id' => 'ct-1', 'title' => ['nl' => 'Woo-verzoek']]]];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');

		$service = new KpiAggregationService(
			appConfig: $appConfig,
			container: $container,
			appManager: $appManager,
			engineTasks: $this->createMock(EngineTaskInbox::class),
			queueFigures: $this->createMock(QueueFigures::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

		$titles = (new ReflectionMethod($service, 'caseTypeTitles'))->invoke($service, ['register' => '1']);

		self::assertSame(['ct-1' => 'Woo-verzoek'], $titles);
	}//end testTheQueueFiguresNameACaseTypeByItsTitle()

	/**
	 * The demo caseload finds a status by its name, so the key carries the name, not "Array".
	 *
	 * @return void
	 */
	public function testTheDemoCaseloadKeysAStatusByItsName(): void {
		$gateway = $this->createMock(DemoCaseloadGateway::class);
		$statusType = (object)['id' => 'st-1'];
		$gateway->method('findMany')->willReturn([$statusType]);
		$gateway->method('toArray')->willReturn(['id' => 'st-1', 'name' => ['nl' => 'Publicatie'], 'caseType' => 'ct-1']);
		$gateway->method('idOf')->willReturn('st-1');

		$service = new DemoCaseloadSeedDataService(
			gateway: $gateway,
			engineTask: $this->createMock(EngineTaskGateway::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$map = (new ReflectionMethod($service, 'statusTypesByCaseTypeAndName'))->invoke($service, new \stdClass(), ['register' => '1', 'statusType' => '2']);

		self::assertSame(['ct-1|Publicatie' => 'st-1'], $map);
	}//end testTheDemoCaseloadKeysAStatusByItsName()
}//end class
