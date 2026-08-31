<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve the sign-off route for a case type.
 *
 * Extracted from BesluitvormingParafeerService::activate(), which used to do
 * this lookup inline and then carry on with an EMPTY step list when it found
 * nothing — parking the voorstel in `in_parafering` at step 1 with nothing to
 * travel. Pulling it out makes "no route" a value the caller has to handle
 * rather than a silent empty array.
 *
 * 🔴 IT READS THE LOCAL ROUTE, AND ONLY THE LOCAL ROUTE. dossiq sends the steps
 * to the decision app when a voorstel is activated; it does not read them back.
 * That is deliberate — a route resolved from the wrong place is a wrong
 * signature chain, and it would look entirely plausible: the right number of
 * steps, sensible actors, nothing to notice.
 *
 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
 */
class ParafeerrouteDirectory {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Resolves OpenRegister and the schema slugs.
	 * @param LoggerInterface $logger   Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ordered steps a voorstel of this case type must travel.
	 *
	 * @param string $caseTypeId The case type the voorstel belongs to.
	 *
	 * @return array<int, array<string, mixed>> The steps, or an empty list when
	 *                                          no route is configured.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function stepsForCaseType(string $caseTypeId): array {
		$local = $this->localRoute(caseTypeId: $caseTypeId);
		if ($local === null) {
			return [];
		}

		return $this->stepsOf(route: $local);

	}//end stepsForCaseType()

	/**
	 * The default local parafeerroute for a case type.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<string, mixed>|null The route, or null when none is configured.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function localRoute(string $caseTypeId): ?array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'parafeerroute_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId, 'isDefault' => true],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: could not list routes for case type',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);

			return null;
		}

		if ($rows === []) {
			return null;
		}

		return (array)$rows[0];

	}//end localRoute()

	/**
	 * Read a local route's steps, accepting either stored shape.
	 *
	 * @param array<string, mixed> $route The route row.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 */
	private function stepsOf(array $route): array {
		$raw = ($route['steps'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		$steps = [];
		foreach ($raw as $step) {
			if (is_array($step) === true) {
				$steps[] = $step;
			}
		}

		return $steps;

	}//end stepsOf()

}//end class
