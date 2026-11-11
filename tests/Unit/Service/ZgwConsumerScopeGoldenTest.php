<?php

/**
 * ZgwService consumer scope golden tests.
 *
 * Pins the edges of the scope check next to ZgwServiceConsumerScopeTest: only
 * a boolean true superuser flag is unrestricted, an authorisation without a
 * component or without scopes grants nothing, a scope is granted only on its
 * own component, and a failing lookup is logged and denied.
 *
 * An authorisation without a component is read as the empty component; this
 * pins that as the code reads it, it does not endorse it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ConsumerMapper shape ZgwService calls.
 */
interface ScopeGoldenConsumerMapperStub {
	/**
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, object>
	 */
	public function findAll(array $filters): array;
}//end interface

/**
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwConsumerScopeGoldenTest extends TestCase {

	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The service over a consumer with this authorisation configuration.
	 *
	 * @param array<string, mixed>|\Throwable $authConfig The configuration, or what the lookup throws.
	 *
	 * @return ZgwService
	 */
	private function service(array|\Throwable $authConfig): ZgwService {
		$consumer = new class (is_array($authConfig) === true ? $authConfig : []) {
			public function __construct(private array $config) {
			}

			public function getAuthorizationConfiguration(): array {
				return $this->config;
			}
		};
		$mapper = $this->createMock(ScopeGoldenConsumerMapperStub::class);
		$mapper->method('findAll')->willReturnCallback(
			static function () use ($authConfig, $consumer): array {
				if ($authConfig instanceof \Throwable) {
					throw $authConfig;
				}

				return [$consumer];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		$service = (new \ReflectionClass(ZgwService::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			static function () use ($service, $mapper, $logger): void {
				$service->logger = $logger;
				$service->consumerMapper = $mapper;
			},
			null,
			ZgwService::class
		)();

		return $service;
	}//end service()

	/**
	 * A request of consumer client-a.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer eyJhbGciOiJIUzI1NiJ9.' . base64_encode((string)json_encode(['client_id' => 'client-a'])) . '.sig');

		return $request;
	}//end request()

	/**
	 * Only a boolean true superuser flag is unrestricted.
	 *
	 * @return void
	 */
	public function testOnlyATrueSuperuserIsUnrestricted(): void {
		$this->assertTrue($this->service(['superuser' => true, 'scopes' => []])->consumerHasScope($this->request(), 'zrc', 'x'));
		$this->assertFalse($this->service(['superuser' => 'true'])->consumerHasScope($this->request(), 'zrc', 'x'));
		$this->assertFalse($this->service(['superuser' => 1])->consumerHasScope($this->request(), 'zrc', 'x'));
	}//end testOnlyATrueSuperuserIsUnrestricted()

	/**
	 * An authorisation without scopes grants nothing, one without a component only the empty one; a later one still can.
	 *
	 * @return void
	 */
	public function testIncompleteAuthorisationsGrantNothing(): void {
		$service = $this->service(['scopes' => [['scopes' => ['zaken.lezen']], ['component' => 'zrc'], ['component' => 'zrc', 'scopes' => ['zaken.bijwerken', 'zaken.lezen']]]]);

		$this->assertTrue($service->consumerHasScope($this->request(), 'zrc', 'zaken.lezen'));
		// An entry without a component counts as the empty component, as the code reads it today.
		$this->assertTrue($service->consumerHasScope($this->request(), '', 'zaken.lezen'));
		$this->assertFalse($this->service(['scopes' => [['component' => 'zrc']]])->consumerHasScope($this->request(), 'zrc', 'zaken.lezen'));
	}//end testIncompleteAuthorisationsGrantNothing()

	/**
	 * A scope is granted on its own component only, compared strictly.
	 *
	 * @return void
	 */
	public function testAScopeIsGrantedOnItsOwnComponent(): void {
		$service = $this->service(['scopes' => [['component' => 'drc', 'scopes' => ['documenten.lezen']]]]);

		$this->assertTrue($service->consumerHasScope($this->request(), 'drc', 'documenten.lezen'));
		$this->assertFalse($service->consumerHasScope($this->request(), 'zrc', 'documenten.lezen'));
		$this->assertFalse($service->consumerHasScope($this->request(), 'DRC', 'documenten.lezen'));
	}//end testAScopeIsGrantedOnItsOwnComponent()

	/**
	 * A lookup that fails denies, and is logged once.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsDeniedAndLogged(): void {
		$this->assertFalse($this->service(new RuntimeException('db down'))->consumerHasScope($this->request(), 'zrc', 'x'));
		$this->assertCount(1, $this->warnings);
	}//end testAFailingLookupIsDeniedAndLogged()
}//end class
