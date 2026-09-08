<?php

/**
 * The flow document a planned follow-up is written as, and read back from.
 *
 * Pure: it takes strings and dates and answers arrays, touches no store and
 * resolves no service. That is deliberate, and it is what makes the two rules
 * that matter testable without an instance — the schedule trigger carries an
 * explicit `runAs`, and the cron pins the planned date rather than a
 * recurrence.
 *
 * 🔴 FIVE CRON FIELDS CANNOT SAY "ONCE". Minute, hour, day and month name one
 * minute of one day of one month, and that minute comes round again next year.
 * The promise is kept by {@see \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob},
 * which switches the flow off once it has fired. Nothing here pretends
 * otherwise.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use RuntimeException;

/**
 * Builds and reads the flow behind one planned follow-up case.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class PlannedFollowUpDocument {

	/**
	 * The `applicationSlug` every planned follow-up flow carries.
	 *
	 * It is the marker that separates the flows this app WRITES from the flows
	 * it SHIPS (which carry none), and `FlowMapper::findAllFlows()` filters on
	 * it server-side. Recognising a planned flow by its NAME instead would
	 * break the moment somebody renamed one in the flow editor.
	 *
	 * @var string
	 */
	public const PLANNED_SLUG = 'dossiq-planned-follow-up';

	/**
	 * The hour a planned follow-up is created on its date.
	 *
	 * @var integer
	 */
	private const HOUR = 6;

	/**
	 * The flow document for one planned follow-up.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param DateTimeImmutable $due The date it is due.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function build(
		string $caseId,
		string $caseTypeId,
		DateTimeImmutable $due,
		string $title,
		string $uid,
	): array {
		$cron = $this->cronFor(due: $due);

		return [
			'name' => 'Planned follow-up: ' . $title,
			'description' => sprintf(
				'Creates a case of type %s on %s, related to case %s. Planned by %s.',
				$caseTypeId,
				$due->format('Y-m-d'),
				$caseId,
				$uid
			),
			'app' => Application::APP_ID,
			'applicationSlug' => self::PLANNED_SLUG,
			'trigger' => 'schedule',
			'cron' => $cron,
			'executionMode' => 'async',
			'enabled' => false,
			'nodes' => [
				[
					'id' => 'when',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => $cron, 'runAs' => $uid],
				],
				[
					'id' => 'create',
					'type' => 'dossiq.createSubCase',
					'config' => [
						'caseType' => $caseTypeId,
						'title' => $title,
						'relatedCases' => [$caseId],
					],
				],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e-create', 'from' => 'when', 'to' => 'create'],
				['id' => 'e-done', 'from' => 'create', 'to' => 'done'],
			],
			'limits' => ['maxTransitions' => 10],
		];
	}//end build()

	/**
	 * Validate and parse the requested date.
	 *
	 * Parsed WITHOUT `DateTimeImmutable::createFromFormat()`: a static call is
	 * a phpmd `StaticAccess` finding, and the shape check it would do is done
	 * here in full anyway.
	 *
	 * @param string $date The date as `Y-m-d`.
	 *
	 * @return DateTimeImmutable The parsed date.
	 *
	 * @throws RuntimeException `invalid_date` when it is absent or unparseable.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function dueDate(string $date): DateTimeImmutable {
		$trimmed = trim($date);
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $parts) !== 1) {
			throw new RuntimeException('invalid_date');
		}

		if (checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			throw new RuntimeException('invalid_date');
		}

		return new DateTimeImmutable($trimmed);
	}//end dueDate()

	/**
	 * What one planned flow says it will create, read off its own graph.
	 *
	 * The graph is the record: a name can be edited, the node config is what
	 * actually runs. The date comes from the schedule node's cron rather than
	 * the description for the same reason.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return array{case: string, caseType: string, title: string, date: string}|null The marker, or null when the graph is not one of ours.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function markerOf(array $nodes): ?array {
		['create' => $create, 'cron' => $cron] = $this->readNodes(nodes: $nodes);
		if ($create === null) {
			return null;
		}

		$related = (array)($create['relatedCases'] ?? []);
		$caseId = trim((string)($related[0] ?? ''));
		if ($caseId === '') {
			return null;
		}

		return [
			'case' => $caseId,
			'caseType' => (string)($create['caseType'] ?? ''),
			'title' => (string)($create['title'] ?? ''),
			'date' => $this->dateOfCron(cron: $cron),
		];
	}//end markerOf()

	/**
	 * The create-node config and the schedule cron, from one node list.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return array{create: array<string, mixed>|null, cron: string} What was found.
	 */
	private function readNodes(array $nodes): array {
		$create = null;
		$cron = '';
		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$type = (string)($node['type'] ?? '');
			$config = (array)($node['config'] ?? []);
			if ($type === 'dossiq.createSubCase') {
				$create = $config;
			}

			if ($type === 'openregister.trigger-schedule') {
				$cron = (string)($config['cron'] ?? '');
			}
		}

		return ['create' => $create, 'cron' => $cron];
	}//end readNodes()

	/**
	 * The cron expression for one date.
	 *
	 * Minute, hour, day and month are all pinned; only the weekday field stays
	 * open, because pinning it too would AND two calendar constraints that
	 * disagree in most years and the flow would fire on neither.
	 *
	 * @param DateTimeImmutable $due The date.
	 *
	 * @return string The five-field expression.
	 */
	private function cronFor(DateTimeImmutable $due): string {
		return sprintf('0 %d %d %d *', self::HOUR, (int)$due->format('j'), (int)$due->format('n'));
	}//end cronFor()

	/**
	 * The next calendar date a pinned cron expression names.
	 *
	 * @param string $cron The five-field expression.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 */
	private function dateOfCron(string $cron): string {
		$fields = preg_split('/\s+/', trim($cron));
		if (is_array($fields) === false || count($fields) !== 5) {
			return '';
		}

		$day = (int)$fields[2];
		$month = (int)$fields[3];
		if ($day < 1 || $month < 1) {
			return '';
		}

		$year = (int)date('Y');
		$candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
		if ($candidate >= date('Y-m-d')) {
			return $candidate;
		}

		return sprintf('%04d-%02d-%02d', ($year + 1), $month, $day);
	}//end dateOfCron()
}//end class
