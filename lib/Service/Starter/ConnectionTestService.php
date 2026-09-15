<?php

/**
 * Probing a configured connection, and saying what came back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes one real call to a configured endpoint and reports what happened.
 *
 * 🔑 A GREEN THAT WAS NEVER PROBED IS WORSE THAN NO BUTTON. Redmine posts to
 * `admin/test_email` and gets `test_connection`, and the point of both is the
 * live call. A screen that reads a config file and prints "OK" tells an
 * administrator the mail works right up to the morning a term is missed because
 * it stopped weeks ago. So this class only ever answers about a call it made.
 *
 * 🔑 UNTESTED IS ITS OWN ANSWER. `NOT_TESTED` is what a connection reads before
 * anybody pressed the button, and it is deliberately not `false`: a red cross
 * on a connection nobody has probed sends somebody debugging a working
 * integration. Every result carries the moment it was measured, so a week-old
 * green reads as a week-old green.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */
class ConnectionTestService {

	/**
	 * Nobody has probed this connection.
	 */
	public const NOT_TESTED = 'not_tested';

	/**
	 * The endpoint answered.
	 */
	public const REACHABLE = 'reachable';

	/**
	 * The endpoint did not answer, or answered an error.
	 */
	public const FAILED = 'failed';

	/**
	 * How long to wait, in seconds, before calling it a timeout.
	 *
	 * Ten, not sixty: an administrator pressing a button is watching it, and a
	 * broker that has not answered in ten seconds is not going to.
	 */
	public const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clients The Nextcloud HTTP client factory.
	 * @param LoggerInterface $logger  Logger.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a connection reads as before anybody tested it.
	 *
	 * @param string $endpoint The endpoint the connection points at.
	 *
	 * @return array{state: string, endpoint: string, status: int, reason: string, measuredAt: string}
	 */
	public function untested(string $endpoint): array {
		return [
			'state' => self::NOT_TESTED,
			'endpoint' => $endpoint,
			'status' => 0,
			'reason' => '',
			'measuredAt' => '',
		];
	}//end untested()

	/**
	 * Probe one endpoint.
	 *
	 * @param string $endpoint The URL to call.
	 *
	 * @return array{state: string, endpoint: string, status: int, reason: string, measuredAt: string, responseTimeMs: int}
	 */
	public function probe(string $endpoint): array {
		$result = ($this->untested(endpoint: $endpoint) + ['responseTimeMs' => 0]);

		if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
			$result['state'] = self::FAILED;
			$result['reason'] = 'The endpoint is not a URL.';
			$result['measuredAt'] = gmdate('c');

			return $result;
		}

		$started = microtime(true);

		try {
			$response = $this->clients->newClient()->get(
				$endpoint,
				[
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					// The probe asks whether the endpoint answers, not whether
					// it likes us. A 401 from a broker that is up is a
					// different problem from a broker that is down, and
					// throwing on it would report them the same way.
					'http_errors' => false,
				]
			);

			$status = (int)$response->getStatusCode();
			$result['status'] = $status;
			$result['responseTimeMs'] = (int)round(((microtime(true) - $started) * 1000));
			$result['measuredAt'] = gmdate('c');

			// A 4xx from a broker that is up is a different problem from a
			// broker that is down, so only a 5xx or no status at all reads as
			// the connection failing.
			$result['state'] = self::FAILED;
			if ($status > 0 && $status < 500) {
				$result['state'] = self::REACHABLE;
			}

			if ($result['state'] === self::FAILED) {
				$result['reason'] = ('The endpoint answered ' . $status . '.');
			}

			return $result;
		} catch (Throwable $e) {
			$result['state'] = self::FAILED;
			$result['reason'] = $e->getMessage();
			$result['responseTimeMs'] = (int)round(((microtime(true) - $started) * 1000));
			$result['measuredAt'] = gmdate('c');

			$this->logger->info(
				'Dossiq starter: a connection test failed',
				['endpoint' => $endpoint, 'reason' => $e->getMessage()]
			);

			return $result;
		}//end try
	}//end probe()
}//end class
