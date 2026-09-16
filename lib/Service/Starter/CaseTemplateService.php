<?php

/**
 * A case that is a starting point rather than work.
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use Psr\Log\LoggerInterface;

/**
 * Saves a case as a template, and starts a case from one.
 *
 * 🔑 A TEMPLATE IS A FLAG ON THE CASE, NOT A SECOND STORE. Taiga posts a
 * project from project-templates and Huly keeps issue templates beside issues;
 * both keep the template in the same store as the thing. So does this: one
 * editor, one permission model, one export. Twelve standaardzaken that differ
 * in four fields are twelve rows with `isTemplate` set.
 *
 * 🔑 THE PRICE OF THAT IS EXCLUSION, AND IT IS PAID EXPLICITLY. A row in the
 * case store is in every count and every list unless something takes it out.
 * Three places take it out: `EXCLUSION` below is the filter every working list
 * and count adds, {@see bindsTerm()} is what the term services ask before they
 * bind a deadline, and the manifest's case lenses carry the same filter. A
 * template that slipped into an open-cases count would read as work nobody is
 * doing, which is the same complaint hidden statuses were introduced to fix.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
class CaseTemplateService {

	/**
	 * The app config key naming the case schema.
	 */
	public const CASES = 'case_schema';

	/**
	 * The property that marks a case row as a template.
	 */
	public const PROPERTY = 'isTemplate';

	/**
	 * The filter every working list, count and term report adds.
	 *
	 * @var array<string, mixed>
	 */
	public const EXCLUSION = [self::PROPERTY => false];

	/**
	 * Constructor.
	 *
	 * @param StarterStore    $store  The OpenRegister seam.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a case row is a template.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return boolean True when the row is a template.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function isTemplate(array $case): bool {
		return (($case[self::PROPERTY] ?? false) === true);
	}//end isTemplate()

	/**
	 * Whether a term may be bound to this case.
	 *
	 * The term services ask this before they create a deadline instance. A
	 * template with a statutory term would otherwise start counting the day it
	 * was saved and turn up overdue on somebody's report forever.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return boolean True when a deadline belongs on this case.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function bindsTerm(array $case): bool {
		return ($this->isTemplate(case: $case) === false);
	}//end bindsTerm()

	/**
	 * The templates a handler may start from.
	 *
	 * @param string $caseTypeId Only templates of this case type, or '' for all.
	 *
	 * @return array<int, array<string, mixed>>|null The templates, or null when
	 *                                               the store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function templates(string $caseTypeId = ''): ?array {
		$filters = [self::PROPERTY => true];
		if ($caseTypeId !== '') {
			$filters['caseType'] = $caseTypeId;
		}

		$rows = $this->store->rows(configKey: self::CASES, filters: $filters);
		if ($rows === null) {
			return null;
		}

		return array_map(
			fn (array $row): array => [
				'id' => $this->store->idOf(row: $row),
				'templateName' => (string)($row['templateName'] ?? ($row['title'] ?? '')),
				'caseType' => (string)($row['caseType'] ?? ''),
			],
			$rows
		);
	}//end templates()

	/**
	 * Start a case from a template.
	 *
	 * @param string               $templateId The template's id.
	 * @param array<string, mixed> $overrides  Fields the handler set themselves.
	 *
	 * @return array{ok: bool, reason: string, case: array<string, mixed>} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function startFrom(string $templateId, array $overrides = []): array {
		$template = $this->store->row(configKey: self::CASES, id: $templateId);
		if ($template === null) {
			return ['ok' => false, 'reason' => 'not_found', 'case' => []];
		}

		if ($this->isTemplate(case: $template) === false) {
			// Starting from an ordinary case is a copy, which
			// `CaseCopyService` already does. Letting this path do it silently
			// would give two answers to one gesture.
			return ['ok' => false, 'reason' => 'not_a_template', 'case' => []];
		}

		$payload = $this->preset(template: $template);
		foreach ($overrides as $key => $value) {
			$payload[$key] = $value;
		}

		$payload[self::PROPERTY] = false;
		$payload['startedFromTemplate'] = $templateId;

		$created = $this->store->save(configKey: self::CASES, payload: $payload);
		if ($created === null) {
			return ['ok' => false, 'reason' => 'write_failed', 'case' => []];
		}

		$this->logger->info(
			'Dossiq starter: a case was started from a template',
			['template' => $templateId, 'case' => $this->store->idOf(row: $created)]
		);

		return ['ok' => true, 'reason' => '', 'case' => $created];
	}//end startFrom()

	/**
	 * The fields a template presets, with the template's own identity dropped.
	 *
	 * `identifier` goes because a zaaknummer belongs to one case and two cases
	 * carrying the same one is an archive problem, not a cosmetic one. The
	 * dates go because a case starts when it is started, not when its template
	 * was written. `templateName` goes because the new row is not a template.
	 *
	 * @param array<string, mixed> $template The template row.
	 *
	 * @return array<string, mixed> The preset fields.
	 */
	private function preset(array $template): array {
		unset(
			$template['id'],
			$template['@self'],
			$template['identifier'],
			$template['templateName'],
			$template['startDate'],
			$template['endDate'],
			$template['deadline'],
			$template['plannedEndDate'],
			$template['statusHistory'],
			$template['startedFromTemplate'],
		);

		return $template;
	}//end preset()
}//end class
