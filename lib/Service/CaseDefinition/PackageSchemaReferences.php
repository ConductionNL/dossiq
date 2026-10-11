<?php

/**
 * Resolves a package setting that names another app's schema.
 *
 * A schema id differs per instance, so a package cannot ship one. It ships
 * the schema's address instead: the app that owns it, the register slug (or
 * the slugs that register has had) and the schema slug. The installer turns
 * that address into this instance's id at install time, and refuses the
 * package, naming the app, when the schema is not there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns settings that name a schema by app, register and slug into schema ids.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageSchemaReferences {

	/**
	 * Constructor.
	 *
	 * @param IAppManager         $appManager Answers whether the owning app is installed.
	 * @param ContainerInterface  $container  The DI container, for OpenRegister's mappers.
	 * @param LoggerInterface     $logger     Logger.
	 * @param PackageSettingRules $rules      Reads a schema reference.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PackageSettingRules $rules = new PackageSettingRules(),
	) {
	}//end __construct()

	/**
	 * Resolve every reference among a package's settings.
	 *
	 * @param array<string, mixed> $settings The decoded settings.json.
	 *
	 * @return array{settings: array<string, mixed>, missing: array<int, string>, reasons: array<int, string>}
	 *         The settings with each reference replaced by its schema id, and
	 *         for each schema not found here `<app>:<slug>` and a sentence.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function resolve(array $settings): array {
		$missing = [];
		$reasons = [];
		foreach ($settings as $key => $value) {
			$reference = $this->rules->reference(value: $value);
			if ($reference === null) {
				continue;
			}

			['app' => $app, 'registers' => $registers, 'slug' => $slug] = $reference;

			$id = null;
			if ($this->appManager->isInstalled($app) === true) {
				$id = $this->findSchemaId(registers: $registers, slug: $slug);
			}

			if ($id === null) {
				$missing[] = "{$app}:{$slug}";
				$reasons[] = $this->reason(app: $app, slug: $slug, registers: $registers);
				continue;
			}

			$settings[$key] = $id;
		}

		return ['settings' => $settings, 'missing' => $missing, 'reasons' => $reasons];
	}//end resolve()

	/**
	 * Why a reference could not be resolved, for a person.
	 *
	 * @param string             $app       The owning app.
	 * @param string             $slug      The schema slug.
	 * @param array<int, string> $registers The register slugs.
	 *
	 * @return string The sentence.
	 */
	private function reason(string $app, string $slug, array $registers): string {
		if ($this->appManager->isInstalled($app) === false) {
			return "{$app}'s schema '{$slug}', and {$app} is not installed";
		}

		return "{$app}'s schema '{$slug}', which register " . implode(' or ', $registers) . ' does not hold here';
	}//end reason()

	/**
	 * The id of the schema with this slug in the first register that has it.
	 *
	 * @param array<int, string> $registers The register slugs, newest first.
	 * @param string             $slug      The schema slug.
	 *
	 * @return string|null The id, or null when no register holds it.
	 */
	private function findSchemaId(array $registers, string $slug): ?string {
		foreach ($registers as $registerSlug) {
			try {
				$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
				$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
				// A read of configuration, not of objects; an install may have no user.
				$register = $registerMapper->find($registerSlug, false, false);
				$schema = $schemaMapper->findBySlugInIds($slug, (array)$register->getSchemas());
			} catch (Throwable $e) {
				// An unknown register throws; so does a missing OpenRegister.
				// Either way this register does not hold the schema here.
				$this->logger->info(
					'Dossiq: register {register} did not answer for schema {slug}',
					['register' => $registerSlug, 'slug' => $slug, 'exception' => $e->getMessage()]
				);
				continue;
			}

			if ($schema !== null) {
				return (string)$schema->getId();
			}
		}

		return null;
	}//end findSchemaId()
}//end class
