<?php

/**
 * DwangsomController authorization + dead-predicate tests.
 *
 * Two defects lived on this controller at once, and the second hid the first.
 *
 * 1. All four verbs are `@NoAdminRequired` and the only check they ran was a
 *    private `ensureAuthenticated()` — documented as a "per-object
 *    authorization guard", but it established only that somebody was logged in.
 *    Measured live, the attacker's responses were byte-identical to the
 *    owner's on every endpoint.
 *
 * 2. `ObjectService::find()` is declared `: ?ObjectEntity` and never returns an
 *    array, so `is_array($row) === false` was true for every existing
 *    berekening and `show()` answered 404 to everyone, its own case assignee
 *    included. That is what made defect 1 invisible: the endpoint denied
 *    everybody, so no probe ever saw a leak.
 *
 * Repairing 2 without 1 would have converted four dead endpoints into live
 * unguarded ones, two of which mutate penalty amounts. The guard therefore
 * landed first, and these tests pin both halves and their ordering.
 *
 * The ObjectService double below returns an ENTITY, not an array. A double that
 * returned an array would make the repaired code and the broken code
 * indistinguishable — the test would pass against the dead predicate too.
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

use JsonSerializable;
use OCA\Procest\Controller\DwangsomController;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\DwangsomBezwaarService;
use OCA\Procest\Service\DwangsomCalculationService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\OwningCaseResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for OpenRegister's ObjectEntity.
 *
 * Mirrors the real collaborator: `jsonSerialize()` is declared (ObjectEntity
 * implements JsonSerializable) while every other accessor arrives through
 * `Entity::__call()`, for which `method_exists()` is false. Returning an array
 * here instead would let a broken `is_array()` predicate pass the test.
 */
class DwangsomBerekeningEntityDouble implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The berekening payload.
	 */
	public function __construct(
		private readonly array $data,
	) {
	}//end __construct()

	/**
	 * Serialise to an array, as ObjectEntity does.
	 *
	 * @return array<string, mixed> The berekening payload.
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}//end jsonSerialize()

	/**
	 * Magic accessor, as Entity does.
	 *
	 * @param string $name The method name.
	 * @param array<mixed> $arguments The arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments) {
		return ($this->data[lcfirst(substr($name, 3))] ?? null);
	}//end __call()
}//end class

/**
 * Typed stub for the OpenRegister ObjectService (named-argument safe).
 */
interface DwangsomObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * @param int|string $id Object UUID.
	 * @param mixed $register Register id.
	 * @param mixed $schema Schema id.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;
}//end interface

/**
 * Unit tests for DwangsomController per-object authorization and the repair.
 *
 * @covers \OCA\Procest\Controller\DwangsomController
 */
class DwangsomControllerAuthzTest extends TestCase {

	private const BEREKENING_ID = 'af19757a-ca9b-457c-8839-98e5ef00478a';

	private const CASE_ID = 'b50836fb-077e-4cfd-987f-b39b11ae036d';

	/**
	 * Build a controller with the collaborators each test needs.
	 *
	 * @param OwningCaseResolver $owningCase The owning-case resolver.
	 * @param CaseAccessGuard $caseAccess The per-case guard.
	 * @param DwangsomCalculationService $calc The calculation service.
	 * @param DwangsomBezwaarService $bezwaar The bezwaar service.
	 * @param mixed $found What ObjectService::find() returns.
	 *
	 * @return DwangsomController The controller under test.
	 */
	private function makeController(
		OwningCaseResolver $owningCase,
		CaseAccessGuard $caseAccess,
		DwangsomCalculationService $calc,
		DwangsomBezwaarService $bezwaar,
		mixed $found = null,
	): DwangsomController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pro-attacker2');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(DwangsomObjectServiceStub::class);
		$objectService->method('find')->willReturn($found);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) {
				return ($key === 'register' ? '14' : '149');
			}
		);

		return new DwangsomController(
			'procest',
			$this->createMock(IRequest::class),
			$calc,
			$bezwaar,
			$settings,
			$session,
			$caseAccess,
			$owningCase
		);
	}//end makeController()

	/**
	 * A caller with no relationship to the owning case is refused, and the
	 * business services are never reached.
	 *
	 * @return void
	 */
	public function testUnrelatedCallerIsRefusedAndTheServicesAreNeverReached(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(self::CASE_ID);

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->method('hasCaseReadAccess')->willReturn(false);
		$caseAccess->method('hasCaseMutationAccess')->willReturn(false);

		$calc = $this->createMock(DwangsomCalculationService::class);
		$calc->expects($this->never())->method('stopForBeschikking');

		$bezwaar = $this->createMock(DwangsomBezwaarService::class);
		$bezwaar->expects($this->never())->method('registerBezwaar');
		$bezwaar->expects($this->never())->method('resolveBezwaar');

		$controller = $this->makeController(
			$owningCase,
			$caseAccess,
			$calc,
			$bezwaar,
			new DwangsomBerekeningEntityDouble(['id' => self::BEREKENING_ID])
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(self::BEREKENING_ID)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->beschikking(self::BEREKENING_ID)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->bezwaar(self::BEREKENING_ID)->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->bezwaarHeroverweging(self::BEREKENING_ID)->getStatus()
		);
	}//end testUnrelatedCallerIsRefusedAndTheServicesAreNeverReached()

	/**
	 * An unresolvable owning case DENIES rather than falling through.
	 *
	 * @return void
	 */
	public function testUnresolvableOwningCaseDenies(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(null);

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->expects($this->never())->method('hasCaseReadAccess');
		$caseAccess->expects($this->never())->method('hasCaseMutationAccess');

		$controller = $this->makeController(
			$owningCase,
			$caseAccess,
			$this->createMock(DwangsomCalculationService::class),
			$this->createMock(DwangsomBezwaarService::class),
			new DwangsomBerekeningEntityDouble(['id' => self::BEREKENING_ID])
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(self::BEREKENING_ID)->getStatus());
	}//end testUnresolvableOwningCaseDenies()

	/**
	 * The repair: an authorised caller gets the berekening, normalised from the
	 * ENTITY that ObjectService actually returns.
	 *
	 * Against the old `is_array($row) === false` predicate this test fails with
	 * 404 — which is the whole point of it.
	 *
	 * @return void
	 */
	public function testAuthorisedCallerGetsTheBerekeningNormalisedFromAnEntity(): void {
		$payload = [
			'id' => self::BEREKENING_ID,
			'dagtarief' => 2300,
			'cumulatievBedrag' => 11500,
		];

		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(self::CASE_ID);

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->method('hasCaseReadAccess')->willReturn(true);

		$controller = $this->makeController(
			$owningCase,
			$caseAccess,
			$this->createMock(DwangsomCalculationService::class),
			$this->createMock(DwangsomBezwaarService::class),
			new DwangsomBerekeningEntityDouble($payload)
		);

		$response = $controller->show(self::BEREKENING_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());
	}//end testAuthorisedCallerGetsTheBerekeningNormalisedFromAnEntity()

	/**
	 * A genuinely absent berekening is still 404 for an authorised caller —
	 * the repair must not turn "missing" into "found".
	 *
	 * @return void
	 */
	public function testAbsentBerekeningIsStillNotFoundForAnAuthorisedCaller(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(self::CASE_ID);

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->method('hasCaseReadAccess')->willReturn(true);

		$controller = $this->makeController(
			$owningCase,
			$caseAccess,
			$this->createMock(DwangsomCalculationService::class),
			$this->createMock(DwangsomBezwaarService::class),
			null
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(self::BEREKENING_ID)->getStatus());
	}//end testAbsentBerekeningIsStillNotFoundForAnAuthorisedCaller()

	/**
	 * Write verbs consult MUTATION access, not the wider read access.
	 *
	 * @return void
	 */
	public function testWriteVerbsConsultMutationAccess(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(self::CASE_ID);

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->expects($this->once())
			->method('hasCaseMutationAccess')
			->willReturn(false);
		$caseAccess->expects($this->never())->method('hasCaseReadAccess');

		$controller = $this->makeController(
			$owningCase,
			$caseAccess,
			$this->createMock(DwangsomCalculationService::class),
			$this->createMock(DwangsomBezwaarService::class),
			new DwangsomBerekeningEntityDouble(['id' => self::BEREKENING_ID])
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->beschikking(self::BEREKENING_ID)->getStatus());
	}//end testWriteVerbsConsultMutationAccess()
}//end class
