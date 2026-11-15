<?php

/**
 * AccruingAmountController unit tests: the write verbs and the unavailable and missing answers.
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AccruingAmountController;
use OCA\Dossiq\Kernel\Contracts\AccruingAmountSettlerInterface;
use OCA\Dossiq\Kernel\Contracts\AmountObjectionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\OwningCaseResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An authorised caller on case-1, and what each verb answers.
 *
 * @covers \OCA\Dossiq\Controller\AccruingAmountController
 */
class AccruingAmountControllerVerbsTest extends TestCase {

	/**
	 * The settler double.
	 *
	 * @var AccruingAmountSettlerInterface&MockObject
	 */
	private AccruingAmountSettlerInterface $settler;

	/**
	 * The objection service double.
	 *
	 * @var AmountObjectionServiceInterface&MockObject
	 */
	private AmountObjectionServiceInterface $objection;

	/**
	 * The settings double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $settings;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * The owning-case resolver double.
	 *
	 * @var OwningCaseResolverInterface&MockObject
	 */
	private OwningCaseResolverInterface $owningCase;

	/**
	 * Calculation calc-1 belongs to case-1, and the caller may read and change it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settler = $this->createMock(AccruingAmountSettlerInterface::class);
		$this->objection = $this->createMock(AmountObjectionServiceInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->owningCase = $this->createMock(OwningCaseResolverInterface::class);
		$this->owningCase->method('resolveVia')->willReturn('case-1');
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return AccruingAmountController
	 */
	private function controller(): AccruingAmountController {
		return new AccruingAmountController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			settler: $this->settler,
			objection: $this->objection,
			settings: $this->settings,
			userSession: $this->session,
			caseAccess: $this->guard,
			owningCase: $this->owningCase,
		);
	}//end controller()

	/**
	 * Without a session every verb is a 403 before the owning case is resolved.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheVerbsAreForbidden(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->owningCase = $this->createMock(OwningCaseResolverInterface::class);
		$this->owningCase->expects($this->never())->method('resolveVia');

		$response = $this->controller()->settle(id: 'calc-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testWithoutASessionTheVerbsAreForbidden()

	/**
	 * Without the schema configured the show is a 503.
	 *
	 * @return void
	 */
	public function testShowWithoutTheSchemaIsUnavailable(): void {
		$this->settings->method('getObjectService')->willReturn(new \stdClass());
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			['accruing_amount_schema', '', ''],
		]);

		$response = $this->controller()->showLegacyPath(id: 'calc-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['message' => 'Service unavailable'], $response->getData());
	}//end testShowWithoutTheSchemaIsUnavailable()

	/**
	 * A find that throws is a 404.
	 *
	 * @return void
	 */
	public function testShowOfAFailingFindIsNotFound(): void {
		$this->settings->method('getObjectService')->willReturn(new class {
			/**
			 * Find, failing.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $id, mixed $register, mixed $schema): never {
				throw new RuntimeException('gone');
			}
		});
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			['accruing_amount_schema', '', '7'],
		]);

		$response = $this->controller()->show(id: 'calc-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testShowOfAFailingFindIsNotFound()

	/**
	 * A find that answers an entity serialising to a non-array is a 404, a plain array row is returned as is.
	 *
	 * @return void
	 */
	public function testShowNormalisesWhatFindReturns(): void {
		$rows = [
			'calc-odd' => new class {
				/**
				 * Not an array.
				 *
				 * @return string
				 */
				public function jsonSerialize(): string {
					return 'scalar';
				}
			},
			'calc-1' => ['id' => 'calc-1', 'bedragCents' => 1200],
		];
		$this->settings->method('getObjectService')->willReturn(new class($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $rows The rows by id.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Find a row.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $register, mixed $schema): mixed {
				return $this->rows[$id];
			}
		});
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			['accruing_amount_schema', '', '7'],
		]);

		$odd = $this->controller()->show(id: 'calc-odd');
		$plain = $this->controller()->show(id: 'calc-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $odd->getStatus());
		$this->assertSame(Http::STATUS_OK, $plain->getStatus());
		$this->assertSame(['id' => 'calc-1', 'bedragCents' => 1200], $plain->getData());
	}//end testShowNormalisesWhatFindReturns()

	/**
	 * Settling stops the calculation with the decision status and returns the row.
	 *
	 * @return void
	 */
	public function testSettleStopsTheCalculation(): void {
		$this->settler->expects($this->once())->method('settle')
			->with('accruing_amount_schema', 'calc-1', 'gestopt-wegens-decision')
			->willReturn(['id' => 'calc-1', 'status' => 'gestopt-wegens-decision']);

		$response = $this->controller()->settleLegacyPath(id: 'calc-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'calc-1', 'status' => 'gestopt-wegens-decision'], $response->getData());
	}//end testSettleStopsTheCalculation()

	/**
	 * Settling an unknown calculation is a 404.
	 *
	 * @return void
	 */
	public function testSettleOfAnUnknownCalculationIsNotFound(): void {
		$this->settler->method('settle')->willReturn(null);

		$response = $this->controller()->settle(id: 'calc-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testSettleOfAnUnknownCalculationIsNotFound()

	/**
	 * Without a body the objection is registered on the default basis with no rationale.
	 *
	 * @return void
	 */
	public function testObjectionDefaultsItsBasis(): void {
		$this->objection->expects($this->once())->method('registerObjection')
			->with('calc-1', 'AWB 7:1', '')
			->willReturn(['id' => 'calc-1', 'objection' => 'open']);

		$response = $this->controller()->objectionLegacyPath(id: 'calc-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'calc-1', 'objection' => 'open'], $response->getData());
	}//end testObjectionDefaultsItsBasis()

	/**
	 * A failing objection is a 400 carrying the failure's message.
	 *
	 * @return void
	 */
	public function testAFailingObjectionIsABadRequest(): void {
		$this->objection->method('registerObjection')
			->willThrowException(new RuntimeException('An objection is already open'));

		$response = $this->controller()->objection(id: 'calc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'An objection is already open'], $response->getData());
	}//end testAFailingObjectionIsABadRequest()

	/**
	 * Reconsidering without a new amount is a 400 and nothing is resolved.
	 *
	 * @return void
	 */
	public function testReconsiderWithoutANewAmountIsABadRequest(): void {
		$this->objection->expects($this->never())->method('resolveObjection');

		$response = $this->controller()->reconsiderLegacyPath(id: 'calc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'newBedragCents required and must be >= 0'], $response->getData());
	}//end testReconsiderWithoutANewAmountIsABadRequest()
}//end class
