<?php

/**
 * Golden record of the payout callback.
 *
 * The signature check is the authentication of this public page. This test
 * records, per world, the status and body the callback answers with, every
 * call it makes and every log line, so a refactor cannot change which
 * callbacks are refused or how a payout is booked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use LogicException;
use OCA\Dossiq\Controller\PayoutCallbackController;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Money\SignedPayoutCallback;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for PayoutCallbackController's callback handling.
 *
 * @covers \OCA\Dossiq\Controller\PayoutCallbackController
 */
class PayoutCallbackGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the callback distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testCallbackAcrossWorlds(): void {
		$paid = '{"reference":"UIT-1","status":"paid","actualPaymentDate":"2026-03-02","bankReference":"BANK-9"}';
		$noDate = '{"reference":"UIT-1","status":"paid"}';
		$signed = ['signed' => true, 'secret' => true, 'service' => 'ok', 'holds' => false, 'reader' => true];
		$worlds = [
			'unsigned, no secret configured' => [$paid, ['signed' => false, 'secret' => false] + $signed],
			'unsigned, secret configured' => [$paid, ['signed' => false] + $signed],
			'not JSON' => ['{nope', $signed],
			'no reference' => ['{"status":"paid"}', $signed],
			'a malformed payment date' => ['{"reference":"UIT-1","status":"paid","actualPaymentDate":"02-03-2026x"}', $signed],
			'no payment date' => [$noDate, $signed],
			'paid with a date' => [$paid, $signed],
			'unknown reference, held by finance' => [$paid, ['service' => 'unknown', 'holds' => true] + $signed],
			'unknown reference, not held' => [$paid, ['service' => 'unknown'] + $signed],
			'unknown reference, no finance reader' => [$paid, ['service' => 'unknown', 'reader' => false] + $signed],
			'service breaks' => [$paid, ['service' => 'broken'] + $signed],
		];

		$record = [];
		foreach ($worlds as $name => [$body, $world]) {
			$this->events = [];
			$handle = new ReflectionMethod(PayoutCallbackController::class, 'handle');
			$handle->setAccessible(true);
			$response = $handle->invoke($this->controllerFor(world: $world), $body);
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'events' => $this->events];
		}

		$this->assertMatchesGolden(name: 'payout-callback', value: $record);
	}//end testCallbackAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return PayoutCallbackController
	 */
	private function controllerFor(array $world): PayoutCallbackController {
		$reading = new SignedPayoutCallback(appConfig: $this->createMock(IAppConfig::class));
		$callback = $this->createMock(SignedPayoutCallback::class);
		$callback->method('isSigned')->willReturn($world['signed']);
		$callback->method('hasSecret')->willReturn($world['secret']);
		$callback->method('read')->willReturnCallback(static fn (string $rawBody): array => $reading->read(rawBody: $rawBody));

		$reader = null;
		if ($world['reader'] === true) {
			$reader = $this->createMock(PayoutReader::class);
			$reader->method('holds')->willReturnCallback(
				function (string $reference) use ($world): bool {
					$this->events[] = 'holds ' . $reference;
					return $world['holds'];
				}
			);
		}

		return new PayoutCallbackController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			service: $this->serviceFor(world: $world),
			callback: $callback,
			dates: $this->caseDates(),
			logger: $this->recordingLogger(),
			reader: $reader,
		);
	}//end controllerFor()

	/**
	 * A payout service that records the callback and answers per the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return PayoutService
	 */
	private function serviceFor(array $world): PayoutService {
		$service = $this->createMock(PayoutService::class);
		$service->method('handleCallback')->willReturnCallback(
			function (string $reference, string $status, ?DateTimeImmutable $paymentDate, string $bankReference = '') use ($world): array {
				$this->events[] = 'handleCallback ' . json_encode([$reference, $status, $paymentDate?->format('Y-m-d H:i:s e'), $bankReference]);
				if ($world['service'] === 'unknown') {
					throw new RuntimeException('No payout with reference ' . $reference);
				}

				if ($world['service'] === 'broken') {
					throw new LogicException('register offline');
				}

				return ['reference' => $reference, 'status' => $status];
			}
		);

		return $service;
	}//end serviceFor()

	/**
	 * A logger that records each line with its level and context.
	 *
	 * @return LoggerInterface
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return $logger;
	}//end recordingLogger()
}//end class
