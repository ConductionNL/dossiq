<?php

/**
 * Dossiq register-application migration repair step
 *
 * Re-points the register and its schemas from the `procest` application id to
 * `dossiq` before the register import runs.
 *
 * WHY THIS IS NEEDED, AND WHY IT MUST RUN FIRST. The register descriptor's
 * `x-openregister.app` names the owning application. OpenRegister's
 * ImportHandler resolves the two halves by different keys: a register is
 * matched by SLUG alone and then has setApplication() applied, so it follows a
 * rename by itself, while a schema is matched by findByApplicationAndSlug() —
 * the PAIR, on lower(slug).
 *
 * So once `x-openregister.app` says `dossiq`, every schema lookup misses the
 * schemas that still say `procest`. The import does not fail and does not warn:
 * it takes the "not found, will create new one" branch and builds a second,
 * EMPTY set of schemas under `dossiq`, while the originals and every object
 * stored against them stay behind under `procest`. The UI then renders empty
 * collections and the API 404s for `procest-<schema>` — a rename that silently
 * half-happened, presented to the user as missing data.
 *
 * Running BEFORE InitializeSettings means the schemas already carry the new
 * application id when the import looks for them, so the import updates the
 * originals instead of forking them.
 *
 * NOT THE REGISTER SLUG. The register keeps its slug `procest` — that is an
 * identifier written into stored data and referenced by 77 manifest pages, and
 * it does not move with the app id. Only the `application` column changes here.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Move the register and its schemas onto the `dossiq` application id.
 */
class MigrateRegisterApplicationId implements IRepairStep {
	/**
	 * OpenRegister's migration service, resolved by name at runtime.
	 *
	 * A string rather than an import: dossiq must install and boot on an
	 * instance without OpenRegister, so this is a duck-typed lookup guarded by
	 * class_exists() — the same shape MigrateArchivalToOpenRegister uses.
	 *
	 * @var string
	 */
	private const MIGRATOR = 'OCA\OpenRegister\Service\SchemaApplicationMigrator';

	/**
	 * The application id this app used to be published under.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'procest';

	/**
	 * The application id this app is published under now.
	 *
	 * @var string
	 */
	private const NEW_APP_ID = 'dossiq';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()


	/**
	 * Step name.
	 *
	 * @return string The human-readable step name.
	 */
	public function getName(): string {
		return 'Move the Dossiq register and schemas onto the dossiq application id';

	}//end getName()


	/**
	 * Run the migration.
	 *
	 * Never throws into the upgrade: a failure here leaves the estate exactly as
	 * it was (the old ids still own everything), which the next run retries.
	 * Aborting the whole app upgrade would be a worse outcome than a warning.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		if (class_exists(self::MIGRATOR) === false) {
			// Either OpenRegister is absent, or it predates the migrator. Both
			// are legitimate; say which is being skipped rather than passing
			// silently, because a silent skip here looks identical to a
			// migration that ran and found nothing.
			$output->info(
				'OpenRegister\'s SchemaApplicationMigrator is not available; '
				. 'skipping the ' . self::OLD_APP_ID . ' -> ' . self::NEW_APP_ID . ' application migration.'
			);
			return;
		}

		try {
			$migrator = $this->container->get(self::MIGRATOR);
			$result   = $migrator->migrate(self::OLD_APP_ID, self::NEW_APP_ID);
		} catch (\Throwable $e) {
			$output->warning('Could not migrate the register application id: ' . $e->getMessage());
			$this->logger->error(
				'Dossiq: register application migration failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if (($result['ok'] ?? false) === false) {
			$reason = (string)($result['reason'] ?? 'unknown');
			if ($reason === 'collisions') {
				$slugs = implode(', ', (array)($result['collisions'] ?? []));
				$output->warning(
					'Refused: these schema slugs already exist under "' . self::NEW_APP_ID . '": ' . $slugs
					. '. An import has already forked them; run occ openregister:schemas:dedup, then re-run the upgrade.'
				);
				$this->logger->warning(
					'Dossiq: register application migration refused, forked schemas present',
					['collisions' => $slugs]
				);
				return;
			}

			$output->warning('Register application migration did not run: ' . $reason);
			return;
		}

		$schemas   = (int)($result['schemas'] ?? 0);
		$registers = (int)($result['registers'] ?? 0);

		if (($schemas + $registers) === 0) {
			$output->info('Register application id already migrated; nothing to move.');
			return;
		}

		$output->info(
			'Moved ' . $schemas . ' schema(s) and ' . $registers . ' register(s) from "'
			. self::OLD_APP_ID . '" to "' . self::NEW_APP_ID . '".'
		);

	}//end run()


}//end class
