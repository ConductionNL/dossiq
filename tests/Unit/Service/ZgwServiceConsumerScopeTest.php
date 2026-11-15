<?php

/**
 * Characterisation tests for the consumer scope answers of ZgwService.
 *
 * Pins consumerHasScope() and getConsumerAuthorisaties() on every JWT path, so their
 * decomposition (method-decomposition) can be proven to change no outcome. Both fail closed:
 * no mapper, a malformed token, no client id, an unknown consumer or an error all deny.
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

/**
 * The ConsumerMapper shape ZgwService calls.
 */
interface ScopeConsumerMapperStub {
	public function findAll(array $filters): array;
}//end interface

/**
 * Characterisation tests for the consumer scope answers.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwServiceConsumerScopeTest extends TestCase {

	/**
	 * Build a ZgwService without its container-reading constructor.
	 *
	 * @param object|null $consumerMapper The ConsumerMapper, or null when unavailable
	 *
	 * @return ZgwService
	 */
	private function service(?object $consumerMapper): ZgwService {
		$service = (new \ReflectionClass(ZgwService::class))->newInstanceWithoutConstructor();
		$logger  = $this->createMock(LoggerInterface::class);
		\Closure::bind(
			static function () use ($service, $consumerMapper, $logger): void {
				$service->logger = $logger;
				$service->consumerMapper = $consumerMapper;
			},
			null,
			ZgwService::class
		)();

		return $service;

	}//end service()

	/**
	 * A request carrying a bearer token whose payload is the given claims.
	 *
	 * @param array|null $claims The JWT claims, or null for a malformed token
	 *
	 * @return IRequest
	 */
	private function request(?array $claims): IRequest {
		$token = 'not-a-jwt';
		if ($claims !== null) {
			$token = 'eyJhbGciOiJIUzI1NiJ9.'.base64_encode((string)json_encode($claims)).'.sig';
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer '.$token);

		return $request;

	}//end request()

	/**
	 * A mapper that knows one consumer with the given authorisation configuration.
	 *
	 * @param array|null $authConfig The consumer's configuration, or null for no consumer
	 * @param bool       $throws     Whether findAll throws
	 *
	 * @return ScopeConsumerMapperStub
	 */
	private function mapper(?array $authConfig, bool $throws=false): ScopeConsumerMapperStub {
		$consumer = new class($authConfig ?? []) {
			/**
			 * Constructor.
			 *
			 * @param array $config The authorisation configuration
			 */
			public function __construct(private array $config) {
			}//end __construct()

			/**
			 * The authorisation configuration.
			 *
			 * @return array
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->config;
			}//end getAuthorizationConfiguration()
		};

		$mapper = $this->createMock(ScopeConsumerMapperStub::class);
		$mapper->method('findAll')->willReturnCallback(
			static function (array $filters) use ($authConfig, $consumer, $throws): array {
				if ($throws === true) {
					throw new \RuntimeException('db down');
				}

				if ($authConfig === null || $filters['name'] !== 'client-a') {
					return [];
				}

				return [$consumer];
			}
		);

		return $mapper;

	}//end mapper()

	/**
	 * Every way the consumer cannot be established denies, on both answers.
	 *
	 * @return void
	 */
	public function testEveryUnknownConsumerIsDenied(): void {
		$scoped = ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]]];
		$cases  = [
			'no mapper'      => [$this->service(consumerMapper: null), $this->request(claims: ['client_id' => 'client-a'])],
			'malformed'      => [$this->service(consumerMapper: $this->mapper(authConfig: $scoped)), $this->request(claims: null)],
			'no client'      => [$this->service(consumerMapper: $this->mapper(authConfig: $scoped)), $this->request(claims: ['sub' => 'x'])],
			'unknown client' => [$this->service(consumerMapper: $this->mapper(authConfig: $scoped)), $this->request(claims: ['client_id' => 'client-b'])],
			'mapper throws'  => [$this->service(consumerMapper: $this->mapper(authConfig: $scoped, throws: true)), $this->request(claims: ['client_id' => 'client-a'])],
		];

		foreach ($cases as $label => [$service, $request]) {
			$this->assertFalse($service->consumerHasScope($request, 'zrc', 'zaken.lezen'), $label);
			$this->assertSame([], $service->getConsumerAuthorisaties($request, 'zrc'), $label);
		}

	}//end testEveryUnknownConsumerIsDenied()

	/**
	 * A superuser has every scope and is unrestricted; iss stands in for client_id.
	 *
	 * @return void
	 */
	public function testSuperuserIsUnrestricted(): void {
		$service = $this->service(consumerMapper: $this->mapper(authConfig: ['superuser' => true]));
		$request = $this->request(claims: ['iss' => 'client-a']);

		$this->assertTrue($service->consumerHasScope($request, 'drc', 'anything'));
		$this->assertNull($service->getConsumerAuthorisaties($request, 'drc'));

	}//end testSuperuserIsUnrestricted()

	/**
	 * A scoped consumer has exactly the scopes of the component asked for.
	 *
	 * @return void
	 */
	public function testScopedConsumerHasItsComponentScopes(): void {
		$zrc     = ['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'maxVertrouwelijkheidaanduiding' => 'openbaar'];
		$drc     = ['component' => 'drc', 'scopes' => ['documenten.lezen']];
		$service = $this->service(consumerMapper: $this->mapper(authConfig: ['scopes' => [$zrc, $drc, ['scopes' => ['x']]]]));
		$request = $this->request(claims: ['client_id' => 'client-a']);

		$this->assertTrue($service->consumerHasScope($request, 'zrc', 'zaken.lezen'));
		$this->assertFalse($service->consumerHasScope($request, 'zrc', 'zaken.aanmaken'));
		$this->assertFalse($service->consumerHasScope($request, 'brc', 'zaken.lezen'));
		$this->assertSame([$zrc], $service->getConsumerAuthorisaties($request, 'zrc'));
		$this->assertSame([], $service->getConsumerAuthorisaties($request, 'ztc'));

	}//end testScopedConsumerHasItsComponentScopes()

	/**
	 * A consumer without an authorisation configuration has no scope and an empty set.
	 *
	 * @return void
	 */
	public function testConsumerWithoutConfigurationHasNothing(): void {
		$service = $this->service(consumerMapper: $this->mapper(authConfig: []));
		$request = $this->request(claims: ['client_id' => 'client-a']);

		$this->assertFalse($service->consumerHasScope($request, 'zrc', 'zaken.lezen'));
		$this->assertSame([], $service->getConsumerAuthorisaties($request, 'zrc'));

	}//end testConsumerWithoutConfigurationHasNothing()
}//end class
