<?php

/**
 * Dossiq OnboardingStepGuide.
 *
 * What the onboarding checklist says under each step (board DqTenant): when
 * a done step was done, what the next step asks with a link to where it is
 * done, and that a later step waits for the ones before it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Tenant
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
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Tenant;

use OCA\Dossiq\Kernel\Contracts\OnboardingStepGuideInterface;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Adds `isNext`, `note` and, on the next step, `action` to each onboarding step.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */
class OnboardingStepGuide implements OnboardingStepGuideInterface {
	/**
	 * Where each step is done, as an admin settings section; a step without one has no link.
	 *
	 * @var array<string, string>
	 */
	private const SECTIONS = [
		'mandate_import' => 'dossiq',
		'sso_setup' => 'user_saml',
		'branding' => 'theming',
		'zaaktype_selection' => 'dossiq',
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n Translates the notes and the action labels.
	 * @param IURLGenerator $urls Builds the action links.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The progress with its steps in checklist order, each carrying its guidance.
	 *
	 * @param array{steps: array<int, array<string, mixed>>} $progress What {@see TenantOnboardingService::getProgress()} answers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function withGuidance(array $progress): array {
		$byStep = array_column($progress['steps'], null, 'step');
		$ordered = [];
		$nextFound = false;
		foreach (TenantOnboardingService::STEPS as $step) {
			$row = ($byStep[$step] ?? ['step' => $step, 'status' => 'pending']);
			$isNext = ($nextFound === false && $this->isDone(row: $row) === false);
			$ordered[] = $this->guided(row: $row, isNext: $isNext, waiting: $nextFound);
			$nextFound = ($nextFound || $isNext);
		}

		$progress['steps'] = $ordered;

		return $progress;
	}//end withGuidance()

	/**
	 * Whether a step needs nothing more.
	 *
	 * @param array<string, mixed> $row The step.
	 *
	 * @return bool
	 */
	private function isDone(array $row): bool {
		return in_array((string)($row['status'] ?? ''), ['completed', 'skipped'], true);
	}//end isDone()

	/**
	 * One step with its flag, note and action.
	 *
	 * @param array<string, mixed> $row     The step.
	 * @param bool                 $isNext  Whether it is the first step not done.
	 * @param bool                 $waiting Whether an earlier step is still open.
	 *
	 * @return array<string, mixed>
	 */
	private function guided(array $row, bool $isNext, bool $waiting): array {
		$row['isNext'] = $isNext;
		$row['note'] = $this->noteFor(row: $row, isNext: $isNext, waiting: $waiting);
		$row['action'] = null;
		$section = (self::SECTIONS[(string)$row['step']] ?? '');
		if ($isNext === true && $section !== '') {
			$row['action'] = [
				'label' => $this->actionLabel(step: (string)$row['step']),
				'href' => $this->urls->linkToRoute('settings.AdminSettings.index', ['section' => $section]),
			];
		}

		return $row;
	}//end guided()

	/**
	 * The line under a step.
	 *
	 * @param array<string, mixed> $row     The step.
	 * @param bool                 $isNext  Whether it is the next step.
	 * @param bool                 $waiting Whether an earlier step is still open.
	 *
	 * @return string
	 */
	private function noteFor(array $row, bool $isNext, bool $waiting): string {
		$status = (string)($row['status'] ?? '');
		if ($status === 'skipped') {
			return $this->l10n->t('Skipped');
		}

		if ($status === 'completed') {
			$when = date_create_immutable((string)($row['completedAt'] ?? ''));
			if ((string)($row['completedAt'] ?? '') === '' || $when === false) {
				return $this->l10n->t('Done');
			}

			return $this->l10n->t('Done on %s', [(string)$this->l10n->l('date', $when, ['width' => 'medium'])]);
		}

		if ($isNext === true) {
			return $this->l10n->t('Not done yet; %s', [$this->hint(step: (string)$row['step'])]);
		}

		if ($waiting === true) {
			return $this->l10n->t('Waits for the steps before this one');
		}

		return '';
	}//end noteFor()

	/**
	 * What the next step asks, in a few words.
	 *
	 * @param string $step The step.
	 *
	 * @return string
	 */
	private function hint(string $step): string {
		$hints = [
			'contract' => $this->l10n->t('sign the contract'),
			'mandate_import' => $this->l10n->t('import the mandate table'),
			'sso_setup' => $this->l10n->t('link the organisation\'s sign-in (SSO)'),
			'branding' => $this->l10n->t('set the house style'),
			'zaaktype_selection' => $this->l10n->t('choose the case types'),
			'first_user' => $this->l10n->t('add the first user'),
			'go_live' => $this->l10n->t('go live once every step is done'),
		];

		return ($hints[$step] ?? $step);
	}//end hint()

	/**
	 * The label of the link to where a step is done.
	 *
	 * @param string $step The step.
	 *
	 * @return string
	 */
	private function actionLabel(string $step): string {
		$labels = [
			'mandate_import' => $this->l10n->t('Import mandates'),
			'sso_setup' => $this->l10n->t('Set up sign-in'),
			'branding' => $this->l10n->t('Set the house style'),
			'zaaktype_selection' => $this->l10n->t('Choose case types'),
		];

		return ($labels[$step] ?? '');
	}//end actionLabel()
}//end class
