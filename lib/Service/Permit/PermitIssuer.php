<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Permit;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Issue a permit when decidiq concludes a case's decision positively.
 *
 * Ruben decided on 10 Oct (decision 172, Q-dossiq-L2-4), against the
 * recommendation, that a permit is issued on decidiq's decision outcome event
 * when the outcome is positive. DecisionConcludedListener hears that event
 * (`status: approved`) for a dossiq case and hands it here:
 *
 * - a case whose type declares `issuesPermit` gets one permit per decision:
 *   the holder is the case's portal subject, valid from the decision day,
 *   details copied from the case as `detailsFromCase` says;
 * - a change case (`issuesPermit.changesPlateOf`) sets the new plate on the
 *   permit it names, when that permit is the requester's and still in force.
 *
 * Revoking a permit is not decided by this event and stays out (design D2).
 *
 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
 */
class PermitIssuer {
	use SearchesObjects;

	/**
	 * The permit schema slug.
	 */
	public const SCHEMA = 'permit';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and OpenRegister.
	 * @param LoggerInterface $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Act on a positive decision outcome for a case.
	 *
	 * Never throws: the decision stands whether or not a permit could be
	 * written, and a failure is logged with the case and the decision.
	 *
	 * @param string      $caseId     The case the decision is about.
	 * @param string      $decisionId The concluded decision.
	 * @param string|null $decidedAt  When it concluded (ISO 8601), or null.
	 *
	 * @return array<string, mixed>|null The permit written or changed, or null.
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
	 */
	public function onApprovedDecision(string $caseId, string $decisionId, ?string $decidedAt): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || trim($caseId) === '') {
			return null;
		}

		try {
			$case = $this->read(objectService: $objectService, register: $register, schema: 'case', id: $caseId);
			$type = $this->read(objectService: $objectService, register: $register, schema: 'caseType', id: (string)(($case ?? [])['caseType'] ?? ''));
			$issues = (($type ?? [])['issuesPermit'] ?? null);
			if ($case === null || is_array($issues) === false || $issues === []) {
				return null;
			}

			if (trim((string)($issues['changesPlateOf'] ?? '')) !== '') {
				return $this->changePlate(objectService: $objectService, register: $register, case: $case);
			}

			return $this->issue(
				objectService: $objectService,
				register: $register,
				case: $case,
				issues: $issues,
				decisionId: $decisionId,
				decidedAt: $decidedAt
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the decision was approved, but its permit could not be written',
				['case' => $caseId, 'decision' => $decisionId, 'error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end onApprovedDecision()

	/**
	 * Write the case's permit, once per decision.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $case          The case.
	 * @param array<string, mixed> $issues        The case type's `issuesPermit`.
	 * @param string               $decisionId    The decision.
	 * @param string|null          $decidedAt     When it concluded.
	 *
	 * @return array<string, mixed>|null The permit.
	 */
	private function issue(object $objectService, string $register, array $case, array $issues, string $decisionId, ?string $decidedAt): ?array {
		$holder = trim((string)($case['portalSubject'] ?? ''));
		if ($holder === '') {
			$this->logger->info('Dossiq: an approved case without a portal subject issues no permit', ['case' => ($case['id'] ?? '')]);
			return null;
		}

		$existing = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				filters: ['decision' => $decisionId, '_limit' => 1],
			)
		);
		if (is_array($existing) === true && $existing !== []) {
			return $existing[0];
		}

		$permit = [
			'title' => $this->title(template: (string)($issues['titleTemplate'] ?? ''), case: $case),
			'kind' => (string)($issues['kind'] ?? ''),
			'theme' => (string)($issues['theme'] ?? ''),
			'portalSubject' => $holder,
			'case' => (string)($case['id'] ?? ''),
			'decision' => $decisionId,
			'validFrom' => $this->day(instant: $decidedAt),
			'status' => 'active',
		];
		foreach ((array)($issues['detailsFromCase'] ?? []) as $field => $source) {
			$value = $this->caseValue(case: $case, name: (string)$source);
			if ($value === '') {
				continue;
			}

			if ($field === 'kenteken') {
				$value = PermitPlateChange::normalisePlate(plate: $value);
			}

			$permit[(string)$field] = $value;
		}

		$permit = array_filter($permit, static fn ($value): bool => $value !== '');

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				object: $permit
			)
		);
	}//end issue()

	/**
	 * Set the plate an approved change case asked for on the permit it names.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $case          The change case.
	 *
	 * @return array<string, mixed>|null The permit, changed.
	 */
	private function changePlate(object $objectService, string $register, array $case): ?array {
		$permitId = $this->caseValue(case: $case, name: 'permit');
		$plate = PermitPlateChange::normalisePlate(plate: $this->caseValue(case: $case, name: 'nieuwKenteken'));
		$permit = $this->read(objectService: $objectService, register: $register, schema: self::SCHEMA, id: $permitId);
		if ($permit === null || $plate === '') {
			return null;
		}

		// Only the requester's own permit, and only while it is in force: a
		// change case can name any id, and the decision is about this case.
		if ((string)($permit['portalSubject'] ?? '') !== (string)($case['portalSubject'] ?? '')
			|| (string)($permit['status'] ?? 'active') !== 'active'
		) {
			$this->logger->warning(
				'Dossiq: a change case names a permit that is not its requester\'s or not in force',
				['case' => ($case['id'] ?? ''), 'permit' => $permitId]
			);
			return null;
		}

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				id: $permitId,
				changes: ['kenteken' => $plate]
			)
		);
	}//end changePlate()

	/**
	 * A case value by name: a top-level field, else a case-type property answer.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $name The name.
	 *
	 * @return string The value, or ''.
	 */
	private function caseValue(array $case, string $name): string {
		if ($name === '') {
			return '';
		}

		if (is_scalar($case[$name] ?? null) === true) {
			return trim((string)$case[$name]);
		}

		foreach ((array)($case['properties'] ?? []) as $answer) {
			if (is_array($answer) === true && ($answer['name'] ?? null) === $name && is_scalar($answer['value'] ?? null) === true) {
				return trim((string)$answer['value']);
			}
		}

		return '';
	}//end caseValue()

	/**
	 * The permit title: the template with case values in double braces.
	 *
	 * @param string               $template The title template.
	 * @param array<string, mixed> $case     The case.
	 *
	 * @return string The title.
	 */
	private function title(string $template, array $case): string {
		if (trim($template) === '') {
			return trim((string)($case['title'] ?? ''));
		}

		return trim(
			(string)preg_replace_callback(
				'/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
				fn (array $match): string => $this->caseValue(case: $case, name: $match[1]),
				$template
			)
		);
	}//end title()

	/**
	 * The calendar day of an instant, or today.
	 *
	 * @param string|null $instant The instant.
	 *
	 * @return string `Y-m-d`.
	 */
	private function day(?string $instant): string {
		$instant = trim((string)$instant);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $instant) === 1) {
			return substr($instant, 0, 10);
		}

		return date('Y-m-d');
	}//end day()

	/**
	 * Read one object as the system, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schema        The schema slug.
	 * @param string $id            The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(object $objectService, string $register, string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$found = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id)
		);

		if (is_array($found) === false) {
			return null;
		}

		return $found;
	}//end read()
}//end class
