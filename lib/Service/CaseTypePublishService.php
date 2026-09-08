<?php

/**
 * Dossiq Case Type Publish Service.
 *
 * Publishing is the one thing on the case type page that needs an ORDER, which
 * is why it is code and everything else in this change is configuration
 * (ADR-031, design D5): validate FIRST, and only then clear the draft flag,
 * mark the active workflow template published and write the change note. A
 * declarative action cannot express "refuse if, otherwise these three writes".
 *
 * The validation is its own, not `ZgwZtcRulesService::validatePublish()`, for
 * one reason that matters: that method asks the store for `statusType where
 * caseType = X`, which is a CHILD type's own rows. A child that derives its
 * lifecycle from a parent has none, so it would be refused with "at least one
 * status type must be defined" while showing four statuses on its own page.
 * This reads through `CaseTypeResolver`, so what is validated is what the page
 * shows. The ZGW layer keeps its own answer on purpose: it mirrors the
 * catalogue as DECLARED, which is what a ZGW client asked for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates a draft case type and publishes it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class CaseTypePublishService {
	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService  Bridge to OpenRegister + config.
	 * @param CaseTypeResolver $caseTypeResolver The effective blueprint.
	 * @param CaseTypeStore    $store            Reads for the resolver's schemas.
	 * @param LoggerInterface  $logger           The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly CaseTypeStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What stands between this draft and being published.
	 *
	 * Returns the findings as sentences a person can act on, never as codes:
	 * the list is rendered straight into the Publish dialog, and "no findings"
	 * is the empty array.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The findings, empty when the draft is publishable.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function validate(string $caseTypeId): array {
		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return ['This case type could not be read.'];
		}

		$findings = [];
		$statuses = $this->caseTypeResolver->statusTypesFor(caseTypeId: $caseTypeId);

		if ($statuses === []) {
			$findings[] = 'Give the case type at least one status.';
		}

		if ($statuses !== [] && $this->hasFinalStatus(statuses: $statuses) === false) {
			$findings[] = 'Mark one of the statuses as the final one, so a case can close.';
		}

		if ($statuses !== [] && $this->initialStatusIsOwn(caseType: $caseType, statuses: $statuses) === false) {
			$findings[] = 'Pick the status a new case of this type starts in.';
		}

		if (trim((string)($caseType['title'] ?? '')) === '') {
			$findings[] = 'Give the case type a title.';
		}

		$cycle = $this->cycleFinding(caseTypeId: $caseTypeId, caseType: $caseType);
		if ($cycle !== '') {
			$findings[] = $cycle;
		}

		return $findings;
	}//end validate()

	/**
	 * The finding a looping parent chain produces, if it loops.
	 *
	 * 🔴 THIS IS THE ONLY PLACE DOSSIQ CAN REFUSE A CYCLE. The spec words the
	 * refusal as "on save", and dossiq does not own the save: a case type is
	 * written straight to OpenRegister's object API by the page, and no dossiq
	 * code runs in between. Publishing is the one write dossiq does own, so it
	 * is where a type whose chain returns to itself is stopped. `chainFor()`
	 * degrades safely on a chain already stored that way — it stops rather
	 * than looping — so a mis-saved type stays readable while it is unpublished.
	 *
	 * @param string               $caseTypeId The type being published.
	 * @param array<string, mixed> $caseType   Its effective row.
	 *
	 * @return string The finding, or '' when the chain is sound.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function cycleFinding(string $caseTypeId, array $caseType): string {
		$parent = $this->store->referenceId(value: ($caseType['parentCaseType'] ?? ''));
		if ($parent === '') {
			return '';
		}

		try {
			$this->caseTypeResolver->assertNoCycle(caseTypeId: $caseTypeId, parentCaseTypeId: $parent);
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end cycleFinding()

	/**
	 * Publish a draft case type.
	 *
	 * Validation runs first and a non-empty finding list stops everything: a
	 * partial publish — draft flag cleared, workflow template still a draft —
	 * is worse than no publish, because nothing afterwards says which half ran.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $changeNote  What changed in this version.
	 *
	 * @return array<string, mixed> `{published: bool, findings: string[], version: ?int}`.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function publish(string $caseTypeId, string $changeNote): array {
		$findings = $this->validate(caseTypeId: $caseTypeId);
		if ($findings !== []) {
			return ['published' => false, 'findings' => $findings, 'version' => null];
		}

		$caseType = $this->store->readCaseType(caseTypeId: $caseTypeId);
		$caseType['isDraft'] = false;

		if ($this->save(schemaKey: 'case_type_schema', object: $caseType) === false) {
			return [
				'published' => false,
				'findings' => ['The case type could not be saved.'],
				'version' => null,
			];
		}

		$version = $this->publishActiveTemplate(caseTypeId: $caseTypeId, changeNote: $changeNote);

		return ['published' => true, 'findings' => [], 'version' => $version];
	}//end publish()

	/**
	 * Mark the case type's active workflow template published, with the note.
	 *
	 * A case type with no workflow template publishes anyway and answers a null
	 * version. Refusing would block every case type that drives its lifecycle
	 * from transitions alone, and those are the majority.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $changeNote What changed in this version.
	 *
	 * @return integer|null The published template's version, or null when there is none.
	 */
	private function publishActiveTemplate(string $caseTypeId, string $changeNote): ?int {
		$template = $this->activeTemplate(caseTypeId: $caseTypeId);
		if ($template === []) {
			return null;
		}

		$template['isDraft'] = false;
		$template['lifecycleStatus'] = 'published';
		if (trim($changeNote) !== '') {
			$template['description'] = $changeNote;
		}

		if ($this->save(schemaKey: 'workflow_template_schema', object: $template) === false) {
			return null;
		}

		return (int)($template['version'] ?? 1);
	}//end publishActiveTemplate()

	/**
	 * The case type's active workflow template.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The template, or an empty array when there is none.
	 */
	private function activeTemplate(string $caseTypeId): array {
		$rows = $this->store->rowsOfType(schemaKey: 'workflow_template_schema', caseTypeId: $caseTypeId);

		$fallback = [];
		foreach ($rows as $row) {
			if (($row['isActive'] ?? false) === true) {
				return $row;
			}

			if ($fallback === []) {
				$fallback = $row;
			}
		}

		return $fallback;
	}//end activeTemplate()

	/**
	 * Whether one of the statuses closes a case.
	 *
	 * @param array<int, array<string, mixed>> $statuses The resolved statuses.
	 *
	 * @return boolean True when at least one is final.
	 */
	private function hasFinalStatus(array $statuses): bool {
		foreach ($statuses as $status) {
			if (in_array(($status['isFinal'] ?? false), [true, 1, '1', 'true'], true) === true) {
				return true;
			}
		}

		return false;
	}//end hasFinalStatus()

	/**
	 * Whether the type's initial status is one of the statuses it resolves to.
	 *
	 * An initial status pointing at a status the type does not have is worse
	 * than none: a new case is filed into a status its own lifecycle cannot
	 * move it out of.
	 *
	 * @param array<string, mixed>             $caseType The effective case type.
	 * @param array<int, array<string, mixed>> $statuses The resolved statuses.
	 *
	 * @return boolean True when the initial status resolves.
	 */
	private function initialStatusIsOwn(array $caseType, array $statuses): bool {
		$initial = $this->store->referenceId(value: ($caseType['initialStatus'] ?? ''));
		if ($initial === '') {
			return false;
		}

		foreach ($statuses as $status) {
			if ($this->store->rowId(row: $status) === $initial) {
				return true;
			}
		}

		return false;
	}//end initialStatusIsOwn()

	/**
	 * Write one object back to its configured schema.
	 *
	 * @param string               $schemaKey The settings key naming the schema.
	 * @param array<string, mixed> $object    The object to save.
	 *
	 * @return boolean True when it was written.
	 */
	private function save(string $schemaKey, array $object): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			$objectService->saveObject(object: $object, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Case type publish: save failed',
				['exception' => $e->getMessage(), 'schema' => $schemaKey]
			);
			return false;
		}

		return true;
	}//end save()
}//end class
