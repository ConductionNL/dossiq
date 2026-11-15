<?php

/**
 * Tests for the payout callback signature check and body reader.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use InvalidArgumentException;
use OCA\Dossiq\Service\Money\SignedPayoutCallback;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A callback is signed only by the configured secret in one of the two headers,
 * and its body is read onto the stored payout vocabulary.
 *
 * @covers \OCA\Dossiq\Service\Money\SignedPayoutCallback
 */
class SignedPayoutCallbackTest extends TestCase {

	/**
	 * The body every signature test signs.
	 *
	 * @var string
	 */
	private const BODY = '{"reference":"PAY-1","status":"paid"}';

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The app config.
	 *
	 * @return IAppConfig|MockObject
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'dossiq' ? ($this->stored[$key] ?? $default) : $default)
		);
		$config->method('hasKey')->willReturnCallback(
			fn (string $app, string $key): bool => ($app === 'dossiq' && array_key_exists($key, $this->stored) === true)
		);

		return $config;
	}//end appConfig()

	/**
	 * A request carrying these headers.
	 *
	 * @param array<string, string> $headers The headers.
	 *
	 * @return IRequest|MockObject
	 */
	private function request(array $headers): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[$name] ?? '')
		);

		return $request;
	}//end request()

	/**
	 * The callback reader under test.
	 *
	 * @return SignedPayoutCallback
	 */
	private function reader(): SignedPayoutCallback {
		return new SignedPayoutCallback(appConfig: $this->appConfig());
	}//end reader()

	/**
	 * The signature of the body under a secret.
	 *
	 * @param string $secret The secret.
	 *
	 * @return string
	 */
	private static function sign(string $secret): string {
		return hash_hmac('sha256', self::BODY, $secret);
	}//end sign()

	/**
	 * A body signed with the secret, in either header, is signed.
	 *
	 * @return void
	 */
	public function testABodySignedInEitherHeaderIsSigned(): void {
		$this->stored['payout_callback_secret'] = ' s3cret ';
		$callback = $this->reader();

		$this->assertTrue($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Shillinq-Signature' => self::sign('s3cret')])));
		$this->assertTrue($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Procest-Signature' => self::sign('s3cret')])));
		$this->assertTrue(
			$callback->isSigned(
				rawBody: self::BODY,
				request: $this->request(headers: ['X-Shillinq-Signature' => 'wrong', 'X-Procest-Signature' => self::sign('s3cret')])
			)
		);
	}//end testABodySignedInEitherHeaderIsSigned()

	/**
	 * A wrong secret, a changed body, another header or no header is not signed.
	 *
	 * @return void
	 */
	public function testAnythingElseIsNotSigned(): void {
		$this->stored['payout_callback_secret'] = 's3cret';
		$callback = $this->reader();

		$this->assertFalse($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Shillinq-Signature' => self::sign('other')])));
		$this->assertFalse($callback->isSigned(rawBody: self::BODY . ' ', request: $this->request(headers: ['X-Shillinq-Signature' => self::sign('s3cret')])));
		$this->assertFalse($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Signature' => self::sign('s3cret')])));
		$this->assertFalse($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: [])));
	}//end testAnythingElseIsNotSigned()

	/**
	 * Without a secret nothing is signed, not even a body signed with the empty secret.
	 *
	 * @return void
	 */
	public function testWithoutASecretNothingIsSigned(): void {
		$callback = $this->reader();

		$this->assertFalse($callback->hasSecret());
		$this->assertFalse($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Shillinq-Signature' => self::sign('')])));
	}//end testWithoutASecretNothingIsSigned()

	/**
	 * The legacy key is read while the current key is missing.
	 *
	 * @return void
	 */
	public function testTheLegacySecretIsReadWhileTheCurrentKeyIsMissing(): void {
		$this->stored['dwangsom_callback_secret'] = 'old';
		$callback = $this->reader();

		$this->assertTrue($callback->hasSecret());
		$this->assertTrue($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Procest-Signature' => self::sign('old')])));
	}//end testTheLegacySecretIsReadWhileTheCurrentKeyIsMissing()

	/**
	 * A current key that exists but is blank is not replaced by the legacy one.
	 *
	 * @return void
	 */
	public function testABlankCurrentKeyDoesNotFallBackToTheLegacyKey(): void {
		$this->stored['payout_callback_secret'] = '';
		$this->stored['dwangsom_callback_secret'] = 'old';
		$callback = $this->reader();

		$this->assertFalse($callback->hasSecret());
		$this->assertFalse($callback->isSigned(rawBody: self::BODY, request: $this->request(headers: ['X-Procest-Signature' => self::sign('old')])));
	}//end testABlankCurrentKeyDoesNotFallBackToTheLegacyKey()

	/**
	 * A body is read with its status mapped and its fields trimmed or defaulted.
	 *
	 * @return void
	 */
	public function testReadMapsTheBody(): void {
		$this->assertSame(
			[
				'reference' => 'PAY-1',
				'status' => 'in-handling',
				'actualPaymentDate' => '2026-10-01',
				'bankReference' => 'BANK-9',
				'payableId' => 'payable-3',
			],
			$this->reader()->read(
				rawBody: '{"reference":" PAY-1 ","status":" Approved ","actualPaymentDate":" 2026-10-01 ","bankReference":"BANK-9","payableId":"payable-3"}'
			)
		);
		$this->assertSame(
			['reference' => 'PAY-2', 'status' => 'paid', 'actualPaymentDate' => '', 'bankReference' => 'BETAAL-1', 'payableId' => ''],
			$this->reader()->read(rawBody: '{"reference":"PAY-2","status":"betaald","betalingsreferentie":"BETAAL-1"}')
		);
	}//end testReadMapsTheBody()

	/**
	 * Every known status maps onto the stored vocabulary.
	 *
	 * @return void
	 */
	public function testEveryKnownStatusMaps(): void {
		$expected = [
			'approved' => 'in-handling',
			'paid' => 'paid',
			'rejected' => 'rejected',
			'in-handling' => 'in-handling',
			'in-behandeling' => 'in-handling',
			'betaald' => 'paid',
			'afgewezen' => 'rejected',
		];

		foreach ($expected as $status => $stored) {
			$read = $this->reader()->read(rawBody: json_encode(['reference' => 'PAY-1', 'status' => $status]));
			$this->assertSame($stored, $read['status'], $status);
		}
	}//end testEveryKnownStatusMaps()

	/**
	 * Unreadable bodies are refused with the reason.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unreadableBodies(): array {
		return [
			'not json' => ['nope', 'Invalid JSON body'],
			'json scalar' => ['"paid"', 'Invalid JSON body'],
			'no reference' => ['{"status":"paid"}', 'reference and status are required'],
			'blank status' => ['{"reference":"PAY-1","status":" "}', 'reference and status are required'],
			'unknown status' => ['{"reference":"PAY-1","status":"Lost"}', 'Unknown payout status: lost'],
		];
	}//end unreadableBodies()

	/**
	 * An unreadable body throws.
	 *
	 * @param string $body    The raw body.
	 * @param string $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider unreadableBodies
	 */
	public function testAnUnreadableBodyIsRefused(string $body, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->reader()->read(rawBody: $body);
	}//end testAnUnreadableBodyIsRefused()

}//end class
