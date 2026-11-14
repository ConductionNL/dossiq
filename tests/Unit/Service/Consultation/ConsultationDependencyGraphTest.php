<?php

/**
 * Whether a dependency between advice requests would close a loop.
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
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;

/**
 * Self-dependency, direct and indirect loops, and chains that end.
 *
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationRepository
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ConsultationDependencyGraphTest extends TestCase {

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

	/**
	 * @param array<string, mixed> $dependsOn Consultation id => its dependsOn value.
	 */
	private function graph(array $dependsOn): ConsultationDependencyGraph {
		$byId = [];
		foreach ($dependsOn as $id => $deps) {
			$byId[$id] = ['id' => $id, 'dependsOn' => $deps];
		}

		return new ConsultationDependencyGraph($this->repository(new FakeConsultationStore(byId: $byId)));
	}

	public function testDependingOnItselfIsALoop(): void {
		$this->assertTrue($this->graph([])->wouldCreateCycle('a', ['b', 'a']));
	}

	public function testALoopThroughOtherRequestsIsFound(): void {
		$this->assertTrue($this->graph(['b' => ['a']])->wouldCreateCycle('a', ['b']));
		$this->assertTrue($this->graph(['b' => ['x', 'c'], 'c' => ['d'], 'd' => ['a']])->wouldCreateCycle('a', ['b']));
	}

	public function testChainsThatEndAreNoLoop(): void {
		$this->assertFalse($this->graph(['b' => ['c'], 'c' => []])->wouldCreateCycle('a', ['b']));
		$this->assertFalse($this->graph(['b' => 'c'])->wouldCreateCycle('a', ['b']));
		$this->assertFalse($this->graph([])->wouldCreateCycle('a', ['missing']));
		$this->assertFalse($this->graph(['b' => ['c'], 'c' => ['b']])->wouldCreateCycle('a', ['b', 'c']));
		$this->assertFalse($this->graph([])->wouldCreateCycle('a', []));
	}
}
