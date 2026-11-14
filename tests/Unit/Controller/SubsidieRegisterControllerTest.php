<?php

/**
 * SubsidieRegisterController tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SubsidieRegisterController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\SubsidieRegisterExporter;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\SubsidieRegisterController
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class SubsidieRegisterControllerTest extends TestCase {

	private const CONFIG = [
		'register' => '4',
		'subsidie_beschikking_schema' => '11',
		'subsidie_aanvraag_schema' => '12',
		'subsidie_regeling_schema' => '13',
	];

	/** @var SettingsService&MockObject */
	private SettingsService $settings;

	/** @var SubsidieRegisterExporter&MockObject */
	private SubsidieRegisterExporter $exporter;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->exporter = $this->createMock(SubsidieRegisterExporter::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ['limit' => '25', 'offset' => '50'][$key] ?? $default
		);
	}

	/**
	 * @param array<string, string> $config The app config values.
	 */
	private function configure(array $config): void {
		$this->settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => $config[$key] ?? $default
		);
	}

	private function controller(): SubsidieRegisterController {
		return new SubsidieRegisterController($this->request, $this->settings, $this->exporter);
	}

	/**
	 * An object service holding decisions, requests and schemes.
	 *
	 * @param array<int, mixed>              $decisions The granted decisions.
	 * @param array<string, array|\Throwable> $byId      Objects by id.
	 *
	 * @return object
	 */
	private function objectService(array $decisions, array $byId): object {
		return new class($decisions, $byId) {
			/** @var array<int, array<string, mixed>> */
			public array $findAllCalls = [];

			/** @var array<int, array<string, mixed>> */
			public array $findCalls = [];

			public function __construct(private array $decisions, private array $byId) {
			}

			public function findAll(array $config): array {
				$this->findAllCalls[] = $config;
				if ($this->decisions === ['throw']) {
					throw new RuntimeException('boom');
				}

				return $this->decisions;
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->findCalls[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				$found = ($this->byId[$id] ?? []);
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}
		};
	}

	public function testWithoutAnObjectServiceTheFeedIsBuiltFromNoEntries(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->exporter->expects($this->never())->method('toFeedEntry');
		$this->exporter->expects($this->once())->method('buildFeed')->with([], 25, 50)->willReturn(['items' => []]);

		$this->assertSame(['items' => []], $this->controller()->export()->getData());
	}

	public function testAnIncompleteConfigurationYieldsNoEntries(): void {
		$service = $this->objectService([['subsidieaanvraag' => 'a1']], []);
		$this->settings->method('getObjectService')->willReturn($service);
		$this->configure(array_merge(self::CONFIG, ['subsidie_regeling_schema' => '']));
		$this->exporter->expects($this->once())->method('buildFeed')->with([], 25, 50)->willReturn([]);

		$this->controller()->export();

		$this->assertSame([], $service->findAllCalls);
	}

	public function testAFailingSearchYieldsNoEntries(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectService(['throw'], []));
		$this->configure(self::CONFIG);
		$this->exporter->expects($this->never())->method('toFeedEntry');
		$this->exporter->expects($this->once())->method('buildFeed')->with([], 25, 50)->willReturn([]);

		$this->controller()->export();
	}

	public function testGrantedDecisionsAreJoinedWithTheirRequestAndScheme(): void {
		$decision = ['id' => 'd1', 'subsidieaanvraag' => 'a1'];
		$service = $this->objectService(
			[$decision, ['id' => 'd2', 'subsidieaanvraag' => '']],
			['a1' => ['id' => 'a1', 'subsidyScheme' => 'r1'], 'r1' => ['id' => 'r1', 'name' => 'Isolatie']],
		);
		$this->settings->method('getObjectService')->willReturn($service);
		$this->configure(self::CONFIG);
		$this->exporter->expects($this->once())->method('toFeedEntry')
			->with(['id' => 'a1', 'subsidyScheme' => 'r1'], ['id' => 'r1', 'name' => 'Isolatie'], $decision)
			->willReturn(['entry' => 'd1']);
		$this->exporter->expects($this->once())->method('buildFeed')
			->with([['entry' => 'd1']], 25, 50)
			->willReturn(['items' => [['entry' => 'd1']]]);

		$response = $this->controller()->export();

		$this->assertSame(['items' => [['entry' => 'd1']]], $response->getData());
		$this->assertSame(
			[['filters' => ['register' => 4, 'schema' => 11, 'status' => 'granted']]],
			$service->findAllCalls
		);
		$this->assertSame(
			[
				['id' => 'a1', 'register' => '4', 'schema' => '12'],
				['id' => 'r1', 'register' => '4', 'schema' => '13'],
			],
			$service->findCalls
		);
	}

	public function testARequestWithoutSchemeOrAFailedLookupStillYieldsAnEntry(): void {
		$service = $this->objectService(
			[['subsidieaanvraag' => 'a1'], ['subsidieaanvraag' => 'a2']],
			['a1' => ['id' => 'a1'], 'a2' => new RuntimeException('gone')],
		);
		$this->settings->method('getObjectService')->willReturn($service);
		$this->configure(self::CONFIG);
		$this->exporter->expects($this->exactly(2))->method('toFeedEntry')
			->willReturnCallback(
				function (array $request, array $regeling, array $decision): array {
					$this->assertSame([], $regeling);
					return ['request' => $request, 'decision' => $decision['subsidieaanvraag']];
				}
			);
		$this->exporter->expects($this->once())->method('buildFeed')
			->with(
				[
					['request' => ['id' => 'a1'], 'decision' => 'a1'],
					['request' => [], 'decision' => 'a2'],
				],
				25,
				50
			)
			->willReturn([]);

		$this->controller()->export();

		$this->assertCount(2, $service->findCalls);
	}
}
