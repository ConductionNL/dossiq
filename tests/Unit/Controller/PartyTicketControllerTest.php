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
 * @spec openspec/changes/organisation-open-tickets/specs/pipelinq-consumption/spec.md
 */
class PartyTicketControllerTest extends TestCase {

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
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$gateway = $this->createMock(PipelinqGateway::class);
		$gateway->method('openTicketsByMasterEntity')->willReturnCallback(
			static function (array $refs) use ($tickets, &$asked): ?array {
				$asked[] = $refs;
				return $tickets;
			}
		);

		return new PartyTicketController('dossiq', $request, $gateway);
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

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['available' => true, 'values' => ['m1' => 2, 'm2' => 0]], $response->getData());
		$this->assertSame([['m1', 'm2']], $asked);
	}//end testCountsArePerMasterEntity()

	/**
	 * Without pipelinq the list hears that, so it can leave the column out.
	 *
	 * @return void
	 */
	public function testAnAbsentPipelinqIsUnavailable(): void {
		$response = $this->controller(tickets: null, params: ['keys' => ['m1']])->openTicketCounts();

		$this->assertSame(['available' => false], $response->getData());
	}//end testAnAbsentPipelinqIsUnavailable()

	/**
	 * More master entities than one page holds are refused.
	 *
	 * @return void
	 */
	public function testTooManyKeysAreRefused(): void {
		$keys = array_map(static fn (int $i): string => 'm'.$i, range(1, PartyTicketController::MAX_KEYS + 1));

		$this->assertSame(400, $this->controller(tickets: [], params: ['keys' => $keys])->openTicketCounts()->getStatus());
	}//end testTooManyKeysAreRefused()

	/**
	 * The organisation page reads its own open tickets as a list.
	 *
	 * @return void
	 */
	public function testThePageReadsOnePartysTickets(): void {
		$response = $this->controller(tickets: ['m1' => [['id' => 't1', 'title' => 'Vraag']]], params: ['key' => 'm1'])->openTickets();

		$this->assertSame(['available' => true, 'results' => [['id' => 't1', 'title' => 'Vraag']], 'total' => 1], $response->getData());
	}//end testThePageReadsOnePartysTickets()

	/**
	 * A party without a master entity has no tickets, and pipelinq is not asked.
	 *
	 * @return void
	 */
	public function testAPartyWithoutAMasterEntityHasNone(): void {
		$asked = [];
		$response = $this->controller(tickets: [], params: ['key' => ''], asked: $asked)->openTickets();

		$this->assertSame(['available' => true, 'results' => [], 'total' => 0], $response->getData());
		$this->assertSame([], $asked);
	}//end testAPartyWithoutAMasterEntityHasNone()
}//end class
