<?php

/**
 * Dossiq case merge rule.
 *
 * The `x-openregister-merge` rule the `case` schema declares: the reversal
 * window, the state field, and which kinds of row relink. It is read off the
 * shipped register rather than written here, so the rule the import wrote and
 * the rule this app acts on cannot drift apart in one direction only.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseMergeService}, which was over its
 * complexity ceiling. Reading the declaration is not deciding a merge, and an
 * unreadable register is still a warning and an empty rule, not a failure.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the `case` schema declares about merging.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergeRule {

	/**
	 * The shipped register, which is where the merge rule is declared.
	 */
	private const REGISTER_JSON = __DIR__ . '/../../Settings/dossiq_register.json';

	/**
	 * The merge rule, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $rule = null;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `x-openregister-merge` rule the `case` schema declares.
	 *
	 * @return array<string, mixed> The rule, empty when the register cannot be read.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function rule(): array {
		if ($this->rule !== null) {
			return $this->rule;
		}

		$this->rule = [];

		try {
			$raw = file_get_contents(self::REGISTER_JSON);
			$decoded = json_decode((string)$raw, true);
			$case = ($decoded['components']['schemas']['case'] ?? []);
			$declared = ($case['configuration']['x-openregister-merge'] ?? []);
			if (is_array($declared) === true) {
				$this->rule = $declared;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the case merge rule could not be read: ' . $e->getMessage());
		}

		return $this->rule;
	}//end rule()

	/**
	 * The declared relink pairs.
	 *
	 * @return array<int, array<string, mixed>> Each with a `schema` slug and a `field`.
	 */
	public function relinkDeclarations(): array {
		$declared = ($this->rule()['x-dossiq-relink'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn (mixed $row): bool => is_array($row) === true));
	}//end relinkDeclarations()

}//end class
