<?php

/**
 * Contract of CaseNumberRecognizer
 *
 * The public surface of CaseNumberRecognizer that other components call, so they depend
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
 * Contract of CaseNumberRecognizer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseNumberRecognizerInterface {

	/**
	 * App-config key for a configured recognizer. Empty means the default.
	 *
	 * @var string
	 */
	public const PATTERN_KEY = 'email_case_matching_pattern';

	/**
	 * Why a recognizer cannot be used, or null when it can.
	 *
	 * It must compile and contain at least one capture group, because group 1
	 * is the identifier. A recognizer that silently matches nothing is the
	 * classic silent failure, so the caller refuses the run instead.
	 *
	 * The group count is taken from PCRE itself rather than by reading the
	 * pattern: the body is wrapped as `(?:body)|` so it always matches the empty
	 * string, and with PREG_UNMATCHED_AS_NULL every group is then reported.
	 *
	 * @param string $pattern A full PCRE pattern, delimiters included.
	 *
	 * @return string|null The reason it is unusable, or null.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function validatePattern(string $pattern): ?string;
}//end interface
