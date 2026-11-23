<?php

/**
 * Loads the example dataset the setup wizard picked, or records that none is wanted.
 *
 * The dataset a card's Load button posted is checked against the sets the
 * demo data service offers, imported, and the choice recorded once the
 * import returned. {@see \OCA\Dossiq\Controller\SetupController} decides
 * which action asked and reads the pick back for its status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseTypePackageChoiceInterface;
use OCA\Dossiq\Kernel\Contracts\DemoDataServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use Throwable;

/**
 * The Example data step's load and decline.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
final class DatasetLoad {

	/**
	 * App-config key holding the dataset the operator picked.
	 *
	 * @var string
	 */
	public const DATASET_KEY = 'demo_dataset';

	/**
	 * App-config key recording that the optional demo-data step has been dealt with.
	 *
	 * @var string
	 */
	public const DECIDED_KEY = DemoDataServiceInterface::DECIDED_KEY;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                     $appConfig       Records the pick and the decision.
	 * @param DemoDataServiceInterface       $demoDataService Offers and imports the datasets.
	 * @param CaseTypePackageChoiceInterface $caseTypes       Writes the examples of the packages installed before the choice.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DemoDataServiceInterface $demoDataService,
		private readonly CaseTypePackageChoiceInterface $caseTypes,
	) {
	}//end __construct()

	/**
	 * Import the dataset a card's Load button posted as `dataset`, or the
	 * stored pick when nothing is posted (ADR-111 rule 4).
	 *
	 * @param string $actionId The action that asked, which decides whether an
	 *                         unanswered choice is refused or means the shipped set.
	 * @param mixed  $posted   The posted `dataset`, or null.
	 *
	 * @return DataResponse The outcome, carrying the counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function load(string $actionId, mixed $posted): DataResponse {
		// The card's Load button names its dataset in the body. An older wizard
		// posts nothing and relies on the pick stored a step earlier. Nothing is
		// stored before the load succeeds: a failed load must leave the step
		// open for an operator who asked for data and got none.
		$refusal = $this->postedRefusal(posted: $posted);
		if ($refusal !== null) {
			return $refusal;
		}

		$picked = $this->chosenDataset(actionId: $actionId, posted: $posted);

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for, which is
		// the failure this whole step exists to avoid.
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoDataServiceInterface::NONE_DATASET) {
			return $this->decline();
		}

		return $this->importDataset(picked: $picked);
	}//end load()

	/**
	 * Record that no example data is wanted.
	 *
	 * Skipping IS choosing "None", so both keys are written: an older
	 * runbook may read either one.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function decline(): DataResponse {
		$this->appConfig->setValueString('dossiq', self::DATASET_KEY, DemoDataServiceInterface::NONE_DATASET);
		$this->appConfig->setValueString('dossiq', self::DECIDED_KEY, 'skipped');

		return new DataResponse(['success' => true, 'message' => 'No example data was loaded.']);
	}//end decline()

	/**
	 * Refuse a posted dataset nobody answers to; nothing posted is no refusal.
	 *
	 * @param mixed $posted The posted `dataset`, or null.
	 *
	 * @return DataResponse|null
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function postedRefusal(mixed $posted): ?DataResponse {
		if ($posted === null) {
			return null;
		}

		return $this->refuseDataset(value: $posted);
	}//end postedRefusal()

	/**
	 * The dataset to load: the posted one, else the stored pick.
	 *
	 * The legacy id carries no answer, so it means the shipped dataset. A
	 * caller that posts it has said which one by posting it.
	 *
	 * @param string $actionId The action that asked.
	 * @param mixed  $posted   The posted `dataset`, or null.
	 *
	 * @return string The dataset id, or '' when none was chosen.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function chosenDataset(string $actionId, mixed $posted): string {
		$picked = $this->appConfig->getValueString('dossiq', self::DATASET_KEY, '');
		if ($posted !== null) {
			$picked = (string)$posted;
		}

		if ($actionId === 'install-demo-data' && $picked === '') {
			return DemoDataServiceInterface::DEMO_DATASET;
		}

		return $picked;
	}//end chosenDataset()

	/**
	 * Import the dataset and record the choice once the import returned.
	 *
	 * Recorded only after the import actually returned. Marking it first
	 * would let a failed install present as a finished step, and an import
	 * that stored nothing now throws rather than returning zeroes.
	 * Loading IS choosing the set, so the pick is recorded too.
	 *
	 * The packages installed before this choice skipped their example rows,
	 * and reinstalling a current package writes nothing, so their examples
	 * are written now, once the decision says this instance wants them. Their
	 * outcomes travel in `detail.packageExamples`.
	 *
	 * @param string $picked The dataset.
	 *
	 * @return DataResponse The outcome, carrying the counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function importDataset(string $picked): DataResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (Throwable $e) {
			return new DataResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		$this->appConfig->setValueString('dossiq', self::DATASET_KEY, $picked);
		$this->appConfig->setValueString('dossiq', self::DECIDED_KEY, DemoDataServiceInterface::DECIDED_INSTALLED);

		return $this->importedAnswer(imported: $imported + ['packageExamples' => $this->caseTypes->writeExamplesOfInstalled()]);
	}//end importDataset()

	/**
	 * The answer to an import that returned.
	 *
	 * 🔴 THE COUNTS, ALWAYS, AND BOTH OF THEM. "Demo data installed" with no
	 * numbers cannot be told apart from an import that wrote nothing — and
	 * one number cannot be told apart from a number read out of the file it
	 * was asked to import. The landing is stated against the ask.
	 *
	 * 🔴 THE SCHEMA COUNT IS GONE, BECAUSE IT WAS COUNTING THE DAMAGE.
	 * `schemas` is how many schemas the import DEFINED, and this message
	 * read it as "how many schemas the objects landed in". Those were the
	 * same number only while the demo import was forking a schema set of
	 * its own: "across 139 schemas" was the fork, reported as a feature.
	 * A demo set defines no schemas now, so the honest sentence is about
	 * objects. The full counts stay in `detail`.
	 *
	 * @param array<string, mixed> $imported The import's counts.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function importedAnswer(array $imported): DataResponse {
		return new DataResponse(
			[
				'success' => true,
				'message' => sprintf(
					'Demo data installed: %d of %d objects stored, %d refused, %d already present.',
					$imported['objects'],
					$imported['requested'],
					$imported['refused'],
					$imported['unchanged']
				),
				'detail'  => $imported,
			]
		);
	}//end importedAnswer()

	/**
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return DataResponse|null The refusal, or null when the dataset is known.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function refuseDataset(mixed $value): ?DataResponse {
		$named = 'that';
		if (is_scalar($value) === true) {
			$named = (string)$value;
		}

		$known = array_column($this->demoDataService->listChoices(), 'id');
		if (is_scalar($value) === true && in_array($named, $known, true) === true) {
			return null;
		}

		return new DataResponse(
			['success' => false, 'message' => 'No dataset is called "' . $named . '".'],
			Http::STATUS_BAD_REQUEST,
		);
	}//end refuseDataset()
}//end class
