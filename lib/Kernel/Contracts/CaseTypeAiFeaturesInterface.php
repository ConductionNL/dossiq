<?php

/**
 * Contract of CaseTypeAiFeatures
 *
 * The public surface of CaseTypeAiFeatures that other components call, so they depend
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
 * Contract of CaseTypeAiFeatures.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeAiFeaturesInterface {

	/**
	 * The case detail page.
	 *
	 * @var string
	 */
	public const SURFACE_CASE = 'case';

	/**
	 * The features a case type declares for one surface.
	 *
	 * @param array<string, mixed> $caseType The case type record.
	 * @param string               $surface  One of SURFACES.
	 *
	 * @return array<int, string> The feature slugs, in declaration order.
	 *
	 * @psalm-return list<string>
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
	 */
	public function onSurface(array $caseType, string $surface): array;
}//end interface
