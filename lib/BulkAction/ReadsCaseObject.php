<?php

/**
 * Dossiq bulk-action support: read one case out of an OpenRegister object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category BulkAction
 * @package  OCA\Dossiq\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BulkAction;

use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The two reads every dossiq bulk action does on the object the job hands it.
 *
 * The job walks OpenRegister objects; dossiq's engines address a case by its
 * uuid. This is the whole of the translation between them, kept in one place
 * so four actions cannot disagree about where a case id lives.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `BulkActionResult` is a value object
 * built through its named constructors.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ReadsCaseObject {

	/**
	 * The case uuid the dossiq engines address.
	 *
	 * Falls back to the object payload's own `id`, which is what
	 * `ObjectEntity::getObject()` puts in front of the stored data.
	 *
	 * @param ObjectEntity $object The object the job is walking.
	 *
	 * @return string The case uuid, or an empty string when the object has none.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function caseId(ObjectEntity $object): string {
		$uuid = trim((string)($object->getUuid() ?? ''));
		if ($uuid !== '') {
			return $uuid;
		}

		$data = $object->getObject();

		return trim((string)($data['id'] ?? ''));
	}//end caseId()

	/**
	 * The stored case data.
	 *
	 * @param ObjectEntity $object The object the job is walking.
	 *
	 * @return array<string, mixed> The case payload.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function caseData(ObjectEntity $object): array {
		return $object->getObject();
	}//end caseData()

	/**
	 * Run the work on the step's case, or skip a step whose object has no case id.
	 *
	 * @param BulkStep                         $step The member object, the parameters, whether to commit and who started the job.
	 * @param callable(string): BulkActionResult $work The work on the case uuid.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function forCase(BulkStep $step, callable $work): BulkActionResult {
		$caseId = $this->caseId(object: $step->object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		return $work($caseId);
	}//end forCase()
}//end trait
