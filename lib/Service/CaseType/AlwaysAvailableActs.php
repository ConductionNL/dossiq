<?php

/**
 * The half of "what may I do right now" that does not depend on the phase.
 *
 * A case's acts have two halves: what this phase allows, and what is allowed
 * whatever phase the case is in. Withdraw, add a document, ask a colleague,
 * record a contact moment: none of those belong to a phase, and a case model
 * that can only express phase acts either loses them or invents a phase that
 * is always active.
 *
 * 🔴 IT IS NOT A PHASE THAT IS ALWAYS ACTIVE, AND THAT IS THE DESIGN. Modelled
 * as a phase, an always-available act would appear in the phase strip, count
 * towards the progress figure and be given a phase term, and all three would
 * be wrong: the strip would show a stage a case never leaves, the progress bar
 * would never reach the end, and a term would start running on something that
 * is not work. So it is its own declared list, rendered beside the phase's acts
 * and marked, and the two halves never mix.
 *
 * 🔑 A REFUSED ACT IS SHOWN AND DISABLED, NEVER HIDDEN. An act that vanishes
 * for a reader without the role tells them the system cannot do it; one shown
 * with "this needs the role Juridisch medewerker" tells them who to ask. The
 * sentence is the guard's own.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case type's always-available acts and says which of them this
 * reader may take.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
class AlwaysAvailableActs {

	use SearchesObjects;

	/**
	 * The case type property holding the declaration.
	 *
	 * @var string
	 */
	public const DECLARATION = 'alwaysAvailableActs';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register/schema configuration and the object service.
	 * @param RoleGuard       $roles    Answers whether this reader holds a role on this case.
	 * @param LoggerInterface $logger   The logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly RoleGuard $roles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The always-available acts of one case, with their availability decided.
	 *
	 * @param string $caseId The case.
	 * @param string $userId Who is asking.
	 *
	 * @return array<int, array{id: string, label: string, description: string, alwaysAvailable: bool, available: bool, reason: string}> The acts.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function forCase(string $caseId, string $userId): array {
		$case = $this->readObject(schemaKey: 'case_schema', id: $caseId);
		if ($case === null) {
			return [];
		}

		$caseTypeId = $this->referenceId(value: ($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		$caseType = $this->readObject(schemaKey: 'case_type_schema', id: $caseTypeId);
		if ($caseType === null) {
			return [];
		}

		return $this->decided(
			declared: self::declaredOn(caseType: $caseType),
			case: $case,
			userId: $userId
		);
	}//end forCase()

	/**
	 * The acts a case type declares, normalised and in declared order.
	 *
	 * An act with no id names nothing a surface can invoke and is dropped: an
	 * unidentifiable button is one that fails when pressed.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, array<string, mixed>> The declared acts.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function declaredOn(array $caseType): array {
		$raw = ($caseType[self::DECLARATION] ?? []);
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		$acts = [];
		foreach ($raw as $act) {
			if (is_array($act) === false) {
				continue;
			}

			$id = trim((string)($act['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$acts[] = [
				'id' => $id,
				'label' => trim((string)($act['label'] ?? $id)),
				'description' => trim((string)($act['description'] ?? '')),
				'effect' => trim((string)($act['effect'] ?? '')),
				'requiredRole' => trim((string)($act['requiredRole'] ?? '')),
			];
		}

		return $acts;
	}//end declaredOn()

	/**
	 * Decide each act's availability for this reader.
	 *
	 * @param array<int, array<string, mixed>> $declared The declared acts.
	 * @param array<string, mixed>             $case     The case.
	 * @param string                           $userId   Who is asking.
	 *
	 * @return array<int, array<string, mixed>> The acts, each carrying its verdict.
	 */
	private function decided(array $declared, array $case, string $userId): array {
		$acts = [];
		foreach ($declared as $act) {
			$role = (string)$act['requiredRole'];
			$available = true;
			$reason = '';

			if ($role !== '') {
				$verdict = $this->roles->evaluate(
					guardConfig: ['allowedRoles' => [$role]],
					case: $case,
					userId: $userId
				);
				$available = $verdict->passed;
				if ($available === false) {
					$reason = (string)($verdict->failureMessage ?? '');
				}
			}

			$acts[] = [
				'id' => (string)$act['id'],
				'label' => (string)$act['label'],
				'description' => (string)$act['description'],
				// The mark. A surface renders the two halves together and
				// this is what tells them apart, so neither half has to know
				// where the other came from.
				'alwaysAvailable' => true,
				'available' => $available,
				'reason' => $reason,
			];
		}

		return $acts;
	}//end decided()

	/**
	 * Read one configured object by id, or null.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $id        The object's uuid.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function readObject(string $schemaKey, string $id): ?array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: $schemaKey);
		if ($objectService === null || $register === '' || $schema === '' || trim($id) === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: trim($id)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: an object could not be read while listing the always-available acts',
				['schema' => $schemaKey, 'id' => $id, 'error' => $e->getMessage()]
			);

			return null;
		}
	}//end readObject()

	/**
	 * The id a reference holds, whether it is an id or an expanded object.
	 *
	 * A `(string)` cast on an expanded `$ref` yields the literal "Array", and
	 * a case type that resolves to nothing reads as a case type declaring no
	 * acts — which is indistinguishable from one that genuinely declares none.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id, or ''.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['uuid'] ?? '')));
		}

		return trim((string)$value);
	}//end referenceId()
}//end class
