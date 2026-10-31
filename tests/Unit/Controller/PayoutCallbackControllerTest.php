<?php

/**
 * PayoutCallbackController tests.
 *
 * The callback fails closed (401) without a secret, rejects a wrong
 * signature, and accepts a body signed in either header: X-Shillinq-Signature
 * (the finance app) or the frozen X-Procest-Signature (the external provider).
 * The finance app's approved, paid and rejected land on the stored statuses.
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

use OCA\Dossiq\Controller\PayoutCallbackController;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Money\SignedPayoutCallback;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\PayoutCallbackController
 * @covers \OCA\Dossiq\Service\Money\SignedPayoutCallback
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class PayoutCallbackControllerTest extends TestCase {
	use MakesCaseDateNormaliser;

	private const SECRET = 'super-secret';

	/** @var PayoutService&MockObject */
	private PayoutService $service;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: PayoutService::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller over a request carrying the given headers and body.
	 *
	 * @param array<string, string> $headers Header values by name.
	 * @param string $body The raw body.
	 * @param string $secret The configured secret.
	 * @param string $legacySecret The secret under the key it was stored under before the rename.
	 * @param PayoutReader|null $reader Knows the payouts only finance holds.
	 *
	 * @return PayoutCallbackController
	 */
	private function controller(array $headers, string $body, string $secret = self::SECRET, string $legacySecret = '', ?PayoutReader $reader = null): PayoutCallbackController {
		$stored = array_filter([SignedPayoutCallback::SECRET_KEY => $secret, MoneyConfigKeys::LEGACY_KEYS[SignedPayoutCallback::SECRET_KEY] => $legacySecret]);
		$this->appConfig->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => array_key_exists($key, $stored));
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[$name] ?? '')
		);

		return new class(
			appName: 'dossiq',
			request: $request,
			service: $this->service,
			callback: new SignedPayoutCallback(appConfig: $this->appConfig),
			dates: $this->caseDates(),
			logger: $this->logger,
			body: $body,
			reader: $reader,
		) extends PayoutCallbackController {
			/**
			 * @param string $appName App id.
			 * @param IRequest $request Request.
			 * @param PayoutService $service Service.
			 * @param SignedPayoutCallback $callback Callback reader.
			 * @param \OCA\Dossiq\Service\CaseDateNormaliser $dates Dates.
			 * @param LoggerInterface $logger Logger.
			 * @param string $body The raw body this request carries.
			 */
			public function __construct(
				string $appName,
				IRequest $request,
				PayoutService $service,
				SignedPayoutCallback $callback,
				\OCA\Dossiq\Service\CaseDateNormaliser $dates,
				LoggerInterface $logger,
				private readonly string $body,
				?PayoutReader $reader = null,
			) {
				parent::__construct($appName, $request, $service, $callback, $dates, $logger, $reader);
			}

			/**
			 * @return string
			 */
			protected function rawBody(): string {
				return $this->body;
			}
		};
	}//end controller()

	/**
	 * An unconfigured secret fails closed (401), never an implicit pass.
	 *
	 * @return void
	 */
	public function testCallbackRejectsWhenSecretUnconfigured(): void {
		$body = (string)json_encode(['reference' => 'REF-123', 'status' => 'paid']);
		$this->logger->expects($this->atLeastOnce())->method('warning');
		$this->service->expects($this->never())->method('handleCallback');

		$response = $this->controller(headers: ['X-Shillinq-Signature' => hash_hmac('sha256', $body, '')], body: $body, secret: '')->callback();

		$this->assertSame(expected: 401, actual: $response->getStatus());
	}//end testCallbackRejectsWhenSecretUnconfigured()

	/**
	 * A secret stored under its old key still verifies until the upgrade carries it (REQ-DQM-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
	 */
	public function testASecretUnderTheOldKeyStillVerifies(): void {
		$body = (string)json_encode(['reference' => 'REF-123', 'status' => 'paid']);
		$this->service->expects($this->once())->method('handleCallback')->willReturn(['status' => 'paid']);

		$response = $this->controller(
			headers: ['X-Shillinq-Signature' => hash_hmac('sha256', $body, self::SECRET)],
			body: $body,
			secret: '',
			legacySecret: self::SECRET
		)->callback();

		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testASecretUnderTheOldKeyStillVerifies()

	/**
	 * A callback for a payout only finance holds is accepted without a row; an
	 * unknown reference is still a 404 (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testACallbackForAPayoutFinanceHoldsNeedsNoRow(): void {
		$this->service->method('handleCallback')->willThrowException(new RuntimeException('No payout for reference'));
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				$event->answer([['payableId' => 'p-9', 'reference' => 'PROC-DWS-NINE', 'state' => 'paid']]);
			}
		);
		$reader = new PayoutReader(dispatcher: $dispatcher);

		foreach ([['PROC-DWS-NINE', 200], ['NOBODY-KNOWS', 404]] as [$reference, $expected]) {
			$body = (string)json_encode(['reference' => $reference, 'status' => 'paid']);
			$response = $this->controller(headers: ['X-Shillinq-Signature' => hash_hmac('sha256', $body, self::SECRET)], body: $body, reader: $reader)->callback();
			$this->assertSame(expected: $expected, actual: $response->getStatus(), message: $reference);
		}
	}//end testACallbackForAPayoutFinanceHoldsNeedsNoRow()

	/**
	 * A signature that does not match the secret is rejected in either header.
	 *
	 * @return void
	 */
	public function testCallbackRejectsInvalidSignature(): void {
		$body = (string)json_encode(['reference' => 'REF-123', 'status' => 'paid']);
		$this->service->expects($this->never())->method('handleCallback');

		$response = $this->controller(
			headers: ['X-Procest-Signature' => 'not-valid', 'X-Shillinq-Signature' => hash_hmac('sha256', $body, 'other')],
			body: $body
		)->callback();

		$this->assertSame(expected: 401, actual: $response->getStatus());
	}//end testCallbackRejectsInvalidSignature()

	/**
	 * The provider's frozen header still works, with its own status words.
	 *
	 * @return void
	 */
	public function testTheProviderHeaderStillWorks(): void {
		$body = (string)json_encode(['reference' => 'REF-123', 'status' => 'betaald', 'betalingsreferentie' => 'ERP-1', 'actualPaymentDate' => '2026-04-20']);
		$this->service->expects($this->once())->method('handleCallback')
			->with('REF-123', 'paid', $this->isInstanceOf(\DateTimeImmutable::class), 'ERP-1')
			->willReturn(['status' => 'paid']);

		$response = $this->controller(headers: ['X-Procest-Signature' => hash_hmac('sha256', $body, self::SECRET)], body: $body)->callback();

		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testTheProviderHeaderStillWorks()

	/**
	 * The finance app's approved, paid and rejected map onto in-handling, paid
	 * and rejected, read from its body shape (bankReference).
	 *
	 * @return void
	 */
	public function testTheFinanceAppStatusesMapOntoStoredStatuses(): void {
		$calls = [];
		$this->service->method('handleCallback')->willReturnCallback(
			static function (string $reference, string $status, ?\DateTimeImmutable $date, string $bankRef) use (&$calls): array {
				$calls[] = [$reference, $status, $date?->format('Y-m-d'), $bankRef];
				return ['status' => $status];
			}
		);

		foreach (['approved' => '', 'paid' => '2026-10-20', 'rejected' => ''] as $status => $date) {
			$body = (string)json_encode(
				['contractVersion' => 1, 'payableId' => 'p-1', 'reference' => 'PROC-DWS-1', 'status' => $status,
					'actualPaymentDate' => $date, 'bankReference' => ($status === 'paid' ? 'SEPA-777' : ''), 'reason' => '']
			);
			$response = $this->controller(headers: ['X-Shillinq-Signature' => hash_hmac('sha256', $body, self::SECRET)], body: $body)->callback();
			$this->assertSame(expected: 200, actual: $response->getStatus(), message: $status);
		}

		$this->assertSame(
			expected: [
				['PROC-DWS-1', 'in-handling', null, ''],
				['PROC-DWS-1', 'paid', '2026-10-20', 'SEPA-777'],
				['PROC-DWS-1', 'rejected', null, ''],
			],
			actual: $calls
		);
	}//end testTheFinanceAppStatusesMapOntoStoredStatuses()

	/**
	 * An unknown status or a malformed date is a 400, and an unknown reference a 404.
	 *
	 * @return void
	 */
	public function testBadBodiesAreRefused(): void {
		$this->service->method('handleCallback')->willThrowException(new RuntimeException('No payout for reference X'));
		$unknown = (string)json_encode(['reference' => 'REF-404', 'status' => 'paid']);
		$badStatus = (string)json_encode(['reference' => 'X', 'status' => 'cancelled']);
		$badDate = (string)json_encode(['reference' => 'X', 'status' => 'paid', 'actualPaymentDate' => '20-10-2026']);

		foreach ([[$badStatus, 400], [$badDate, 400], [$unknown, 404], ['not json', 400]] as [$body, $expected]) {
			$response = $this->controller(headers: ['X-Shillinq-Signature' => hash_hmac('sha256', $body, self::SECRET)], body: $body)->callback();
			$this->assertSame(expected: $expected, actual: $response->getStatus(), message: $body);
		}
	}//end testBadBodiesAreRefused()
}//end class
