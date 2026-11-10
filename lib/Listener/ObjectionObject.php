<?php

/**
 * Dossiq objection object.
 *
 * Where one bezwaar is read or written for the published-decision guard:
 * the object service, the register, the schema and the objection id.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * One objection, and the schema the guard reads or writes for it.
 *
 * Built by {@see BezwaarDecisionListener} only; it never leaves it.
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
final class ObjectionObject {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The schema read or written: the decision schema or the objection schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The objection's id.
	 *
	 * @var string
	 */
	public string $objectionId = '';

	/**
	 * Whether the register, the schema and the objection are all known.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function isComplete(): bool {
		return $this->register !== '' && $this->schema !== '' && $this->objectionId !== '';
	}//end isComplete()
}//end class
