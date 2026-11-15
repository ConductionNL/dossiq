<?php

/**
 * Dossiq MajorCaseRun.
 *
 * One declaration of a case as major while it is made: the case, who
 * declared it, and the responders pulled in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

/**
 * The state of one major-case declaration, built and read by
 * MajorCaseDeclaration only.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class MajorCaseRun {
	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * Who declared it, '' when nobody is named.
	 *
	 * @var string
	 */
	public readonly string $declaredBy;

	/**
	 * The case as read.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * The responders pulled into the channel.
	 *
	 * @var array<int, string>
	 */
	public array $responders = [];

	/**
	 * Start a declaration.
	 *
	 * @param string $caseId     The case UUID.
	 * @param string $declaredBy Who declared it, '' when nobody is named.
	 */
	public function __construct(string $caseId, string $declaredBy) {
		$this->caseId = $caseId;
		$this->declaredBy = $declaredBy;
	}//end __construct()
}//end class
