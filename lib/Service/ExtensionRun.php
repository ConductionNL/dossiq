<?php

/**
 * Dossiq Extension Run
 *
 * One verlenging of a term, as DeadlineExtensionService carries it through
 * its checks, the store, the engine timer, the event and the notice.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * One verlenging: what was asked, under which mode, and what the term was
 * before it. It never leaves DeadlineExtensionService.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-pause-extension/spec.md
 */
final class ExtensionRun {
	/**
	 * Non-empty reason.
	 *
	 * @var string
	 */
	public string $rationale = '';

	/**
	 * The end date as asked (YYYY-MM-DD).
	 *
	 * @var string
	 */
	public string $requestedEndDate = '';

	/**
	 * Optional document link (verlengingsbrief).
	 *
	 * @var string
	 */
	public string $documentLink = '';

	/**
	 * The end date the term gets, rolled off a day a term may not end on.
	 *
	 * @var string
	 */
	public string $newEndDate = '';

	/**
	 * The term instance before the extension.
	 *
	 * @var array<string, mixed>
	 */
	public array $instance = [];

	/**
	 * How many days the end moves by, to the rolled end.
	 *
	 * @var int
	 */
	public int $daysImpact = 0;

	/**
	 * Constructor.
	 *
	 * @param string $termInstanceId The term instance id.
	 * @param string $mode           One of DeadlineExtensionService::MODE_STANDARD or MODE_SUPERVISOR.
	 */
	public function __construct(
		public readonly string $termInstanceId,
		public readonly string $mode,
	) {
	}//end __construct()
}//end class
