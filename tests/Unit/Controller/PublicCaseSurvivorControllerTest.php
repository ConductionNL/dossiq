<?php

/**
 * PublicCaseSurvivorController unit tests: a token of a merged case finds the surviving case, and everything else is one 404.
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
 *
 * @spec openspec/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PublicCaseSurvivorController;
use OCA\Dossiq\Kernel\Contracts\CaseMergeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The public survivor lookup against doubles of the token service and the object store.
 *
 * @covers \OCA\Dossiq\Controller\PublicCaseSurvivorController
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class PublicCaseSurvivorControllerTest extends TestCase {

	/**
	 * The merge service double.
	 *
	 * @var CaseMergeServiceInterface&MockObject
	 */
	private CaseMergeServiceInterface $merges;

	/**
	 * The settings double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $settings;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles; the register and case schema are configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->merges = $this->createMock(CaseMergeServiceInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			['case_schema', '', '12'],
		]);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return PublicCaseSurvivorController
	 */
	private function controller(): PublicCaseSurvivorController {
		return new PublicCaseSurvivorController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			mergeService: $this->merges,
			settingsService: $this->settings,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * A token service whose resolve() answers from a callback.
	 *
	 * @param callable $resolve The resolve body.
	 *
	 * @return object
	 */
	private function tokens(callable $resolve): object {
		return new class($resolve) {
			/**
			 * The resolve body.
			 *
			 * @var callable
			 */
			private $resolve;

			/**
			 * Constructor.
			 *
			 * @param callable $resolve The resolve body.
			 */
			public function __construct(callable $resolve) {
				$this->resolve = $resolve;
			}

			/**
			 * Resolve a token.
			 *
			 * @param string $token The token.
			 *
			 * @return mixed
			 */
			public function resolve(string $token): mixed {
				return ($this->resolve)($token);
			}
		};
	}//end tokens()

	/**
	 * An object store that records its calls.
	 *
	 * @param mixed $entity   What find() answers, or a Throwable to throw.
	 * @param mixed $rendered What renderEntity() answers.
	 *
	 * @return object
	 */
	private function store(mixed $entity, mixed $rendered=null): object {
		return new class($entity, $rendered) {
			/**
			 * The calls seen.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $entity   What find() answers.
			 * @param mixed $rendered What renderEntity() answers.
			 */
			public function __construct(private readonly mixed $entity, private readonly mixed $rendered) {
			}

			/**
			 * Find one object.
			 *
			 * @param mixed ...$args Named arguments.
			 *
			 * @return mixed
			 */
			public function find(mixed ...$args): mixed {
				$this->calls[] = ['find' => $args];
				if ($this->entity instanceof \Throwable) {
					throw $this->entity;
				}

				return $this->entity;
			}

			/**
			 * Render an entity.
			 *
			 * @param mixed ...$args Named arguments.
			 *
			 * @return mixed
			 */
			public function renderEntity(mixed ...$args): mixed {
				$this->calls[] = ['renderEntity' => $args];
				return $this->rendered;
			}
		};
	}//end store()

	/**
	 * A token pointing at merged case old-1, merged into new-1.
	 *
	 * @return void
	 */
	private function mergedToken(): void {
		$this->settings->method('getOpenRegisterClass')->with('OCA\\OpenRegister\\Service\\CaseTokenService')
			->willReturn($this->tokens(fn (string $token) => ['object' => ['id' => 'old-1']]));
		$this->merges->method('resolveSurvivor')->with('old-1')->willReturn('new-1');
	}//end mergedToken()

	/**
	 * A merged case's token answers with the survivor read under RBAC and multitenancy.
	 *
	 * @return void
	 */
	public function testAMergedCaseTokenFindsTheSurvivor(): void {
		$this->mergedToken();
		$entity = new \stdClass();
		$store = $this->store(entity: $entity, rendered: ['id' => 'new-1', 'title' => 'Survivor']);
		$this->settings->method('getObjectService')->willReturn($store);

		$response = $this->controller()->survivor(token: 'tok-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['token' => 'tok-1', 'mergedFrom' => 'old-1', 'object' => ['id' => 'new-1', 'title' => 'Survivor']],
			$response->getData()
		);
		$this->assertSame(
			[
				['find' => ['id' => 'new-1', '_extend' => [], 'files' => false, 'register' => '5', 'schema' => '12', '_rbac' => true, '_multitenancy' => true]],
				['renderEntity' => ['entity' => $entity, '_rbac' => true, '_multitenancy' => true]],
			],
			$store->calls
		);
	}//end testAMergedCaseTokenFindsTheSurvivor()

	/**
	 * Without OpenRegister's token service the answer is 404 and no merge is consulted.
	 *
	 * @return void
	 */
	public function testWithoutATokenServiceItIsNotFound(): void {
		$this->settings->method('getOpenRegisterClass')->willReturn(null);
		$this->merges->expects($this->never())->method('resolveSurvivor');

		$response = $this->controller()->survivor(token: 'tok-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}//end testWithoutATokenServiceItIsNotFound()

	/**
	 * A token service without resolve() counts as absent.
	 *
	 * @return void
	 */
	public function testATokenServiceWithoutResolveIsNotFound(): void {
		$this->settings->method('getOpenRegisterClass')->willReturn(new \stdClass());
		$this->merges->expects($this->never())->method('resolveSurvivor');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
	}//end testATokenServiceWithoutResolveIsNotFound()

	/**
	 * A token that fails to resolve is logged at debug and is a 404.
	 *
	 * @return void
	 */
	public function testATokenThatThrowsIsNotFound(): void {
		$this->settings->method('getOpenRegisterClass')->willReturn(
			$this->tokens(fn (string $token) => throw new RuntimeException('expired'))
		);
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: a public token did not resolve: expired');
		$this->merges->expects($this->never())->method('resolveSurvivor');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
	}//end testATokenThatThrowsIsNotFound()

	/**
	 * A token that resolves to something that is not an array is a 404.
	 *
	 * @return void
	 */
	public function testATokenResolvingToANonArrayIsNotFound(): void {
		$this->settings->method('getOpenRegisterClass')->willReturn($this->tokens(fn (string $token) => 'old-1'));
		$this->merges->expects($this->never())->method('resolveSurvivor');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
	}//end testATokenResolvingToANonArrayIsNotFound()

	/**
	 * A case that was not merged (its own survivor, or none) is a 404 and nothing is read.
	 *
	 * @return void
	 */
	public function testACaseThatWasNotMergedIsNotFound(): void {
		$this->settings->method('getOpenRegisterClass')
			->willReturn($this->tokens(fn (string $token) => ['object' => ['id' => $token === 'own' ? 'case-1' : 'case-2']]));
		$this->merges->method('resolveSurvivor')->willReturnMap([['case-1', 'case-1'], ['case-2', '']]);
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'own')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'none')->getStatus());
	}//end testACaseThatWasNotMergedIsNotFound()

	/**
	 * Without an object store the survivor cannot be read: 404.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectStoreItIsNotFound(): void {
		$this->mergedToken();
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
	}//end testWithoutAnObjectStoreItIsNotFound()

	/**
	 * A read that throws (RBAC-denied, gone) is logged at debug and is a 404.
	 *
	 * @return void
	 */
	public function testAFailingReadIsNotFound(): void {
		$this->mergedToken();
		$this->settings->method('getObjectService')->willReturn($this->store(entity: new RuntimeException('denied')));
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: a surviving case could not be read publicly: denied');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
	}//end testAFailingReadIsNotFound()

	/**
	 * A survivor that is not found, or renders to nothing usable, is a 404 and is never rendered when absent.
	 *
	 * @return void
	 */
	public function testAnAbsentOrUnrenderableSurvivorIsNotFound(): void {
		$this->mergedToken();
		$absent = $this->store(entity: null);
		$unrenderable = $this->store(entity: new \stdClass(), rendered: 'not a row');
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls($absent, $unrenderable);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->survivor(token: 'tok-1')->getStatus());
		$this->assertCount(1, $absent->calls);
		$this->assertCount(2, $unrenderable->calls);
	}//end testAnAbsentOrUnrenderableSurvivorIsNotFound()
}//end class
