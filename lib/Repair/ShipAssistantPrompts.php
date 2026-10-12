<?php

/**
 * Dossiq repair step: ship the initial assistant prompt library into hermiq.
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-dossiq-ships-an-initial-prompt-library-and-stops-owning-it-req-aic-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Hands the prompts dossiq wants offered on a case to hermiq's library, once
 * per upgrade, and then owns nothing.
 *
 * 🔴 AN EDITED PROMPT STANDS. hermiq's `seed()` skips every prompt an
 * administrator has touched, so running this again after an edit restores
 * nothing. dossiq keeps no copy of the edited state and reads the offered
 * prompts back from hermiq.
 *
 * 🔑 THE PROMPTS ARE DATA, NOT CODE. They live in
 * `lib/Settings/assistant-prompts.json`; this step only carries them across.
 *
 * NON-FATAL. Without hermiq, or with a hermiq that has no library, the step
 * says so and stops: an upgrade must not fail on an optional sibling.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-dossiq-ships-an-initial-prompt-library-and-stops-owning-it-req-aic-06
 */
class ShipAssistantPrompts implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * hermiq's prompt library, looked up by name so dossiq does not need hermiq
	 * to load.
	 *
	 * @var string
	 */
	public const LIBRARY_CLASS = 'OCA\\Hermiq\\Service\\Assistant\\AssistantPromptLibrary';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container   Resolves hermiq's library.
	 * @param IAppManager          $appManager  Says whether hermiq is installed.
	 * @param SettingsService|null $settings    Resolves OpenRegister for the system identity.
	 * @param string|null          $libraryFile The prompt library file; the shipped one when null.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly ?SettingsService $settings=null,
		private readonly ?string $libraryFile=null,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string The name shown during upgrade.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-dossiq-ships-an-initial-prompt-library-and-stops-owning-it-req-aic-06
	 */
	public function getName(): string {
		return 'Ship the initial assistant prompt library into hermiq';
	}//end getName()

	/**
	 * Hand the shipped prompts to hermiq.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-edited-prompt-is-not-overwritten-by-dossiq
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isInstalled('hermiq') === false) {
			$output->info('hermiq is not installed; no assistant prompts shipped.');
			return;
		}

		try {
			$library = $this->container->get(self::LIBRARY_CLASS);
		} catch (Throwable $e) {
			$output->warning('hermiq has no prompt library to ship into: ' . $e->getMessage());
			return;
		}

		if (is_object($library) === false || method_exists($library, 'seed') === false) {
			$output->warning('hermiq\'s prompt library cannot take shipped prompts.');
			return;
		}

		$prompts = $this->shippedPrompts();
		if ($prompts === []) {
			$output->warning('The shipped assistant prompt library is empty or unreadable.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $this->settings?->getObjectService(),
			work: function () use ($library, $prompts, $output): void {
				try {
					$result = $library->seed('dossiq', $prompts);
				} catch (Throwable $e) {
					$output->warning('Could not ship the assistant prompts: ' . $e->getMessage());
					return;
				}

				$output->info(
					'Assistant prompts: ' . (int)($result['installed'] ?? 0) . ' shipped, '
					. (int)($result['skipped'] ?? 0) . ' kept as an administrator left them.'
				);
			}
		);
	}//end run()

	/**
	 * The prompts in the library file, each carrying the library's usage scope.
	 *
	 * @return array<int, array<string, mixed>> The prompts, or an empty list when the file is unreadable.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-dossiq-ships-an-initial-prompt-library-and-stops-owning-it-req-aic-06
	 */
	public function shippedPrompts(): array {
		$file = ($this->libraryFile ?? __DIR__ . '/../Settings/assistant-prompts.json');
		if (is_readable($file) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($file), true);
		if (is_array($decoded) === false || is_array($decoded['prompts'] ?? null) === false) {
			return [];
		}

		$scope = (string)($decoded['usageScope'] ?? '');
		$prompts = [];
		foreach ($decoded['prompts'] as $prompt) {
			if (is_array($prompt) === false || trim((string)($prompt['label'] ?? '')) === ''
				|| trim((string)($prompt['prompt'] ?? '')) === ''
			) {
				continue;
			}

			$prompts[] = array_merge(['usageScope' => $scope], $prompt);
		}

		return $prompts;
	}//end shippedPrompts()
}//end class
