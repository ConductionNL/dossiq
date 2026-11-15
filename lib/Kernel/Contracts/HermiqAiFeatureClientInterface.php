<?php

/**
 * Contract of HermiqAiFeatureClient
 *
 * The public surface of HermiqAiFeatureClient that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of HermiqAiFeatureClient.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HermiqAiFeatureClientInterface {

	/**
	 * The prompts hermiq holds for one record type, in the administrator's order.
	 *
	 * Dossiq holds no prompt text of its own for anything the assistant offers on
	 * a case. A locally cached copy would render without a round trip and be a
	 * prompt an administrator cannot edit.
	 *
	 * @param string $scope The record type the surface is open on.
	 *
	 * @return array<int, array<string, mixed>> The prompts.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-prompts-offered-on-a-case-come-from-hermiq
	 */
	public function prompts(string $scope): array;

	/**
	 * Which provider each feature will use and where it runs, keyed by feature
	 * slug.
	 *
	 * An absent hermiq returns an empty map rather than throwing, because the
	 * caller's job is to report each declared feature as unavailable. What it must
	 * never do is report it as local: "we could not ask" and "it runs here" are
	 * different answers and only one of them is safe to act on.
	 *
	 * @return array<string, array<string, mixed>> The rows, by feature slug.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-case-type-screen-shows-where-each-feature-runs
	 */
	public function featureResidency(): array;
}//end interface
