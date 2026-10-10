<?php

/**
 * Which case type an intake path opens.
 *
 * Decision 182: a procedure is configuration. A path that opens a case (a
 * portal action, a drain of old intake objects) names its case type by an app
 * setting, and the setting holds the case type's identifier (its slug) or its
 * uuid. This class turns that setting into the uuid a case's `caseType`
 * stores, so no code names a procedure and an instance can point a path at
 * its own case type.
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
	 * The setting naming the case type a portal objection opens.
	 *
	 * @var string
	 */
	public const OBJECTION = 'portal_objection_case_type';

	/**
	 * The setting naming the case type a portal complaint opens.
	 *
	 * @var string
	 */
	public const COMPLAINT = 'portal_complaint_case_type';

	/**
	 * The shipped case type each setting falls back to.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = [
		self::OBJECTION => 'bezwaar-behandeling',
		self::COMPLAINT => 'klacht-behandeling',
	];

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
	 * The uuid of the case type a setting names.
	 *
	 * @param string $setting One of the setting constants.
	 *
	 * @return string The case type uuid, or the empty string when none answers.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function caseTypeFor(string $setting): string {
		$reference = trim($this->settingsService->getConfigValue($setting, (self::DEFAULTS[$setting] ?? '')));
		if ($reference === '') {
			return '';
		}

		if (array_key_exists($reference, $this->resolved) === false) {
			$this->resolved[$reference] = $this->lookup(reference: $reference);
		}

		if ($this->resolved[$reference] === '') {
			$this->logger->warning(
				'Dossiq intake: no case type answers to the configured reference',
				['setting' => $setting, 'reference' => $reference]
			);
		}

		return $this->resolved[$reference];
	}//end caseTypeFor()

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
