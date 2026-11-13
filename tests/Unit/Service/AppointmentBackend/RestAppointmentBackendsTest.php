<?php

/**
 * The JCC and Qmatic appointment backends: calls, answers and failures
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\AppointmentBackend
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

namespace OCA\Dossiq\Tests\Unit\Service\AppointmentBackend;

use OCA\Dossiq\Service\AppointmentBackend\AppointmentBackendInterface;
use OCA\Dossiq\Service\AppointmentBackend\JccBackend;
use OCA\Dossiq\Service\AppointmentBackend\QmaticBackend;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every call each backend makes, what it answers, and what it logs when the API fails.
 *
 * @covers \OCA\Dossiq\Service\AppointmentBackend\JccBackend
 * @covers \OCA\Dossiq\Service\AppointmentBackend\QmaticBackend
 * @uses \OCA\Dossiq\Service\AppointmentBackend\RestAppointmentBackend
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class RestAppointmentBackendsTest extends TestCase {
	/**
	 * The HTTP calls made, in order, as [verb, url, options].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The error lines logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A backend over a client that records each call and answers $body, or throws when $body is null.
	 *
	 * @param string      $kind 'jcc' or 'qmatic'.
	 * @param string|null $body The response body, or null to make every call throw.
	 *
	 * @return AppointmentBackendInterface
	 */
	private function backend(string $kind, ?string $body): AppointmentBackendInterface {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string) $body);
		$client = $this->createMock(IClient::class);
		foreach (['get', 'post', 'delete'] as $verb) {
			$client->method($verb)->willReturnCallback(
				function (string $url, array $options) use ($verb, $body, $response): IResponse {
					$this->calls[] = [$verb, $url, $options];
					if ($body === null) {
						throw new RuntimeException('down');
					}

					return $response;
				}
			);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		if ($kind === 'jcc') {
			return new JccBackend(clientService: $clientService, logger: $logger, apiUrl: 'https://api', apiKey: 'k');
		}

		return new QmaticBackend(clientService: $clientService, logger: $logger, apiUrl: 'https://api', apiKey: 'k');
	}//end backend()

	/**
	 * JCC asks /timeslots with a query and a bearer key, and answers the decoded body.
	 *
	 * @return void
	 */
	public function testJccTimeslots(): void {
		$answer = $this->backend('jcc', '[{"time":"09:00"}]')->getTimeslots('p', 'l', '2026-01-02');

		$this->assertSame([['time' => '09:00']], $answer);
		$this->assertSame(
			[['get', 'https://api/timeslots', ['query' => ['product' => 'p', 'location' => 'l', 'date' => '2026-01-02'], 'headers' => ['Authorization' => 'Bearer k']]]],
			$this->calls
		);
	}//end testJccTimeslots()

	/**
	 * Qmatic asks the branch/service/date path with an auth token and reshapes the times.
	 *
	 * @return void
	 */
	public function testQmaticTimeslots(): void {
		$answer = $this->backend('qmatic', '{"times":[{"time":"09:00"},{}]}')->getTimeslots('p', 'l', '2026-01-02');

		$this->assertSame(
			[
				['time' => '09:00', 'duration' => 30, 'available' => true],
				['time' => '', 'duration' => 30, 'available' => true],
			],
			$answer
		);
		$this->assertSame(
			[['get', 'https://api/branches/l/services/p/dates/2026-01-02/times', ['headers' => ['auth-token' => 'k']]]],
			$this->calls
		);
	}//end testQmaticTimeslots()

	/**
	 * An undecodable body answers an empty list for both.
	 *
	 * @return void
	 */
	public function testTimeslotsOfAnEmptyBody(): void {
		$this->assertSame([], $this->backend('jcc', '')->getTimeslots('p', 'l', 'd'));
		$this->assertSame([], $this->backend('qmatic', '')->getTimeslots('p', 'l', 'd'));
	}//end testTimeslotsOfAnEmptyBody()

	/**
	 * A failing timeslot call answers [] and logs the vendor's API error.
	 *
	 * @return void
	 */
	public function testTimeslotFailures(): void {
		$this->assertSame([], $this->backend('jcc', null)->getTimeslots('p', 'l', 'd'));
		$this->assertSame([], $this->backend('qmatic', null)->getTimeslots('p', 'l', 'd'));
		$this->assertSame(['JCC API error: down', 'Qmatic API error: down'], $this->errors);
	}//end testTimeslotFailures()

	/**
	 * Booking posts the data as JSON with each vendor's auth header and answers the decoded body.
	 *
	 * @return void
	 */
	public function testBooking(): void {
		$this->assertSame(['id' => 'a'], $this->backend('jcc', '{"id":"a"}')->bookAppointment(['x' => 1]));
		$this->assertSame(['id' => 'b'], $this->backend('qmatic', '{"id":"b"}')->bookAppointment(['x' => 2]));
		$this->assertSame([], $this->backend('qmatic', 'null')->bookAppointment([]));
		$this->assertSame(
			[
				['post', 'https://api/appointments', ['json' => ['x' => 1], 'headers' => ['Authorization' => 'Bearer k']]],
				['post', 'https://api/appointments', ['json' => ['x' => 2], 'headers' => ['auth-token' => 'k']]],
				['post', 'https://api/appointments', ['json' => [], 'headers' => ['auth-token' => 'k']]],
			],
			$this->calls
		);
	}//end testBooking()

	/**
	 * A failing booking answers the error and logs it.
	 *
	 * @return void
	 */
	public function testBookingFailures(): void {
		$this->assertSame(['error' => 'down'], $this->backend('jcc', null)->bookAppointment([]));
		$this->assertSame(['error' => 'down'], $this->backend('qmatic', null)->bookAppointment([]));
		$this->assertSame(['JCC booking error: down', 'Qmatic booking error: down'], $this->errors);
	}//end testBookingFailures()

	/**
	 * Cancelling deletes the appointment and answers true, or false with a logged error.
	 *
	 * @return void
	 */
	public function testCancelling(): void {
		$this->assertTrue($this->backend('jcc', '')->cancelAppointment('e1'));
		$this->assertTrue($this->backend('qmatic', '')->cancelAppointment('e2'));
		$this->assertFalse($this->backend('jcc', null)->cancelAppointment('e3'));
		$this->assertFalse($this->backend('qmatic', null)->cancelAppointment('e4'));
		$this->assertSame(
			[
				['delete', 'https://api/appointments/e1', ['headers' => ['Authorization' => 'Bearer k']]],
				['delete', 'https://api/appointments/e2', ['headers' => ['auth-token' => 'k']]],
				['delete', 'https://api/appointments/e3', ['headers' => ['Authorization' => 'Bearer k']]],
				['delete', 'https://api/appointments/e4', ['headers' => ['auth-token' => 'k']]],
			],
			$this->calls
		);
		$this->assertSame(['JCC cancel error: down', 'Qmatic cancel error: down'], $this->errors);
	}//end testCancelling()

	/**
	 * Rescheduling cancels, then books the new time; JCC also sends the old external id.
	 *
	 * @return void
	 */
	public function testRescheduling(): void {
		$this->assertSame(['id' => 'n'], $this->backend('jcc', '{"id":"n"}')->rescheduleAppointment('e1', 'T1'));
		$this->assertSame(['id' => 'n'], $this->backend('qmatic', '{"id":"n"}')->rescheduleAppointment('e2', 'T2'));
		$this->assertSame(
			[
				['delete', 'https://api/appointments/e1', ['headers' => ['Authorization' => 'Bearer k']]],
				['post', 'https://api/appointments', ['json' => ['dateTime' => 'T1', 'externalId' => 'e1'], 'headers' => ['Authorization' => 'Bearer k']]],
				['delete', 'https://api/appointments/e2', ['headers' => ['auth-token' => 'k']]],
				['post', 'https://api/appointments', ['json' => ['dateTime' => 'T2'], 'headers' => ['auth-token' => 'k']]],
			],
			$this->calls
		);
	}//end testRescheduling()
}//end class
