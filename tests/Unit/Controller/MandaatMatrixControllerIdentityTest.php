<?php

/**
 * MandaatMatrixController server-side identity tests.
 *
 * `probe()` built its case properties straight from the request body:
 *
 *   $caseProps = (array) ($body['caseProperties'] ?? []);
 *
 * and the belangenconflict check then gated on `$caseProps['userBsn']`. Identity
 * supplied by the requester is not identity — a caller could force "no conflict"
 * simply by omitting the key.
 *
 * These tests pin the two halves of the fix: client identity is STRIPPED, and
 * the applicant identity is RE-DERIVED server-side from the case object.
 *
 * `probe()` itself reads php://input via jsonBody(), so the helpers are
 * exercised directly through reflection rather than over a faked request.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\MandaatMatrixController;
use OCA\Procest\Service\MandaatCheckService;
use OCA\Procest\Service\MandaatEscalatieService;
use OCA\Procest\Service\MandaatGebruikService;
use OCA\Procest\Service\MandaatImportService;
use OCA\Procest\Service\SettingsService;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Typed stub for the OpenRegister ObjectService (named-argument safe).
 */
interface MandaatCaseObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * @param int|string $id Object UUID.
	 * @param mixed $register Register slug.
	 * @param mixed $schema Schema slug.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;
}//end interface

/**
 * Unit tests for MandaatMatrixController identity handling.
 *
 * @covers \OCA\Procest\Controller\MandaatMatrixController
 */
class MandaatMatrixControllerIdentityTest extends TestCase {

	/**
	 * Build a controller whose case lookup returns $case.
	 *
	 * @param array<string, mixed>|null $case The case object OR returns.
	 *
	 * @return MandaatMatrixController
	 */
	private function controllerWithCase(?array $case): MandaatMatrixController {
		$objectService = $this->createMock(MandaatCaseObjectServiceStub::class);
		$objectService->method('find')->willReturn($case);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return ['register' => 'procest', 'case_schema' => 'case'][$key] ?? '';
			}
		);

		return new MandaatMatrixController(
			appName: 'procest',
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			check: $this->createMock(MandaatCheckService::class),
			escalatie: $this->createMock(MandaatEscalatieService::class),
			gebruik: $this->createMock(MandaatGebruikService::class),
			import: $this->createMock(MandaatImportService::class),
			settings: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controllerWithCase()

	/**
	 * Invoke a private method on the controller.
	 *
	 * @param MandaatMatrixController $controller The controller.
	 * @param string $method Method name.
	 * @param array<int, mixed> $args Arguments.
	 *
	 * @return mixed
	 */
	private function invokePrivate(MandaatMatrixController $controller, string $method, array $args): mixed {
		$ref = new ReflectionMethod(MandaatMatrixController::class, $method);
		$ref->setAccessible(true);

		return $ref->invokeArgs($controller, $args);
	}//end invokePrivate()

	/**
	 * Every client-supplied identity key is stripped from case properties.
	 *
	 * @return void
	 */
	public function testClientSuppliedIdentityKeysAreStripped(): void {
		$controller = $this->controllerWithCase(null);

		$result = $this->invokePrivate(
			$controller,
			'stripClientSuppliedIdentity',
			[
				[
					'userBsn' => '111',
					'applicantBsn' => '222',
					'userBsnHash' => 'deadbeef',
					'applicantBsnHash' => 'cafebabe',
					'bedragCents' => 100000,
					'caseType' => 'wmo',
				],
			]
		);

		self::assertArrayNotHasKey('userBsn', $result);
		self::assertArrayNotHasKey('applicantBsn', $result);
		self::assertArrayNotHasKey('userBsnHash', $result);
		self::assertArrayNotHasKey('applicantBsnHash', $result);

		// Non-identity condition properties survive — the mandaat matrix still
		// needs them.
		self::assertSame(100000, $result['bedragCents']);
		self::assertSame('wmo', $result['caseType']);
	}//end testClientSuppliedIdentityKeysAreStripped()

	/**
	 * A natural-person initiator yields the applicant BSN from the case object.
	 *
	 * @return void
	 */
	public function testApplicantIdentityIsResolvedFromTheCaseObject(): void {
		$controller = $this->controllerWithCase(
			[
				'id' => 'case-1',
				'initiatorType' => 'person',
				'initiatorSourceId' => '123456789',
			]
		);

		$result = $this->invokePrivate($controller, 'resolveApplicantIdentity', ['case-1']);

		self::assertSame(['applicantBsn' => '123456789'], $result);
	}//end testApplicantIdentityIsResolvedFromTheCaseObject()

	/**
	 * A company initiator carries a KvK number, not a BSN — no natural person,
	 * so no applicant identity.
	 *
	 * @return void
	 */
	public function testCompanyInitiatorYieldsNoApplicantIdentity(): void {
		$controller = $this->controllerWithCase(
			[
				'id' => 'case-1',
				'initiatorType' => 'company',
				'initiatorSourceId' => '12345678',
			]
		);

		$result = $this->invokePrivate($controller, 'resolveApplicantIdentity', ['case-1']);

		self::assertSame([], $result);
	}//end testCompanyInitiatorYieldsNoApplicantIdentity()

	/**
	 * An unresolvable case yields no applicant identity.
	 *
	 * @return void
	 */
	public function testUnresolvableCaseYieldsNoApplicantIdentity(): void {
		$controller = $this->controllerWithCase(null);

		$result = $this->invokePrivate($controller, 'resolveApplicantIdentity', ['case-missing']);

		self::assertSame([], $result);
	}//end testUnresolvableCaseYieldsNoApplicantIdentity()

	/**
	 * The server-derived applicant identity must WIN over anything the client
	 * sent — the strip-then-merge order is the whole point.
	 *
	 * @return void
	 */
	public function testServerDerivedApplicantOverridesClientClaim(): void {
		$controller = $this->controllerWithCase(
			[
				'id' => 'case-1',
				'initiatorType' => 'person',
				'initiatorSourceId' => '123456789',
			]
		);

		$clientProps = [
			'userBsn' => '999999999',
			'applicantBsn' => '000000000',
			'bedragCents' => 100000,
		];

		$stripped = $this->invokePrivate($controller, 'stripClientSuppliedIdentity', [$clientProps]);
		$resolved = $this->invokePrivate($controller, 'resolveApplicantIdentity', ['case-1']);
		$merged = array_merge($stripped, $resolved);

		self::assertSame('123456789', $merged['applicantBsn']);
		self::assertArrayNotHasKey('userBsn', $merged);
	}//end testServerDerivedApplicantOverridesClientClaim()
}//end class
