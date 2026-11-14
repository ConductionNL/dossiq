<?php

/**
 * Unit tests for DsoObjectRepository: a DSO case or collaboration request is
 * read from the configured register (the request's schema defaulting), a read
 * failure is logged and answers nothing, and the dashboard lists cases
 * filtered by location.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Service\Dso\DsoObjectRepository;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Dso\DsoObjectRepository
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DsoObjectRepositoryTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Wire a store and config.
	 *
	 * @param array<string, mixed> $objects Objects by "id@schema", a Throwable to throw.
	 * @param list<mixed>          $cases   What a search answers.
	 * @param array<string, string> $config Config values by key.
	 */
	private function store(array $objects = [], array $cases = [], array $config = ['register' => 'dossiq', 'case_schema' => 'case']): object {
		$store = new class($objects, $cases) {
			/** @var list<array<int|string, mixed>> */
			public array $calls = [];

			public function __construct(private array $objects, private array $cases) {
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = $this->objects[$id . '@' . $schema] ?? null;
				if ($found instanceof \Throwable) {
					throw $found;
				}

				if ($found === null) {
					throw new DoesNotExistException('missing');
				}

				return $found;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				return $this->cases;
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		return $store;
	}//end store()

	private function repository(): DsoObjectRepository {
		return new DsoObjectRepository($this->settings, $this->logger);
	}//end repository()

	public function testACaseIsReadFromTheConfiguredCaseSchema(): void {
		$store = $this->store(['case-1@case' => ['id' => 'case-1', 'title' => 'Dakkapel']]);

		$this->assertSame(['id' => 'case-1', 'title' => 'Dakkapel'], $this->repository()->findZaak('case-1'));
		$this->assertNull($this->repository()->findZaak('case-2'));
		$this->assertSame(['find', 'case-1', 'dossiq', 'case'], $store->calls[0]);
	}//end testACaseIsReadFromTheConfiguredCaseSchema()

	public function testWithoutAStoreThereIsNoCase(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertNull($this->repository()->findZaak('case-1'));
	}//end testWithoutAStoreThereIsNoCase()

	public function testAnUnconfiguredCaseSchemaReadsNothing(): void {
		$store = $this->store([], [], ['register' => 'dossiq']);

		$this->assertNull($this->repository()->findZaak('case-1'));
		$this->assertSame([], $store->calls);
	}//end testAnUnconfiguredCaseSchemaReadsNothing()

	public function testAFailingReadIsLoggedAndAnswersNothing(): void {
		$this->store(['case-1@case' => new RuntimeException('locked')]);
		$this->logger->expects($this->once())->method('warning')->with('Dossiq DsoObjectRepository: could not load zaak case-1: locked');

		$this->assertNull($this->repository()->findZaak('case-1'));
	}//end testAFailingReadIsLoggedAndAnswersNothing()

	public function testACollaborationRequestFallsBackToTheDefaultSchema(): void {
		$this->store(['s-1@samenwerkverzoek' => ['id' => 's-1']], [], ['register' => 'dossiq']);

		$this->assertSame(['id' => 's-1'], $this->repository()->findSamenwerkverzoek('s-1'));
	}//end testACollaborationRequestFallsBackToTheDefaultSchema()

	public function testAConfiguredCollaborationSchemaIsUsed(): void {
		$store = $this->store(['s-1@sv' => ['id' => 's-1']], [], ['register' => 'dossiq', 'dso_samenwerkverzoek_schema' => 'sv']);

		$this->assertSame(['id' => 's-1'], $this->repository()->findSamenwerkverzoek('s-1'));
		$this->assertSame(['find', 's-1', 'dossiq', 'sv'], $store->calls[0]);
	}//end testAConfiguredCollaborationSchemaIsUsed()

	public function testAFailingCollaborationReadIsLoggedAndAnswersNothing(): void {
		$this->store(['s-1@samenwerkverzoek' => new RuntimeException('down')], [], ['register' => 'dossiq']);
		$this->logger->expects($this->once())->method('warning')->with('Dossiq DsoObjectRepository: could not load samenwerkverzoek s-1: down');

		$this->assertNull($this->repository()->findSamenwerkverzoek('s-1'));
	}//end testAFailingCollaborationReadIsLoggedAndAnswersNothing()

	public function testTheDashboardListsCasesFilteredByLocation(): void {
		$cases = [['id' => 'c-1', 'location' => 'Utrecht, Oudegracht 1'], ['id' => 'c-2', 'location' => 'Amersfoort'], ['id' => 'c-3']];
		$store = $this->store([], $cases);

		$this->assertSame(['error' => null, 'results' => $cases], $this->repository()->fetchDashboard(['status' => 'open'], '', '', ''));
		$this->assertSame(
			['error' => null, 'results' => [['id' => 'c-1', 'location' => 'Utrecht, Oudegracht 1']]],
			$this->repository()->fetchDashboard([], '', '', 'Utrecht')
		);
		$this->assertSame(['search', 'dossiq', 'case', ['status' => 'open']], $store->calls[0]);
	}//end testTheDashboardListsCasesFilteredByLocation()

	public function testTheDashboardSaysWhyItCannotList(): void {
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls(null, new \stdClass());
		$this->settings->method('getConfigValue')->willReturn('');

		$this->assertSame(['error' => 'OpenRegister not available', 'results' => []], $this->repository()->fetchDashboard([], '', '', ''));
		$this->assertSame(['error' => 'Case register not configured', 'results' => []], $this->repository()->fetchDashboard([], '', '', ''));
	}//end testTheDashboardSaysWhyItCannotList()
}//end class
