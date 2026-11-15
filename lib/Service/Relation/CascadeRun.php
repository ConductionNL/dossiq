<?php

/**
 * One run of an outcome cascade on a target.
 *
 * Built by `OutcomeCascade::run()` and never handed out: the cascade, the
 * target and its uuid, and the caller's input values, which every step reads,
 * and the target as it stands after the steps that ran so far.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * The cascade, its target and the inputs one cascade run works on.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
 */
final class CascadeRun {
	/**
	 * The cascade as configured.
	 *
	 * @var array<string, mixed>
	 */
	public array $cascade = [];

	/**
	 * The target uuid.
	 *
	 * @var string
	 */
	public string $targetId = '';

	/**
	 * The caller's values, asked for by name.
	 *
	 * @var array<string, mixed>
	 */
	public array $inputs = [];

	/**
	 * The target as it stands after the steps that ran so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $target = [];
}//end class
