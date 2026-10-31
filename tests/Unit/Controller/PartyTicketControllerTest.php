<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PartyTicketController;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The open tickets of parties, as the organisation list and page read them.
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */
class PartyTicketControllerTest extends TestCase {
	/**
	 * The status filters the gateway was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $statuses = [];

	/**
	 * A controller over a gateway answering the given tickets, and a request with the given parameters.
	 *
	 * @param array<string, mixed>|null $tickets Tickets per master entity, or null for an absent pipelinq.
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<int, array<int, string>> $asked Receives the references the gateway was asked for.
	 *
	 * @return PartyTicketController The controller under test.
	 */
	private function controller(?array $tickets, array $params, array &$asked = []): PartyTicketController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$gateway = $this->createMock(originalClassName: PipelinqGateway::class);
		$gateway->method('openTicketsByMasterEntity')->willReturnCallback(
			static function (array $refs) use ($tickets, &$asked): ?array {
				$asked[] = $refs;
				return $tickets;
			}
		);
		$gateway->method('ticketsByMasterEntity')->willReturnCallback(
			function (array $refs, string $status) use ($tickets, &$asked): ?array {
				$asked[] = $refs;
				$this->statuses[] = $status;
				return $tickets;
			}
		);

		return new PartyTicketController(appName: 'dossiq', request: $request, gateway: $gateway);
	}//end controller()

	/**
	 * The list reads one count per master entity, 0 for one with no tickets.
	 *
	 * @return void
	 */
	public function testCountsArePerMasterEntity(): void {
		$asked = [];
		$response = $this->controller(
			tickets: ['m1' => [['id' => 't1'], ['id' => 't2']], 'm2' => []],
			params: ['keys' => ['m1', 'm2', 'm1', '']],
			asked: $asked
		)->openTicketCounts();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['available' => true, 'values' => ['m1' => 2, 'm2' => 0]], actual: $response->getData());
		$this->assertSame(expected: [['m1', 'm2']], actual: $asked);
	}//end testCountsArePerMasterEntity()

	/**
	 * Without pipelinq the list hears that, so it can leave the column out.
	 *
	 * @return void
	 */
	public function testAnAbsentPipelinqIsUnavailable(): void {
		$response = $this->controller(tickets: null, params: ['keys' => ['m1']])->openTicketCounts();

		$this->assertSame(expected: ['available' => false], actual: $response->getData());
	}//end testAnAbsentPipelinqIsUnavailable()

	/**
	 * More master entities than one page holds are refused.
	 *
	 * @return void
	 */
	public function testTooManyKeysAreRefused(): void {
		$keys = array_map(static fn (int $i): string => 'm'.$i, range(1, PartyTicketController::MAX_KEYS + 1));

		$this->assertSame(expected: 400, actual: $this->controller(tickets: [], params: ['keys' => $keys])->openTicketCounts()->getStatus());
	}//end testTooManyKeysAreRefused()

	/**
	 * The organisation page reads its own open tickets as a list.
	 *
	 * @return void
	 */
	public function testThePageReadsOnePartysTickets(): void {
		$response = $this->controller(tickets: ['m1' => [['id' => 't1', 'title' => 'Vraag']]], params: ['key' => 'm1'])->openTickets();

		$this->assertSame(expected: ['available' => true, 'results' => [['id' => 't1', 'title' => 'Vraag']], 'total' => 1], actual: $response->getData());
	}//end testThePageReadsOnePartysTickets()

	/**
	 * A party without a master entity has no tickets, and pipelinq is not asked.
	 *
	 * @return void
	 */
	public function testAPartyWithoutAMasterEntityHasNone(): void {
		$asked = [];
		$response = $this->controller(tickets: [], params: ['key' => ''], asked: $asked)->openTickets();

		$this->assertSame(expected: ['available' => true, 'results' => [], 'total' => 0], actual: $response->getData());
		$this->assertSame(expected: [], actual: $asked);
	}//end testAPartyWithoutAMasterEntityHasNone()

	/**
	 * The Tickets card asks for all of a party's tickets; anything but
	 * `closed` or `all` reads the open ones.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	public function testTheStatusParameterPicksTheTickets(): void {
		$tickets = ['m1' => [['id' => 't1', 'status' => 'in_progress'], ['id' => 't4', 'status' => 'resolved']]];
		$response = $this->controller(tickets: $tickets, params: ['key' => 'm1', 'status' => 'all'])->openTickets();
		$this->assertSame(expected: 2, actual: $response->getData()['total']);

		$this->controller(tickets: $tickets, params: ['key' => 'm1', 'status' => 'deleted'])->openTickets();
		$this->controller(tickets: $tickets, params: ['key' => 'm1'])->openTickets();
		$this->assertSame(expected: ['all', 'open', 'open'], actual: $this->statuses);
	}//end testTheStatusParameterPicksTheTickets()
}//end class
