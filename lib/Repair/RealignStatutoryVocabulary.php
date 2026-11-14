<?php

/**
 * Dossiq Realign Statutory Vocabulary Repair Step
 *
 * Moves the values `RenameDutchValues` anglicised out of the ZGW and StUF
 * vocabularies back to the spelling their schema declares (dossiq#1841).
 *
 * WHAT WENT WRONG. `RenameDutchValueDecisions::VALUE_MAP` is keyed by property
 * and applied by COLUMN, against no schema at all, to every OpenRegister shard
 * table on the instance. Seventeen of its entries reached four properties whose
 * vocabulary is a standard's and is therefore Dutch by statute:
 * `confidentiality`, `vertrouwelijkheidaanduiding`, `stufMessage.status` and
 * `zaaksysteemMapping.synchronisationStatus`. The map's own docblock had always
 * excluded exactly these, and the map contradicted it.
 *
 * The tell that this was a slip and not a decision: the confidentiality map
 * covered five of the eight statutory levels, leaving `zaakvertrouwelijk`,
 * `vertrouwelijk` and `confidentieel` in Dutch. A set is translated whole or
 * not at all.
 *
 * WHY IT MATTERED. Every rewritten value is one its own schema refuses, so the
 * row could no longer be saved — a case handler opening a demo case and
 * changing anything got a validation refusal on a field they had not touched.
 * Worse for the confidentiality pair: `InformatieobjectAccessGuard` ranks the
 * Dutch eight and fails CLOSED on a value it cannot rank, so `openbaar` became
 * `public` and every public document was treated as the most secret one this
 * app holds, while `ZgwAuthMiddleware::isConfidentialityAllowed()` answered
 * false for every ZGW consumer.
 *
 * The map entries are removed at source, so a fresh instance cannot acquire
 * this. This step exists for the instances that already ran them.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Repair\Support\StoredValueRewriter;
use OCA\Dossiq\Repair\Support\ValueRewrite;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step restoring the statutory ZGW and StUF vocabularies.
 *
 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
 */
class RealignStatutoryVocabulary implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Schema slug => property => the wrong English value => the declared Dutch one.
	 *
	 * 🔴 SCHEMA-SCOPED, UNLIKE THE MIGRATION THAT CAUSED THIS. Reversing by
	 * column would repeat the original mistake in the other direction: `status`
	 * is a column on many schemas and `sent` is a perfectly good value on some
	 * of them, so a blanket `sent` -> `verzonden` would corrupt rows this step
	 * exists to protect. Every pair below is addressed through its own schema.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const REALIGNMENTS = [
		'case' => ['confidentiality' => self::CONFIDENTIALITY],
		'caseType' => ['confidentiality' => self::CONFIDENTIALITY],
		'document' => ['confidentiality' => self::CONFIDENTIALITY],
		'documentType' => ['confidentiality' => self::CONFIDENTIALITY],
		'informatieobject' => ['vertrouwelijkheidaanduiding' => self::CONFIDENTIALITY],
		'informatieobjecttype' => ['vertrouwelijkheidaanduiding' => self::CONFIDENTIALITY],
		'stufMessage' => [
			'status' => [
				'sent' => 'verzonden',
				'confirmed' => 'bevestigd',
				'error' => 'fout',
				'awaiting_retry' => 'wacht_op_retry',
			],
		],
		'zaaksysteemMapping' => [
			'synchronisationStatus' => [
				'error' => 'fout',
				'cancelled' => 'geannuleerd',
				'waiting' => 'wacht',
			],
		],
	];

	/**
	 * The ZGW Vertrouwelijkheidaanduiding, restored to the statutory spelling.
	 *
	 * Only the five the migration touched appear here. The other three were
	 * never translated, which is how the slip was spotted.
	 *
	 * @var array<string, string>
	 */
	private const CONFIDENTIALITY = [
		'public' => 'openbaar',
		'restricted_public' => 'beperkt_openbaar',
		'internal' => 'intern',
		'secret' => 'geheim',
		'top_secret' => 'zeer_geheim',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsServiceInterface $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
	 */
	public function getName(): string {
		return 'Realign Dossiq statutory ZGW and StUF vocabularies';
	}//end getName()

	/**
	 * Move every mis-translated value back.
	 *
	 * NEVER throws: an upgrade that dies here leaves the instance worse off
	 * than a value left in English does.
	 *
	 * @param IOutput $output Output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq: statutory vocabulary realignment skipped, OpenRegister unavailable.');
			return;
		}

		$repaired = 0;

		try {
			$this->withSystemIdentity(
				objectService: $objectService,
				work: function () use ($objectService, &$repaired): void {
					$repaired = $this->realignAll(objectService: $objectService);
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: statutory vocabulary realignment failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('Dossiq: statutory vocabulary realignment could not run: ' . $e->getMessage());
			return;
		}

		if ($repaired === 0) {
			$output->info('Dossiq: no rows needed the statutory vocabulary realignment.');
			return;
		}

		$output->info(sprintf('Dossiq: realigned the statutory vocabulary on %d row(s).', $repaired));
	}//end run()

	/**
	 * Walk every schema, property and value pair.
	 *
	 * @param object $objectService OpenRegister's object service.
	 *
	 * @return integer The number of rows rewritten.
	 */
	private function realignAll(object $objectService): int {
		$rewriter = new StoredValueRewriter(objectService: $objectService);
		$repaired = 0;

		foreach (self::REALIGNMENTS as $schema => $properties) {
			foreach ($this->schemaRewrites(schema: (string)$schema, properties: $properties) as $rewrite) {
				$repaired += $rewriter->rewrite(rewrite: $rewrite);
			}
		}

		return $repaired;
	}//end realignAll()

	/**
	 * The value rewrites one schema declares, property by property, in declaration order.
	 *
	 * @param string                               $schema     The schema slug.
	 * @param array<string, array<string, string>> $properties Property => wrong value => right value.
	 *
	 * @return array<int, ValueRewrite> The rewrites.
	 */
	private function schemaRewrites(string $schema, array $properties): array {
		$rewrites = [];
		foreach ($properties as $property => $values) {
			foreach ($values as $wrong => $right) {
				$rewrite = new ValueRewrite();
				$rewrite->schema = $schema;
				$rewrite->property = (string)$property;
				$rewrite->wrong = (string)$wrong;
				$rewrite->right = $right;
				$rewrites[] = $rewrite;
			}
		}

		return $rewrites;
	}//end schemaRewrites()
}//end class
