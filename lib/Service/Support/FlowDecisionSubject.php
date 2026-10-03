<?php

/**
 * The dossiq case a decision raised by Decidiq's flow step is about, if any.
 *
 * Decidiq's `decidiq.request-decision` step raises decisions for any app's
 * objects, under source app `decidiq-flow`, and names the object as the
 * decision's subject. Such a decision is dossiq's to project only when that
 * subject is a dossiq case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Reads a concluded decision's subject as a dossiq case.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class FlowDecisionSubject {

	/**
	 * The source Decidiq's flow step raises its decisions under.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'decidiq-flow';

	/**
	 * Constructor.
	 *
	 * @param CaseObjectReference $cases Recognises a dossiq case.
	 */
	public function __construct(
		private readonly CaseObjectReference $cases,
	) {
	}//end __construct()

	/**
	 * The case id when the event is a flow decision about a dossiq case, else ''.
	 *
	 * The event is Decidiq's and optional at runtime, so it is read by its
	 * getters rather than by type.
	 *
	 * @param object $event The concluded-decision event.
	 *
	 * @return string The case id, or ''.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function caseIdOf(object $event): string {
		$caseId = trim($this->read(event: $event, getter: 'getSubjectId'));
		if ($this->read(event: $event, getter: 'getSourceApp') !== self::SOURCE_APP || $caseId === '') {
			return '';
		}

		$register = $this->read(event: $event, getter: 'getSubjectRegister');
		$schema = $this->read(event: $event, getter: 'getSubjectSchema');
		if ($this->cases->isCase(register: $register, schema: $schema) === false) {
			return '';
		}

		return $caseId;
	}//end caseIdOf()

	/**
	 * A string off the event, or '' when it has no such getter or no scalar answer.
	 *
	 * @param object $event  The event.
	 * @param string $getter The getter.
	 *
	 * @return string The value.
	 */
	private function read(object $event, string $getter): string {
		if (method_exists($event, $getter) === false) {
			return '';
		}

		$value = $event->$getter();
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end read()
}//end class
