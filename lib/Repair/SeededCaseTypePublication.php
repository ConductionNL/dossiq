<?php

/**
 * Dossiq SeededCaseTypePublication.
 *
 * One run of publishing the seeded case types: where they are stored and
 * what the run counted (published, already published, failed, unreadable).
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * The state of one publication run, built and read by
 * PublishSeededCaseTypes only.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class SeededCaseTypePublication {
	/**
	 * OpenRegister's ObjectService.
	 *
	 * @var object
	 */
	public readonly object $objectService;

	/**
	 * The register id.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The case type schema id.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * How many seeded drafts were published.
	 *
	 * @var integer
	 */
	public int $published = 0;

	/**
	 * How many were published already.
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * How many writes failed.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Whether the case types could not be read at all.
	 *
	 * @var boolean
	 */
	public bool $unreadable = false;

	/**
	 * Start a run against a register.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 */
	public function __construct(object $objectService, string $register) {
		$this->objectService = $objectService;
		$this->register = $register;
	}//end __construct()

	/**
	 * The run's counts.
	 *
	 * @return array{published: int, skipped: int, failed: int, unreadable: bool}
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function tally(): array {
		return [
			'published' => $this->published,
			'skipped' => $this->skipped,
			'failed' => $this->failed,
			'unreadable' => $this->unreadable,
		];
	}//end tally()
}//end class
