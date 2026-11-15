<?php

/**
 * ZgwServiceAuthenticationTest
 *
 * The ZGW controllers authenticate through ZgwService::validateJwtAuth(). It
 * asks the same credential resolver the middleware asks, so a caller with an
 * API key or a forwarded certificate is accepted there too, and every refusal
 * is a 401 naming the kind of credential that failed.
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-the-caller-may-present-a-jwt-an-api-key-or-a-client-certificate-req-paa-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwPaginationHelper;
use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * validateJwtAuth() over the credential resolver.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 * @uses   \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 */
class ZgwServiceAuthenticationTest extends TestCase {

	/**
	 * The resolver double.
	 *
	 * @var ApiCredentialResolver&MockObject
	 */
	private ApiCredentialResolver&MockObject $resolver;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var ZgwService
	 */
	private ZgwService $service;

	/**
	 * Build the service over the resolver double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->createMock(ApiCredentialResolver::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ZgwService(
			$this->createMock(ZgwMappingService::class),
			$this->createMock(ZgwPaginationHelper::class),
			$this->createMock(ZgwDocumentService::class),
			$this->createMock(NotificatieService::class),
			$this->createMock(ZgwBusinessRulesService::class),
			$this->resolver,
			$this->logger,
		);
	}//end setUp()

	/**
	 * A caller that resolves, by any credential, is let through.
	 *
	 * @return void
	 */
	public function testAResolvedCallerPasses(): void {
		$request = $this->createMock(IRequest::class);
		$this->resolver->expects($this->once())->method('resolve')->with($request)->willReturn(new \stdClass());

		$this->assertNull($this->service->validateJwtAuth($request));
	}//end testAResolvedCallerPasses()

	/**
	 * A request without any credential answers 401 saying none was given.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
	 */
	public function testNoCredentialIs401(): void {
		$this->resolver->method('resolve')->willReturn(null);

		$response = $this->service->validateJwtAuth($this->createMock(IRequest::class));

		$this->assertSame(401, $response?->getStatus());
		$this->assertSame(
			[
				'type' => 'NotAuthenticated',
				'code' => 'not_authenticated',
				'title' => 'Authenticatiegegevens zijn niet geldig.',
				'status' => 401,
				'detail' => 'Authenticatiegegevens zijn niet opgegeven.',
			],
			$response?->getData()
		);
	}//end testNoCredentialIs401()

	/**
	 * A credential that does not resolve answers 401 naming its kind, and the
	 * resolver's own words go to the log only.
	 *
	 * @return void
	 */
	public function testARefusedCredentialIs401NamingItsKind(): void {
		$this->resolver->method('resolve')->willThrowException(new ZgwAuthValidationException('Unknown API key'));
		$this->logger->expects($this->once())->method('warning')->with('ZGW authentication failed: Unknown API key', ['app' => 'dossiq']);

		$response = $this->service->validateJwtAuth($this->createMock(IRequest::class));

		$this->assertSame(401, $response?->getStatus());
		$this->assertSame('The API key is not known.', $response?->getData()['detail']);
	}//end testARefusedCredentialIs401NamingItsKind()

	/**
	 * A failing token says only that it is not valid, so the answer does not
	 * tell which issuers exist.
	 *
	 * @return void
	 */
	public function testAnUnknownIssuerIsNotNamed(): void {
		$this->resolver->method('resolve')->willThrowException(new ZgwAuthValidationException('Unknown issuer'));

		$response = $this->service->validateJwtAuth($this->createMock(IRequest::class));

		$this->assertSame('The token is not valid.', $response?->getData()['detail']);
	}//end testAnUnknownIssuerIsNotNamed()
}//end class
