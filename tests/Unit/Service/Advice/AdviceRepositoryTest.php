<?php

/**
 * How advice requests are read from and written to OpenRegister.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;

/**
 * @covers \OCA\Dossiq\Service\Advice\AdviceRepository
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AdviceRepositoryTest extends TestCase {
	/**
	 * A settings service with the given object service and config.
	 *
	 * @param object|null           $objectService The object service, or none.
	 * @param array<string, string> $config        The config values.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config = ['register' => '5', 'advies_aanvraag_schema' => '7']): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);
		return $settings;
	}//end settings()

	/**
	 * An object service that records calls and answers from callbacks.
	 *
	 * @param array<string, callable> $answers Method name => callback.
	 *
	 * @return object
	 */
	private function objectService(array $answers): object {
		return new class($answers) {
			/** @var list<array{0: string, 1: array<int|string, mixed>}> */
			public array $calls = [];

			/**
			 * @param array<string, callable> $answers The answers.
			 */
			public function __construct(private array $answers) {
			}

			public function find(...$args): mixed {
				$this->calls[] = ['find', $args];
				return ($this->answers['find'])($args);
			}

			public function searchObjects(...$args): mixed {
				$this->calls[] = ['searchObjects', $args];
				return ($this->answers['searchObjects'])($args);
			}

			public function searchObjectsBySlug(...$args): mixed {
				$this->calls[] = ['searchObjectsBySlug', $args];
				return ($this->answers['searchObjectsBySlug'])($args);
			}

			public function patchObject(...$args): mixed {
				$this->calls[] = ['patchObject', $args];
				return ($this->answers['patchObject'])($args);
			}
		};
	}//end objectService()

	public function testFindReturnsTheRecordFromTheConfiguredRegisterAndSchema(): void {
		$service = $this->objectService(['find' => static fn (): array => ['id' => 'a1', 'status' => 'requested']]);
		$repository = new AdviceRepository($this->settings($service), $this->createMock(LoggerInterface::class));

		$this->assertSame(['id' => 'a1', 'status' => 'requested'], $repository->find('a1'));
		$this->assertSame([['find', [0 => 'a1', 'register' => '5', 'schema' => '7']]], $service->calls);
	}//end testFindReturnsTheRecordFromTheConfiguredRegisterAndSchema()

	public function testFindSerialisesAnEntityResult(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'a2'];
			}
		};
		$service = $this->objectService(['find' => static fn (): object => $entity]);
		$repository = new AdviceRepository($this->settings($service), $this->createMock(LoggerInterface::class));

		$this->assertSame(['id' => 'a2'], $repository->find('a2'));
	}//end testFindSerialisesAnEntityResult()

	public function testFindIsNullWithoutAnObjectService(): void {
		$repository = new AdviceRepository($this->settings(null), $this->createMock(LoggerInterface::class));

		$this->assertNull($repository->find('a1'));
		$this->assertSame([], $repository->findForCase('c1'));
		$this->assertSame([], $repository->findOpen());
	}//end testFindIsNullWithoutAnObjectService()

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function incompleteConfig(): array {
		return [
			'no register' => [['advies_aanvraag_schema' => '7']],
			'no schema' => [['register' => '5']],
			'neither' => [[]],
		];
	}//end incompleteConfig()

	/**
	 * @dataProvider incompleteConfig
	 *
	 * @param array<string, string> $config The config values.
	 */
	public function testReadsDegradeToEmptyWhenTheSchemaIsNotConfigured(array $config): void {
		$service = $this->objectService([]);
		$repository = new AdviceRepository($this->settings($service, $config), $this->createMock(LoggerInterface::class));

		$this->assertNull($repository->find('a1'));
		$this->assertSame([], $repository->findForCase('c1'));
		$this->assertSame([], $repository->findOpen());
		$this->assertSame([], $service->calls);
	}//end testReadsDegradeToEmptyWhenTheSchemaIsNotConfigured()

	/**
	 * @dataProvider incompleteConfig
	 *
	 * @param array<string, string> $config The config values.
	 */
	public function testSaveRefusesWhenTheSchemaIsNotConfigured(array $config): void {
		$repository = new AdviceRepository($this->settings($this->objectService([]), $config), $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Advice schema is not configured');
		$repository->save(['status' => 'given'], 'a1');
	}//end testSaveRefusesWhenTheSchemaIsNotConfigured()

	public function testSaveRefusesWithoutAnObjectService(): void {
		$repository = new AdviceRepository($this->settings(null), $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$repository->save(['status' => 'given'], 'a1');
	}//end testSaveRefusesWithoutAnObjectService()

	public function testAFailingFindIsLoggedAndNull(): void {
		$service = $this->objectService(['find' => static function (): never {
			throw new RuntimeException('db gone');
		}]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq: failed to load advice: db gone', ['app' => 'dossiq']);

		$this->assertNull((new AdviceRepository($this->settings($service), $logger))->find('a1'));
	}//end testAFailingFindIsLoggedAndNull()

	public function testFindForCaseSearchesByCaseOnTheNumericPath(): void {
		$service = $this->objectService(['searchObjects' => static fn (): array => [['id' => 'a1'], 'junk']]);
		$repository = new AdviceRepository($this->settings($service), $this->createMock(LoggerInterface::class));

		$this->assertSame([['id' => 'a1']], $repository->findForCase('c1'));
		$this->assertSame(
			[['searchObjects', [['case' => 'c1', '_limit' => 200, '@self' => ['register' => 5, 'schema' => 7]]]]],
			$service->calls
		);
	}//end testFindForCaseSearchesByCaseOnTheNumericPath()

	public function testFindOpenSearchesRequestedAdviceOnTheSlugPath(): void {
		$service = $this->objectService(['searchObjectsBySlug' => static fn (): array => [['id' => 'a3']]]);
		$repository = new AdviceRepository(
			$this->settings($service, ['register' => 'dossiq', 'advies_aanvraag_schema' => 'advies']),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame([['id' => 'a3']], $repository->findOpen());
		$this->assertSame(
			[['searchObjectsBySlug', ['dossiq', 'advies', ['status' => 'requested', '_limit' => 500]]]],
			$service->calls
		);
	}//end testFindOpenSearchesRequestedAdviceOnTheSlugPath()

	public function testFailingSearchesAreLoggedAndEmpty(): void {
		$throw = static function (): never {
			throw new RuntimeException('timeout');
		};
		$service = $this->objectService(['searchObjects' => $throw]);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message) use (&$logged): void {
			$logged[] = $message;
		});
		$repository = new AdviceRepository($this->settings($service), $logger);

		$this->assertSame([], $repository->findForCase('c1'));
		$this->assertSame([], $repository->findOpen());
		$this->assertSame(
			['Dossiq: failed to fetch advice for case: timeout', 'Dossiq: failed to load open advice: timeout'],
			$logged
		);
	}//end testFailingSearchesAreLoggedAndEmpty()

	public function testSavePatchesTheAdviceAndReturnsTheStoredRecord(): void {
		$service = $this->objectService(['patchObject' => static fn (): array => ['id' => 'a1', 'status' => 'given']]);
		$repository = new AdviceRepository($this->settings($service), $this->createMock(LoggerInterface::class));

		$this->assertSame(['id' => 'a1', 'status' => 'given'], $repository->save(['status' => 'given'], 'a1'));
		$this->assertCount(1, $service->calls);
		$this->assertSame('patchObject', $service->calls[0][0]);
		$patch = $service->calls[0][1]['patch'];
		$this->assertInstanceOf(ObjectPatch::class, $patch);
		$this->assertSame(
			['objectId' => 'a1', 'data' => ['status' => 'given'], 'register' => '5', 'schema' => '7'],
			['objectId' => $patch->objectId, 'data' => $patch->data, 'register' => $patch->target->register, 'schema' => $patch->target->schema]
		);
	}//end testSavePatchesTheAdviceAndReturnsTheStoredRecord()

	public function testAFailingSaveIsLoggedAndRefusedWithoutDetail(): void {
		$service = $this->objectService(['patchObject' => static function (): never {
			throw new RuntimeException('constraint violated on column x');
		}]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: failed to transition advice status: constraint violated on column x',
			['app' => 'dossiq']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not update advice request');
		(new AdviceRepository($this->settings($service), $logger))->save(['status' => 'given'], 'a1');
	}//end testAFailingSaveIsLoggedAndRefusedWithoutDetail()

	public function testNormalizeKeepsArraysSerialisesEntitiesAndEmptiesTheRest(): void {
		$repository = new AdviceRepository($this->settings(null), $this->createMock(LoggerInterface::class));

		$this->assertSame(['a' => 1], $repository->normalize(['a' => 1]));
		$this->assertSame([], $repository->normalize(null));
		$this->assertSame([], $repository->normalize('text'));
		$this->assertSame([], $repository->normalize(new \stdClass()));
	}//end testNormalizeKeepsArraysSerialisesEntitiesAndEmptiesTheRest()
}//end class
