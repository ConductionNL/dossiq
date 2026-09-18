<?php

/**
 * Performing the split the plan describes.
 *
 * `CaseSplitPolicy` says what a case type allows and `CaseSplitPlan` says what
 * a selection would do; neither touches the store, which is why both are
 * testable without one and why neither could be reached from a browser. This
 * is the half that was named as missing when those two shipped: it reads the
 * case, opens the second one, performs the moves and writes the relation.
 *
 * 🔴 THE PLAN DECIDES WHAT MOVES, AND THIS CLASS DECIDES NOTHING. Every
 * judgement lives in the two services above: which parts a type allows, and
 * which rows belong to the case being split. A second opinion here would be a
 * second answer, and the first time the two disagreed the disagreement would
 * be a document on the wrong case.
 *
 * 🔴 THE ITEMS ARE READ BEFORE THEY ARE PLANNED, not taken from the request.
 * `CaseSplitPlan` refuses a row whose own `case` names a different case, and
 * it can only do that if it is given the STORED row rather than the id the
 * client sent. Handing it the client's ids would hand it nothing to check.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the case, opens the second one and performs the plan.
 *
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
 */
class CaseSplitPerformer {
	use SearchesObjects;

	/**
	 * Where each part lives, and the field on it that names the case.
	 *
	 * The three parts are `CaseSplitPolicy::PARTS`; this adds only where to
	 * find them, which is the one thing a store-facing class knows that the
	 * policy deliberately does not.
	 *
	 * @var array<string, array{slug: string, field: string, label: string}>
	 */
	public const WHERE = [
		'documents' => ['slug' => 'caseDocument', 'field' => 'case', 'label' => 'title'],
		'parties' => ['slug' => 'role', 'field' => 'case', 'label' => 'name'],
		'tasks' => ['slug' => 'caseObject', 'field' => 'case', 'label' => 'title'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register and schema ids, and the object service.
	 * @param CaseSplitPolicy     $policy          What a case type allows.
	 * @param CaseSplitPlan       $plan            What a selection would do.
	 * @param CaseRelationService $relations       The one writer of a typed case link.
	 * @param LoggerInterface     $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseSplitPolicy $policy,
		private readonly CaseSplitPlan $plan,
		private readonly CaseRelationService $relations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What this case holds, per part the case type allows.
	 *
	 * The picker asks for this rather than reading the register itself, so the
	 * handler is offered the parts the type allows and no others: a checkbox
	 * for something the server will refuse is a checkbox that wastes a split.
	 *
	 * @param string               $caseId   The case.
	 * @param array<string, mixed>|null $caseType Its type.
	 *
	 * @return array<string, array<int, array<string, string>>> Items per allowed part.
	 *
	 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
	 */
	public function divisible(string $caseId, ?array $caseType): array {
		$offered = [];
		foreach ($this->policy->allowedFor(caseType: $caseType) as $part) {
			$offered[$part] = [];
			foreach ($this->rowsOf(part: $part, caseId: $caseId) as $row) {
				$offered[$part][] = [
					'id' => (string)($row['id'] ?? ''),
					'label' => $this->labelOf(part: $part, row: $row),
				];
			}
		}

		return $offered;
	}//end divisible()

	/**
	 * Split the case, or say why not.
	 *
	 * @param array<string, mixed>      $source   The case being split.
	 * @param array<string, mixed>|null $caseType Its type, which bounds the selection.
	 * @param array<string, array<int, string>> $selection The ids the handler ticked, per part.
	 * @param string                    $title    What the new case is called.
	 *
	 * @return array{refused?: string, case?: array<string, mixed>, moved?: int, refusedRows?: array<int, array<string, mixed>>}
	 *         The new case and what moved, or the sentence that refused it.
	 *
	 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md#requirement-a-handler-can-split-a-case-from-its-own-page-req-cm-48
	 */
	public function perform(array $source, ?array $caseType, array $selection, string $title): array {
		$sourceId = (string)($source['id'] ?? '');
		if ($sourceId === '') {
			return ['refused' => 'This case no longer exists.'];
		}

		$chosenParts = [];
		foreach ($selection as $part => $ids) {
			if (is_array($ids) === true && $ids !== []) {
				$chosenParts[] = (string)$part;
			}
		}

		if ($chosenParts === []) {
			return ['refused' => 'Tick what should move to the new case.'];
		}

		// The policy's sentence, verbatim. It already names what may still be
		// divided, and rewording it here would lose that half.
		$refusal = $this->policy->whyRefused(selected: $chosenParts, caseType: $caseType);
		if ($refusal !== '') {
			return ['refused' => $refusal];
		}

		$chosen = $this->storedRowsFor(selection: $selection, caseId: $sourceId);

		$created = $this->openSecondCase(source: $source, title: $title);
		if ($created === null) {
			return ['refused' => 'The second case could not be opened, so nothing was moved.'];
		}

		$newId = (string)($created['id'] ?? '');
		$plan = $this->plan->forSelection(sourceId: $sourceId, newId: $newId, chosen: $chosen);

		$moved = 0;
		foreach ($plan['moves'] as $move) {
			if ($this->repoint(part: (string)$move['part'], id: (string)$move['id'], changes: (array)$move['changes']) === true) {
				$moved++;
			}
		}

		$this->relations->addRelation(
			caseId: (string)$plan['relation']['from'],
			targetId: (string)$plan['relation']['to'],
			natureRelationship: (string)$plan['relation']['nature'],
			notes: $this->plan->noteFor(
				references: $plan['references'],
				newNumber: (string)(($created['identifier'] ?? '') ?: $newId)
			)
		);

		$this->logger->info(
			'Dossiq: a case was split',
			['source' => $sourceId, 'new' => $newId, 'moved' => $moved, 'refused' => count($plan['refused'])]
		);

		return ['case' => $created, 'moved' => $moved, 'refusedRows' => $plan['refused']];
	}//end perform()

	/**
	 * The STORED rows behind the ids the handler ticked.
	 *
	 * Read rather than trusted: the plan refuses a row whose own `case` names
	 * a different case, and it can only do that when it is given the row.
	 *
	 * @param array<string, array<int, string>> $selection The ticked ids, per part.
	 * @param string                            $caseId    The case being split.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The rows, per part.
	 */
	private function storedRowsFor(array $selection, string $caseId): array {
		$chosen = [];
		foreach (CaseSplitPolicy::PARTS as $part) {
			$wanted = array_flip(array_map('strval', (array)($selection[$part] ?? [])));
			if ($wanted === []) {
				continue;
			}

			$chosen[$part] = [];
			foreach ($this->rowsOf(part: $part, caseId: null) as $row) {
				$id = (string)($row['id'] ?? '');
				if (isset($wanted[$id]) === true) {
					$chosen[$part][] = $row;
				}
			}
		}

		return $chosen;
	}//end storedRowsFor()

	/**
	 * The rows of one part, optionally limited to one case.
	 *
	 * A null case reads every row of that part, which is what
	 * {@see storedRowsFor()} needs: a ticked id that turns out to sit on
	 * another case must arrive at the plan so the plan can refuse it by name,
	 * and a query filtered to this case would quietly drop it instead.
	 *
	 * @param string      $part   Which part.
	 * @param string|null $caseId The case, or null for every row.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(string $part, ?string $caseId): array {
		$where = (self::WHERE[$part] ?? null);
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = $this->schemaOf(part: $part);
		if ($where === null || $objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ($caseId === null ? [] : [$where['field'] => $caseId])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a split could not read the ' . $part . ' on a case: ' . $e->getMessage());

			return [];
		}
	}//end rowsOf()

	/**
	 * Point one row at the new case.
	 *
	 * @param string               $part    Which part it is.
	 * @param string               $id      The row.
	 * @param array<string, mixed> $changes What the plan says to write.
	 *
	 * @return bool True when the write landed.
	 */
	private function repoint(string $part, string $id, array $changes): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = $this->schemaOf(part: $part);
		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			return $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $id,
				changes: $changes
			) !== null;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: a split could not move a row: ' . $e->getMessage());

			return false;
		}
	}//end repoint()

	/**
	 * Open the case the material moves to.
	 *
	 * It carries what identifies the WORK and nothing that records what has
	 * happened to the original: a second case that arrived with the first
	 * one's status history would read as having been through steps it never
	 * took.
	 *
	 * @param array<string, mixed> $source The case being split.
	 * @param string               $title  What the new case is called.
	 *
	 * @return array<string, mixed>|null The created case.
	 */
	private function openSecondCase(array $source, string $title): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$payload = [
			'title' => ($title !== '' ? $title : ('Afsplitsing van ' . (string)($source['title'] ?? ''))),
			'caseType' => ($source['caseType'] ?? ''),
			'startDate' => date('Y-m-d'),
			'description' => (string)($source['description'] ?? ''),
			'confidentiality' => ($source['confidentiality'] ?? ''),
			'competentAuthority' => ($source['competentAuthority'] ?? ''),
			'requester' => ($source['requester'] ?? ''),
		];

		foreach ($payload as $field => $value) {
			if ($value === '' || $value === null || $value === []) {
				unset($payload[$field]);
			}
		}

		try {
			return $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $payload
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: the second case of a split was not opened: ' . $e->getMessage());

			return null;
		}
	}//end openSecondCase()

	/**
	 * The configured schema id behind one part.
	 *
	 * @param string $part Which part.
	 *
	 * @return string The id, empty when unconfigured.
	 */
	private function schemaOf(string $part): string {
		$slug = (string)((self::WHERE[$part]['slug'] ?? ''));
		$configKey = (string)(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? '');

		return ($configKey === '' ? '' : (string)$this->settingsService->getConfigValue($configKey));
	}//end schemaOf()

	/**
	 * What one row is called in a picker.
	 *
	 * @param string               $part Which part.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return string The label, falling back to the id so no row is nameless.
	 */
	private function labelOf(string $part, array $row): string {
		$field = (string)((self::WHERE[$part]['label'] ?? 'title'));
		$label = trim((string)($row[$field] ?? ''));

		return ($label !== '' ? $label : (string)($row['id'] ?? ''));
	}//end labelOf()
}//end class
