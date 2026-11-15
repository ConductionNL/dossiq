<?php

/**
 * Dossiq repair step: rewrite the old Woo decision summary on stored rows.
 *
 * Before #3259 a Woo decision's `description` read "WOO besluit voor zaak
 * <case uuid>", and the OpenCatalogi publication copied it into its `summary`
 * (and into its `description` when the decision had no explanation). The
 * public site shows the summary under the publication's title, so a resident
 * read a code. New decisions name the request by the case's title
 * ({@see WOODecisionService::publicationSummary()}); this step gives the rows
 * written before that fix the same words.
 *
 * Two kinds of row, one rule:
 * - the publication's `summary`, plus its `description` when that holds the
 *   same old text;
 * - the decision's `description`, because a republish copies it into the
 *   publication again and would bring the code back.
 *
 * Only a value that is EXACTLY the old text is touched, so a summary somebody
 * rewrote by hand is left alone, and a second run finds nothing to do.
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODecisionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites "WOO besluit voor zaak <uuid>" to the summary new decisions carry.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class RewriteWooPublicationSummaries implements IRepairStep {

	use SearchesObjects;

	/**
	 * The old text, with the case uuid captured.
	 */
	public const OLD_SUMMARY = '/^WOO besluit voor zaak ([0-9a-f-]{36})$/i';

	/**
	 * Rows read per page.
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on pages, so a store that ignores `_offset` cannot loop forever.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Case titles already read, by case uuid.
	 *
	 * @var array<string, string>
	 */
	private array $titles = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function getName(): string {
		return 'Rewrite the old "WOO besluit voor zaak <uuid>" summary on Woo decisions and publications';
	}//end getName()

	/**
	 * Run the rewrite. Never throws: one row that cannot be written is
	 * counted and logged, and the upgrade goes on.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping the Woo summary rewrite.');
			return;
		}

		foreach ($this->summaryTargets() as $name => $target) {
			$this->rewriteTarget(summaryTarget: new SummaryTarget(objectService: $objectService, target: $target), name: $name, output: $output);
		}
	}//end run()

	/**
	 * The publications and decisions to rewrite, with their fields.
	 *
	 * @return array<string, array<string, mixed>> The targets by name.
	 */
	private function summaryTargets(): array {
		return [
			'publications' => [
				'register' => $this->settingsService->getWooPublicationConfigValue('woo_publication_register'),
				'schema'   => $this->settingsService->getWooPublicationConfigValue('woo_publication_schema'),
				'fields'   => ['summary', 'description'],
			],
			'decisions'    => [
				'register' => (string)$this->settingsService->getConfigValue('register'),
				'schema'   => (string)$this->settingsService->getConfigValue('decision_schema'),
				'fields'   => ['description'],
			],
		];
	}//end summaryTargets()

	/**
	 * Rewrite the summaries of one target and report the tally.
	 *
	 * @param SummaryTarget $summaryTarget OpenRegister's object service with the register, schema and fields.
	 * @param string        $name          The target's name.
	 * @param IOutput       $output        Progress reporting.
	 *
	 * @return void
	 */
	private function rewriteTarget(SummaryTarget $summaryTarget, string $name, IOutput $output): void {
		$target = $summaryTarget->target;
		if ($target['register'] === '' || $target['schema'] === '') {
			$output->info('Woo summary rewrite: ' . $name . ' not configured, skipped.');
			return;
		}

		try {
			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $summaryTarget->objectService,
				operation: fn (): array => $this->rewriteAll(summaryTarget: $summaryTarget)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the Woo summary rewrite could not read the ' . $name,
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()]
			);
			$output->info('Woo summary rewrite: ' . $name . ' could not be read, skipped.');
			return;
		}

		$output->info(
			'Woo summary rewrite, ' . $name . ': ' . (int)($tally['rewritten'] ?? 0) . ' rewritten, '
			. (int)($tally['failed'] ?? 0) . ' failed.'
		);
	}//end rewriteTarget()

	/**
	 * The new values for one row, or [] when it carries no old summary.
	 *
	 * The first field is the one that names the case. A later field is
	 * rewritten only when it holds exactly the same old text.
	 *
	 * @param array<string, mixed> $row    The stored row.
	 * @param array<int, string>   $fields The fields to look at, the leading one first.
	 *
	 * @return array<string, string> The changes.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WOODecisionService::publicationSummary()
	 * is the one place the wording lives, so a repaired row and a new one cannot
	 * come to read differently.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function changesFor(array $row, array $fields): array {
		$lead = ($row[$fields[0]] ?? null);
		if (is_string($lead) === false || preg_match(self::OLD_SUMMARY, trim($lead), $match) !== 1) {
			return [];
		}

		$summary = WOODecisionService::publicationSummary(caseTitle: $this->titleOf(caseId: strtolower($match[1])));

		$changes = [];
		foreach ($fields as $field) {
			if (($row[$field] ?? null) === $lead) {
				$changes[$field] = $summary;
			}
		}

		return $changes;
	}//end changesFor()

	/**
	 * Walk every row of one schema and rewrite the ones that match.
	 *
	 * @param SummaryTarget $summaryTarget OpenRegister's ObjectService with what to walk.
	 *
	 * @return array{rewritten: int, failed: int} The tally.
	 */
	private function rewriteAll(SummaryTarget $summaryTarget): array {
		$objectService = $summaryTarget->objectService;
		$target = $summaryTarget->target;
		$tally = ['rewritten' => 0, 'failed' => 0];

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $target['register'],
				schema: $target['schema'],
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$outcome = $this->rewriteRow(summaryTarget: $summaryTarget, row: $row);
				if ($outcome !== null) {
					$tally[$outcome] = ($tally[$outcome] + 1);
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $tally;
	}//end rewriteAll()

	/**
	 * Rewrite one row when it matches.
	 *
	 * @param SummaryTarget $summaryTarget OpenRegister's ObjectService with where the row lives.
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return string|null `rewritten`, `failed`, or null when there was nothing to do.
	 */
	private function rewriteRow(SummaryTarget $summaryTarget, array $row): ?string {
		$target = $summaryTarget->target;
		$changes = $this->changesFor(row: $row, fields: $target['fields']);
		$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		if ($changes === [] || $uuid === '') {
			return null;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $summaryTarget->objectService,
				register: $target['register'],
				schema: $target['schema'],
				id: $uuid,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the Woo summary rewrite failed for one row',
				['app' => AppIdentity::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'rewritten';
	}//end rewriteRow()

	/**
	 * The case's title, or '' when the case or its title cannot be read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The title.
	 */
	private function titleOf(string $caseId): string {
		if (isset($this->titles[$caseId]) === true) {
			return $this->titles[$caseId];
		}

		$title = $this->readTitle(caseId: $caseId);

		$this->titles[$caseId] = $title;

		return $title;
	}//end titleOf()

	/**
	 * The case's title as stored, or '' when OpenRegister is not configured or the title cannot be read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The title.
	 */
	private function readTitle(string $caseId): string {
		$title = '';
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService !== null && $register !== '' && $caseSchema !== '') {
			$title = $this->caseTitle(objectService: $objectService, register: $register, caseSchema: $caseSchema, caseId: $caseId);
		}

		return $title;
	}//end readTitle()

	/**
	 * The title of one case, or '' (logged) when it cannot be read.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $caseSchema    The case schema.
	 * @param string $caseId        The case uuid.
	 *
	 * @return string The title.
	 */
	private function caseTitle(object $objectService, string $register, string $caseSchema, string $caseId): string {
		$title = '';
		try {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
			$value = ($case['title'] ?? null);
			if (is_string($value) === true) {
				$title = trim($value);
			}
		} catch (Throwable $e) {
			$context = ['app' => AppIdentity::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()];
			$this->logger->info('Dossiq: the Woo summary rewrite could not read a case title', $context);
		}

		return $title;
	}//end caseTitle()
}//end class
