<?php

/**
 * Which case type an intake path opens.
 *
 * Decision 182: a procedure is configuration. A path that opens a case (a
 * portal action, a drain of old intake objects) is named by a key, and ONE
 * app setting, `portal_filing_case_types`, maps each key to a case type: its
 * identifier (its slug) or its uuid. This class turns a key into the uuid a
 * case's `caseType` stores, so no code names a procedure and an instance can
 * point a path at its own case type, add one, or switch one off.
 *
 * The setting is a JSON object, for example
 * `{"createBezwaar": "bezwaar", "createKlacht": "klacht-behandeling"}`; that
 * example is also what an instance that sets nothing gets. A key the setting
 * maps to the empty string opens nothing.
 *
 * 🔴 AN UNKNOWN CASE TYPE ANSWERS THE EMPTY STRING AND IS LOGGED. A caller
 * that then wrote a case would be refused for the missing `caseType`; a caller
 * that offers a form leaves the form out instead of offering one that always
 * fails.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a configured case type reference to its uuid.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class IntakeCaseTypes {

	use SearchesObjects;

	/**
	 * The setting mapping each case-opening path to its case type.
	 *
	 * @var string
	 */
	public const SETTING = 'portal_filing_case_types';

	/**
	 * What an instance that sets nothing gets: the portal actions dossiq
	 * declares, each on the case type dossiq ships for it.
	 *
	 * @var string
	 */
	public const SHIPPED = '{"createBezwaar": "bezwaar", "createKlacht": "klacht-behandeling"}';

	/**
	 * What this request already resolved, by reference.
	 *
	 * @var array<string, string>
	 */
	private array $resolved = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reads the setting and reaches OpenRegister.
	 * @param LoggerInterface $logger          Records a reference no case type answers to.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uuid of the case type a path opens.
	 *
	 * @param string $path The path's key: a portal action id.
	 *
	 * @return string The case type uuid, or the empty string when none answers.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function caseTypeFor(string $path): string {
		$reference = ($this->configured()[$path] ?? '');
		if ($reference === '') {
			return '';
		}

		if (array_key_exists($reference, $this->resolved) === false) {
			$this->resolved[$reference] = $this->lookup(reference: $reference);
		}

		if ($this->resolved[$reference] === '') {
			$this->logger->warning(
				'Dossiq intake: no case type answers to the configured reference',
				['setting' => self::SETTING, 'path' => $path, 'reference' => $reference]
			);
		}

		return $this->resolved[$reference];
	}//end caseTypeFor()

	/**
	 * Every configured path with the case type it opens.
	 *
	 * @return array<string, string> Path key to case type uuid; a path whose case type is unknown is left out.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function caseTypes(): array {
		$caseTypes = [];
		foreach (array_keys($this->configured()) as $path) {
			$caseType = $this->caseTypeFor(path: (string)$path);
			if ($caseType !== '') {
				$caseTypes[(string)$path] = $caseType;
			}
		}

		return $caseTypes;
	}//end caseTypes()

	/**
	 * The setting as a map of path key to case type reference.
	 *
	 * A setting that does not read as a JSON object is logged and treated as
	 * empty: it opens nothing, rather than quietly opening the shipped types
	 * an administrator meant to replace.
	 *
	 * @return array<string, string> Path key to reference.
	 */
	private function configured(): array {
		$raw = trim($this->settingsService->getConfigValue(self::SETTING, self::SHIPPED));
		if ($raw === '') {
			$raw = self::SHIPPED;
		}

		$map = json_decode($raw, true);
		if (is_array($map) === false) {
			$this->logger->warning('Dossiq intake: the setting does not read as a JSON object', ['setting' => self::SETTING]);
			return [];
		}

		$configured = [];
		foreach ($map as $path => $reference) {
			if (is_string($reference) === true) {
				$configured[(string)$path] = trim($reference);
			}
		}

		return $configured;
	}//end configured()

	/**
	 * Find a case type by uuid, or by its identifier.
	 *
	 * @param string $reference A uuid or an identifier.
	 *
	 * @return string The uuid, or the empty string.
	 */
	private function lookup(string $reference): string {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1) {
			return $reference;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return '';
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['identifier' => $reference, '_limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq intake: the case type could not be looked up',
				['reference' => $reference, 'exception' => $e->getMessage()]
			);
			return '';
		}

		$row = ($rows[0] ?? []);

		return trim((string)($row['id'] ?? ($row['@self']['id'] ?? '')));
	}//end lookup()
}//end class
