<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CommunicationChannelInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\RepairSchema;
use OCA\Dossiq\Repair\Support\RepairSchemas;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turn every stored `communicationChannel` into a channel slug.
 *
 * Decision 171 (Q-dossiq-L2-3): `case.communicationChannel` is a slug enum
 * (email, portal, post, website, zgw-api). Cases written before it can hold a
 * ZGW communicatiekanaal URL or the words of an older channel intake. This
 * step maps each such value with CommunicationChannel: a URL becomes the slug
 * an administrator mapped it to, else `zgw-api`; known words become their
 * slug; anything else leaves the channel empty. The value it replaced is kept
 * in `communicationChannelSource`, and every converted case is named in the
 * log. It runs once per version behind a persisted key (ADR-106), and a case
 * that cannot be written is named and left for the next run.
 *
 * It replaces ReportCommunicationChannelValues, which only listed the values
 * and was never registered.
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class NormaliseCommunicationChannelValues implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'communication_channel_values_normalised';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * How many cases one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface      $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig           $appConfig       The version gate.
	 * @param CommunicationChannelInterface $channels        The mapping onto slugs.
	 * @param LoggerInterface      $logger          Where each case is named.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly CommunicationChannelInterface $channels,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Turn every Dossiq case communication channel into a channel slug';
	}//end getName()

	/**
	 * Convert the cases, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$target = (new RepairSchemas(settingsService: $this->settingsService))->configured(schemaKey: 'case_schema');
		if ($target === null) {
			$output->info('Dossiq communication channels: OpenRegister or the case schema is unavailable; nothing converted.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $target->objectService,
			work: function () use ($target, $output): void {
				$this->convert(target: $target, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every case and convert the ones off the slugs.
	 *
	 * @param RepairSchema $target The schema it walks.
	 * @param IOutput      $output The upgrade output.
	 *
	 * @return void
	 */
	private function convert(RepairSchema $target, IOutput $output): void {
		$tally = ['converted' => 0, 'failed' => 0, 'skipped' => 0];
		foreach ($target->rows(pageSize: self::PAGE_SIZE) as $row) {
			$tally[$this->convertOne(target: $target, row: $row)]++;
		}

		if ($tally['failed'] === 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq communication channels: ' . $tally['converted'] . ' case(s) converted to a channel slug, '
			. $tally['failed'] . ' left for the next run.');
	}//end convert()

	/**
	 * Convert one case's stored channel value to a slug.
	 *
	 * @param RepairSchema         $target The schema it walks.
	 * @param array<string, mixed> $row    One stored row.
	 *
	 * @return string 'converted', 'failed' (logged, and left for the next run) or 'skipped'.
	 */
	private function convertOne(RepairSchema $target, array $row): string {
		$value = ($row['communicationChannel'] ?? null);
		$isSlug = in_array($value, CommunicationChannelInterface::SLUGS, true);
		if (is_string($value) === false || trim($value) === '' || $isSlug === true) {
			return 'skipped';
		}

		$caseId = $target->idOf(row: $row);
		if ($caseId === '') {
			return 'skipped';
		}

		$changes = $this->channels->normalise(value: $value);
		try {
			$target->patch(id: $caseId, changes: $changes);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq communication channels: case ' . $caseId . ' could not be converted and keeps its value until the next run',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()],
			);
			return 'failed';
		}

		$this->logger->info(
			sprintf('Dossiq channels: case %s now reads "%s"; it held "%s"', $caseId, (string)($changes['communicationChannel'] ?? ''), $value),
			['app' => AppIdentity::APP_ID],
		);

		return 'converted';
	}//end convertOne()
}//end class
