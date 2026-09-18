<?php

/**
 * The case types other apps contribute become real case types.
 *
 * `CaseTypeContributionRegistry` has asked every installed app for the kinds of
 * work it handles since case-types shipped, duck-typing a provider at the
 * convention FQCN the fleet uses, and NOTHING CALLED IT. pipelinq ships one —
 * `PipelinqGateway::CASE_TYPE_CONTRIBUTIONS`, which is the only place in this
 * app that spells the class name — declaring `pipelinq-ticket`
 * with its discriminator, its assignee and its status property. So a ticket has
 * been a declared dossiq case type for as long as both apps have been
 * installed, and the case-type list has never shown it.
 *
 * 🔴 THE CASE-TYPE LIST IS A DECLARATIVE INDEX OVER ONE SCHEMA, so a contributed
 * type cannot be merged into it at read time without a second surface. It is
 * materialised instead: a contributed type is written into `caseType` once and
 * then it is an ordinary case type, on the list, on the detail page and in
 * every facet, with no new page to maintain and nothing that can disagree with
 * the list beside it.
 *
 * 🔴 WRITTEN ONCE, NEVER UPDATED. An administrator who renames a contributed
 * case type, gives it a category or sets a deadline on it has said something
 * this app should not overwrite on the next upgrade. So an identifier that
 * already exists is left exactly as it is, and only a genuinely new one is
 * created. That makes this step safe to run on every upgrade, which is when it
 * runs.
 *
 * 🔴 AND NEVER DELETED. A case type whose contributing app has been removed is
 * still the type of every case already filed under it. Deleting it would orphan
 * those cases in exchange for tidiness on one list.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Contribution\CaseTypeContributionRegistry;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes each contributed case type into the case-type register, once.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class SeedContributedCaseTypes implements IRepairStep {

	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The keys a contributed declaration may put on the case type.
	 *
	 * An allow-list rather than a copy of the whole declaration. A provider
	 * lives in another app's repository and may carry keys that mean something
	 * there and nothing here, and OpenRegister drops an undeclared key in
	 * SILENCE: a provider that grew a field would look stored and be gone.
	 * `discriminator`, `assigneeProperty` and `statusProperty` are deliberately
	 * NOT here, because dossiq's `caseType` declares no such properties and
	 * nothing in this app reads them yet.
	 *
	 * @var array<int, string>
	 */
	private const CARRIED = ['identifier', 'title', 'description', 'category', 'contributedBy'];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeContributionRegistry $registry        What the installed apps contribute.
	 * @param SettingsService              $settingsService Register and schema resolution, and the object service.
	 * @param LoggerInterface              $logger          The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly CaseTypeContributionRegistry $registry,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function getName(): string {
		return 'Add the case types other installed apps contribute';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * Elevated, for the reason every seeding step here is: an upgrade has no
	 * session, so OpenRegister resolves the actor as 'Anonymous' and refuses
	 * the write. An unelevated run would report "added 0" and look like an
	 * instance where no app contributes anything.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function run(IOutput $output): void {
		$this->withSystemIdentity(
			objectService: $this->settingsService->getObjectService(),
			work: function () use ($output): void {
				$this->seed(output: $output);
			}
		);
	}//end run()

	/**
	 * The seeding itself, once an identity is in place.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function seed(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('Dossiq: the case-type register is not configured; contributed case types skipped.');

			return;
		}

		$added = 0;
		foreach ($this->registry->all() as $contributed) {
			try {
				$added += $this->addOne(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					contributed: $contributed,
				);
			} catch (Throwable $e) {
				// ONE APP'S BAD DECLARATION MUST NOT COST THE OTHERS THEIRS,
				// which is the rule the registry already applies to a provider
				// that throws. The same rule has to hold on the write.
				$this->logger->error(
					'Dossiq: a contributed case type could not be stored',
					[
						'identifier' => (string)($contributed['identifier'] ?? ''),
						'app' => (string)($contributed['contributedBy'] ?? ''),
						'reason' => $e->getMessage(),
					]
				);
			}
		}

		$output->info('Dossiq: added ' . $added . ' contributed case type(s).');
	}//end seed()

	/**
	 * Store one contributed case type, unless its identifier is already taken.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The case-type schema.
	 * @param array<string, mixed> $contributed   The normalised declaration.
	 *
	 * @return int 1 when a case type was created, 0 when one already existed.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function addOne(
		object $objectService,
		string $register,
		string $schema,
		array $contributed,
	): int {
		$identifier = (string)($contributed['identifier'] ?? '');
		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['identifier' => $identifier],
		);
		if ($existing !== []) {
			return 0;
		}

		$payload = [];
		foreach (self::CARRIED as $key) {
			$value = ($contributed[$key] ?? null);
			if ($value !== null && $value !== '') {
				$payload[$key] = $value;
			}
		}

		$this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: $payload,
		);

		return 1;
	}//end addOne()
}//end class
