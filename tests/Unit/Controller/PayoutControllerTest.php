<?php

/**
 * PayoutController tests.
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PayoutController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\PayoutController
 */
class PayoutControllerTest extends TestCase {

	/**
	 * The controller with the given access and body.
	 *
	 * @param string|null $caseId The owning case, or null when it cannot be resolved.
	 * @param bool $mayMutate Whether the caller may change the case.
	 * @param PayoutService $payouts The payout service.
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return PayoutController
	 */
	private function controller(?string $caseId, bool $mayMutate, PayoutService $payouts, array $body = []): PayoutController {
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$owning = $this->createMock(OwningCaseResolver::class);
		$owning->method('resolveVia')->willReturn($caseId);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayMutate);
		$guard->expects($this->never())->method('hasCaseReadAccess');

		return new class('dossiq', $this->createMock(IRequest::class), $payouts, $session, $guard, $owning, $body) extends PayoutController {
			/**
			 * @param string $appName App id.
			 * @param IRequest $request Request.
			 * @param PayoutService $payouts Payouts.
			 * @param IUserSession $userSession Session.
			 * @param CaseAccessGuard $caseAccess Guard.
			 * @param OwningCaseResolver $owningCase Resolver.
			 * @param array<string, mixed> $body The body this request carries.
			 */
			public function __construct(
				string $appName,
				IRequest $request,
				PayoutService $payouts,
				IUserSession $userSession,
				CaseAccessGuard $caseAccess,
				OwningCaseResolver $owningCase,
				private readonly array $body,
			) {
				parent::__construct($appName, $request, $payouts, $userSession, $caseAccess, $owningCase);
			}

			/**
			 * @return array<string, mixed>
			 */
			protected function jsonBody(): array {
				return $this->body;
			}
		};
	}//end controller()

	/**
	 * Without mutation access to the owning case, or without an owning case,
	 * the payout service is never reached.
	 *
	 * @return void
	 */
	public function testACallerWithoutMutationAccessIsRefused(): void {
		$payouts = $this->createMock(PayoutService::class);
		$payouts->expects($this->never())->method('preparePayoutFrom');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller('case-1', false, $payouts)->prepare('calc-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(null, true, $payouts)->prepare('calc-1')->getStatus());
	}//end testACallerWithoutMutationAccessIsRefused()

	/**
	 * An authorised caller prepares the payout from the body (201); a refusal is a 400.
	 *
	 * @return void
	 */
	public function testAnAuthorisedCallerPreparesThePayout(): void {
		$body = ['accountHolderName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'receiptDate' => '2026-04-01'];
		$payouts = $this->createMock(PayoutService::class);
		$payouts->expects($this->exactly(2))->method('preparePayoutFrom')
			->with('calc-1', $body)
			->willReturnOnConsecutiveCalls(['id' => 'u-1', 'payableId' => 'p-1'], $this->throwException(new RuntimeException('Invalid IBAN')));

		$created = $this->controller('case-1', true, $payouts, $body)->prepare('calc-1');
		self::assertSame(Http::STATUS_CREATED, $created->getStatus());
		self::assertSame('p-1', $created->getData()['payableId']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller('case-1', true, $payouts, $body)->prepare('calc-1')->getStatus());
	}//end testAnAuthorisedCallerPreparesThePayout()
}//end class
