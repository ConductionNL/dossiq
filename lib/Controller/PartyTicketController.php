<?php

/**
 * Dossiq Party Ticket Controller.
 *
 * The open pipelinq tickets of parties, read by the master entity a party
 * record shares with its pipelinq client:
 *
 *  - GET /api/parties/open-tickets?keys[]=...      one count per master entity (the organisation list)
 *  - GET /api/parties/open-tickets/list?key=...    one party's open tickets (the organisation page)
 *
 * Both answer `available: false` when pipelinq cannot answer, so a surface
 * leaves the tickets out instead of drawing zero. The tickets are read through
 * PipelinqGateway as the calling user, so a ticket the caller may not read is
 * neither listed nor counted.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read parties' open pipelinq tickets by master entity.
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */
class PartyTicketController extends Controller {

	/**
	 * The most master entities one count read may name: a list page.
	 *
	 * @var int
	 */
	public const MAX_KEYS = 200;

	/**
	 * Constructor.
	 *
	 * @param string          $appName The app name.
	 * @param IRequest        $request The HTTP request.
	 * @param PipelinqGateway $gateway The one seam that names pipelinq.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PipelinqGateway $gateway,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One open ticket count per master entity.
	 *
	 * @return JSONResponse `{available, values: {ref: count}}`, or `{available: false}`.
	 *
	 * @spec openspec/specs/pipelinq-consumption/spec.md
	 */
	#[NoAdminRequired]
	public function openTicketCounts(): JSONResponse {
		$keys = $this->keys(raw: $this->request->getParam('keys', []));
		if (count($keys) > self::MAX_KEYS) {
			return new JSONResponse(['error' => 'Too many parties in one read.'], Http::STATUS_BAD_REQUEST);
		}

		if ($keys === []) {
			return new JSONResponse(['available' => true, 'values' => []]);
		}

		$tickets = $this->gateway->openTicketsByMasterEntity(refs: $keys);
		if ($tickets === null) {
			return new JSONResponse(['available' => false]);
		}

		$values = [];
		foreach ($keys as $key) {
			$values[$key] = count(($tickets[$key] ?? []));
		}

		return new JSONResponse(['available' => true, 'values' => $values]);
	}//end openTicketCounts()

	/**
	 * One party's tickets: the open ones, or with `status` `closed` or `all`
	 * the handled ones too.
	 *
	 * @return JSONResponse `{available, results, total}`, or `{available: false}`.
	 *
	 * @spec openspec/specs/pipelinq-consumption/spec.md
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	#[NoAdminRequired]
	public function openTickets(): JSONResponse {
		$key = trim((string)$this->request->getParam('key', ''));
		if ($key === '') {
			return new JSONResponse(['available' => true, 'results' => [], 'total' => 0]);
		}

		$status = (string)$this->request->getParam('status', 'open');
		if (in_array($status, ['closed', 'all'], true) === false) {
			$status = 'open';
		}

		$tickets = $this->gateway->ticketsByMasterEntity(refs: [$key], status: $status);
		if ($tickets === null) {
			return new JSONResponse(['available' => false]);
		}

		$results = array_values(($tickets[$key] ?? []));

		return new JSONResponse(['available' => true, 'results' => $results, 'total' => count($results)]);
	}//end openTickets()

	/**
	 * The distinct, non-empty master entities a request names.
	 *
	 * @param mixed $raw The `keys` parameter.
	 *
	 * @return array<int, string> The keys.
	 */
	private function keys(mixed $raw): array {
		if (is_array($raw) === false) {
			$raw = [$raw];
		}

		$keys = array_map(static fn (mixed $key): string => trim((string)$key), array_filter($raw, 'is_scalar'));

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end keys()
}//end class
