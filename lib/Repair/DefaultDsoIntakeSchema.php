<?php

/**
 * Dossiq Default DSO Intake Schema Repair Step.
 *
 * Points `dso_vergunningaanvraag_schema` at integriq's `dso_verzoek` schema
 * when nobody ever set it, so an instance with integriq makes a case of every
 * mapped DSO verzoek without an admin first finding the key.
 *
 * WHY. Since dossiq#3446 the listener on integriq's `dso_verzoek` is the only
 * path from a DSO verzoek to a case, and it does nothing while the key is
 * empty. The key had no default, so a fresh install made no DSO cases.
 *
 * NEVER SET IS AN ABSENT KEY. An admin who empties the key has turned DSO
 * intake off on purpose, and the key then exists with an empty value. This
 * step only writes when the key is absent, so it never overwrites a value an
 * admin set, an empty one included. Deleting the key
 * (`occ config:app:delete dossiq dso_vergunningaanvraag_schema`) hands it
 * back to this step.
 *
 * THE VALUE IS THE SCHEMA'S ID, as a string, because that is what
 * OpenRegister stores in an object's `@self.schema` and what the listener
 * compares the key with. The schema is found through integriq's register by
 * slug, never by a hard-coded id: the id differs per instance.
 *
 * Without integriq, or before integriq's register is imported, it writes
 * nothing, so a later upgrade still fills the key. It is a config read on
 * OpenRegister and one app config write; it never throws, because it runs
 * under `<install>`.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills the DSO intake schema key once, when it was never set.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */
class DefaultDsoIntakeSchema implements IRepairStep {

	/**
	 * The app config key the listener reads.
	 *
	 * @var string
	 */
	public const KEY = 'dso_vergunningaanvraag_schema';

	/**
	 * integriq's slug for the DSO verzoek schema.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'dso_verzoek';

	/**
	 * integriq's register slugs, newest first (it was `openconnector`).
	 *
	 * @var list<string>
	 */
	public const REGISTER_SLUGS = ['integriq', 'openconnector'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig  The app config.
	 * @param IAppManager        $appManager The app manager.
	 * @param ContainerInterface $container  The DI container, for OpenRegister's mappers.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name shown while the step runs.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function getName(): string {
		return 'Point DSO intake at integriq\'s dso_verzoek schema when it was never set';
	}//end getName()

	/**
	 * Fill the key when it is absent and integriq ships the schema.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->hasKey(app: Application::APP_ID, key: self::KEY, lazy: null) === true) {
			$output->info('The DSO intake schema was set by an admin; left as it is.');
			return;
		}

		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			$output->info('integriq is not installed, so DSO intake stays off.');
			return;
		}

		$schemaId = $this->findSchemaId();
		if ($schemaId === null) {
			$output->info('integriq\'s dso_verzoek schema was not found; DSO intake stays off until the next upgrade.');
			return;
		}

		$this->appConfig->setValueString(app: Application::APP_ID, key: self::KEY, value: $schemaId);
		$output->info(sprintf('DSO intake now reads integriq\'s dso_verzoek schema (%s).', $schemaId));
	}//end run()

	/**
	 * The id of integriq's dso_verzoek schema, from integriq's own register.
	 *
	 * @return string|null The id, or null when it cannot be found.
	 */
	private function findSchemaId(): ?string {
		try {
			$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: OpenRegister is not available, the DSO intake schema was not set',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach (self::REGISTER_SLUGS as $slug) {
			try {
				// A read of configuration, not of objects; a repair step has no user.
				$register = $registerMapper->find($slug, false, false);
				$schema = $schemaMapper->findBySlugInIds(self::SCHEMA_SLUG, (array)$register->getSchemas());
			} catch (Throwable $e) {
				continue;
			}

			if ($schema !== null) {
				return (string)$schema->getId();
			}
		}

		return null;
	}//end findSchemaId()
}//end class
