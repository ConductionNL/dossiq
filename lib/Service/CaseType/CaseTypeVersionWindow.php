<?php

/**
 * When a version of a case type starts and stops being offered.
 *
 * Three writes about dates and one forward link, pulled out of
 * {@see \OCA\Dossiq\Service\CaseTypePublishService} because they are one
 * subject and that class was already at the complexity the analyser allows. The
 * seam is not merely a budget: publishing is "is this draft ready, and make it
 * so", and this is "which version is in force, from when, until when". They
 * happen in the same second and they answer different questions.
 *
 * 🔴 THE FORWARD LINK AND THE CLOSING DATE ARE ONE WRITE, BECAUSE THEY ARE ONE
 * FACT. `supersededBy` is what the pickers and the index read, and `validUntil`
 * is what a person reads, and for as long as only the first was written the two
 * disagreed: the Case types index showed a version with an open-ended validity
 * beside its own successor, and an auditor reading the catalogue saw two
 * versions of one zaaktype both valid indefinitely.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens a version's validity window, and closes the one it replaces.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseTypeVersionWindow {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param CaseTypeStore   $store           The app's one case type reader.
	 * @param ITimeFactory    $time            The day a version takes effect, and the day the last one closes.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Today, as the case type schema spells a date.
	 *
	 * @return string The day.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}//end today()

	/**
	 * Fill in the day this version takes effect, when nobody typed one.
	 *
	 * Written only when empty: an author who typed a future `validFrom` because
	 * the new fee schedule starts next month meant it, and overwriting it with
	 * today would quietly bring the change forward.
	 *
	 * @param array<string, mixed> $caseType The version being published.
	 *
	 * @return array<string, mixed> The version, with its dates.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function open(array $caseType): array {
		$today = $this->today();

		if (trim((string)($caseType['validFrom'] ?? '')) === '') {
			$caseType['validFrom'] = $today;
		}

		if (trim((string)($caseType['versionDate'] ?? '')) === '') {
			$caseType['versionDate'] = $today;
		}

		return $caseType;
	}//end open()

	/**
	 * Close the version this one replaces.
	 *
	 * 🔴 THIS IS THE MOMENT A CASE TYPE VERSION STOPS BEING OFFERED, AND THE
	 * ONLY ONE. Publishing is the single write dossiq owns on a case type (the
	 * page writes everything else straight to OpenRegister), so the forward
	 * link has to be written here or nowhere. Written in two places it would be
	 * a rule with two implementations, and the failure mode is silent: two
	 * versions of one case type both offered in the picker, under the same
	 * name, and no way for the person choosing to tell them apart.
	 *
	 * The previous version keeps `isDraft: false` on purpose. Its cases are
	 * still running on it and still resolve their statuses, results and
	 * deadlines through it. It is closed to NEW cases, not retired.
	 *
	 * The closing day is the day the successor TAKES EFFECT, not today: an
	 * author may publish a version whose `validFrom` is next month, and closing
	 * the running version today would leave the type with no version in force
	 * for a month. That leaves the two overlapping on the switch-over day
	 * itself, which is deliberate and is the smaller wrong: `supersededBy`, not
	 * the date, is what stops new cases landing on the old version, so the
	 * overlap misleads nobody while a gap would leave real days uncovered.
	 *
	 * An existing `validUntil` is never moved. A version already closed on a
	 * date somebody chose is a decision, and publishing a successor is not the
	 * moment to overrule it.
	 *
	 * A failure here is logged and not fatal. The new version is already
	 * published, and refusing after that write would leave the two halves
	 * disagreeing with nothing to say which one ran.
	 *
	 * @param array<string, mixed> $caseType    The version just published.
	 * @param string               $caseTypeId  Its id.
	 * @param string               $takesEffect The day the new version is valid from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function closePrevious(array $caseType, string $caseTypeId, string $takesEffect): void {
		$previousId = $this->store->referenceId(value: ($caseType['previousVersion'] ?? ''));
		if ($previousId === '' || $previousId === $caseTypeId) {
			return;
		}

		$previous = $this->store->readCaseType(caseTypeId: $previousId);
		if ($previous === []) {
			$this->logger->warning(
				'Case type publish: the previous version could not be read, so it was not closed',
				['caseType' => $caseTypeId, 'previousVersion' => $previousId]
			);
			return;
		}

		$previous['supersededBy'] = $caseTypeId;

		if (trim((string)($previous['validUntil'] ?? '')) === '' && $takesEffect !== '') {
			$previous['validUntil'] = $takesEffect;
		}

		if ($this->save(object: $previous) === false) {
			$this->logger->warning(
				'Case type publish: the previous version stays open for new cases',
				['caseType' => $caseTypeId, 'previousVersion' => $previousId]
			);
		}
	}//end closePrevious()

	/**
	 * Close a published version for new cases, as a deliberate act.
	 *
	 * 🔴 IT IS A SERVER ACT AND NOT A FIELD WRITE FROM THE PAGE, AND THE REASON
	 * IS THE WORD "TODAY". The design had Deprecate patch `validUntil: @today`
	 * through the object store. A declared `object-op` merges its `values` into
	 * the row VERBATIM: the token is not resolved for that action type, so the
	 * string `@today` would have been written into a date field, and
	 * OpenRegister would have stored it. The field reads filled in, the date
	 * reads as nonsense, and nothing refuses. Here the day comes from the clock.
	 *
	 * The guard is the second reason. A version with nothing to replace it is
	 * the only version cases can be filed under, so closing it would leave the
	 * case type unusable with no message saying why. The page hides the button
	 * in that state; this refuses it, because a hidden button is not a rule.
	 *
	 * @param string $caseTypeId The version to close.
	 *
	 * @return array{deprecated: bool, findings: array<int, string>, validUntil: ?string}
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function deprecate(string $caseTypeId): array {
		$caseType = $this->store->readCaseType(caseTypeId: $caseTypeId);

		$refusal = $this->deprecationRefusal(caseType: $caseType);
		if ($refusal !== '') {
			return ['deprecated' => false, 'findings' => [$refusal], 'validUntil' => null];
		}

		$today = $this->today();
		$caseType['validUntil'] = $today;

		if ($this->save(object: $caseType) === false) {
			return ['deprecated' => false, 'findings' => ['The case type could not be saved.'], 'validUntil' => null];
		}

		return ['deprecated' => true, 'findings' => [], 'validUntil' => $today];
	}//end deprecate()

	/**
	 * What stands between this version and being closed, if anything.
	 *
	 * @param array<string, mixed> $caseType The version, or an empty array.
	 *
	 * @return string The finding, or '' when it may be closed.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function deprecationRefusal(array $caseType): string {
		if ($caseType === []) {
			return 'This case type could not be read.';
		}

		if (($caseType['isDraft'] ?? false) === true) {
			return 'A draft is not in use yet, so there is nothing to close. Delete it instead.';
		}

		if ($this->store->referenceId(value: ($caseType['supersededBy'] ?? '')) === '') {
			return 'This is the version new cases are filed under. Publish its successor first.';
		}

		return '';
	}//end deprecationRefusal()

	/**
	 * Write one case type back.
	 *
	 * @param array<string, mixed> $object The case type to save.
	 *
	 * @return boolean True when it was written.
	 */
	private function save(array $object): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			$objectService->saveObject(object: $object, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Case type version window: save failed',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end save()
}//end class
