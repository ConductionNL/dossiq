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
	 * The case type a voorstel belongs to, derived from its linked case.
	 *
	 * The voorstel schema declares no `caseType` property — OpenRegister never
	 * returns undeclared properties, so reading one off the voorstel yields ''
	 * for every voorstel and every raise refuses as unroutable. The case type
	 * lives where the schema says it lives: on the case the voorstel's declared
	 * `case` reference points at.
	 *
	 * @param array<string, mixed> $voorstel The voorstel row.
	 *
	 * @return string The case type id, or '' when the voorstel has no case,
	 *                the case cannot be read, or the case carries no type.
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function caseTypeOfVoorstel(array $voorstel): string {
		$caseId = $this->relationId(value: ($voorstel['case'] ?? null));
		if ($caseId === '') {
			return '';
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: could not read the voorstel\'s case for the case type',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			return '';
		}

		if ($case === null) {
			return '';
		}

		return $this->relationId(value: ($case['caseType'] ?? null));

	}//end caseTypeOfVoorstel()

	/**
	 * The id inside a relation value, whichever shape OpenRegister returned.
	 *
	 * An unextended `$ref` property is a uuid string; an extended one is the
	 * related object itself, whose id sits on the row or in its `@self` block.
	 *
	 * @param mixed $value The stored relation value.
	 *
	 * @return string The related object's id, or ''.
	 */
	private function relationId(mixed $value): string {
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? ''))));
		}

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';

	}//end relationId()

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
		if ($caseTypeId === '') {
			// A voorstel whose case type could not be resolved matches no
			// route by definition; an empty filter must not match one either.
			return null;
		}

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
