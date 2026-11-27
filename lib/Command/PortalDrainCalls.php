<?php

/**
 * Dossiq portal drain calls.
 *
 * The three things the intake drain does with the store, as the callbacks
 * the generic drain takes: name the case already holding a source, submit
 * one case through OpenRegister's form submit, and mark a source with the
 * case it became. Split out of
 * {@see \OCA\Dossiq\Command\DrainIntakeObjectsCommand}, which keeps the
 * prerequisites, the run and the report.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use Throwable;

/**
 * The drain's store calls, over one drain setup.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PortalDrainCalls {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param PortalDrainSetup $setup          The object service, form submit, admin, register and schemas.
	 * @param string           $deliveredField The source property the case id is written to.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly PortalDrainSetup $setup,
		private readonly string $deliveredField,
	) {
	}//end __construct()

	/**
	 * The callback that names the case already holding a source.
	 *
	 * @return callable(array): string
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function existingCase(): callable {
		return fn (array $source): string => $this->caseHolding(source: $source);
	}//end existingCase()

	/**
	 * The callback that submits one case.
	 *
	 * @return callable(array): array
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function submitter(): callable {
		return fn (array $case): array => $this->submit(case: $case);
	}//end submitter()

	/**
	 * The callback that marks a source with the case it became.
	 *
	 * @return callable(array, string): void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function sourceMarker(): callable {
		return function (array $source, string $caseId): void {
			$this->markSource(source: $source, caseId: $caseId);
		};
	}//end sourceMarker()

	/**
	 * Submit one case through OpenRegister's form submit.
	 *
	 * @param array<string, mixed> $case  The case.
	 *
	 * @return array<string, mixed> `id`, or `findings`.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function submit(array $case): array {
		try {
			$answer = $this->setup->submitter->submit(
				new FormSubmission(
					writes: [['register' => $this->setup->register, 'schema' => $this->setup->caseSchema, 'mapping' => null]],
					payload: $case,
					submitter: new FormSubmitter(subject: $this->setup->admin)
				)
			);
		} catch (Throwable $e) {
			$findings = [['property' => '', 'code' => 'refused', 'message' => $e->getMessage()]];
			if (method_exists($e, 'getFindings') === true && $e->getFindings() !== []) {
				$findings = $e->getFindings();
			}

			return ['findings' => $findings];
		}

		return ['id' => (string)($answer['id'] ?? '')];
	}//end submit()

	/**
	 * The id of a case that already carries this source's id as its earlier reference.
	 *
	 * @param array<string, mixed> $source The source object.
	 *
	 * @return string The case id, or the empty string.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function caseHolding(array $source): string {
		$sourceId = trim((string)($source['id'] ?? ''));
		if ($sourceId === '') {
			return '';
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $this->setup->objectService,
			register: $this->setup->register,
			schema: $this->setup->caseSchema,
			filters: ['externalReference' => $sourceId, '_limit' => 1]
		);

		return trim((string)(($rows[0] ?? [])['id'] ?? ''));
	}//end caseHolding()

	/**
	 * Record the case id on the source, so the next run skips it.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param string               $caseId The case id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function markSource(array $source, string $caseId): void {
		$sourceId = (string)($source['id'] ?? '');
		$payload = array_merge($source, [$this->deliveredField => $caseId]);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		$this->setup->objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->setup->register, schema: $this->setup->sourceSchema), uuid: $sourceId)
		);
	}//end markSource()
}//end class
