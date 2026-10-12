<?php

/**
 * The published forms of any app that open a case of one case type, judged against that type.
 *
 * A form lives in the app that owns it (portaliq, dossiq's own Forms binding). OpenRegister asks every app for the forms that write into a schema; this keeps the ones whose fixed case type is the one being saved and judges each against the case schema and the type's intake requirements.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Judge the forms bound to a case type, and apply the outcome.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */
class BoundFormsCheck {

	/**
	 * The event OpenRegister asks the owning apps for their forms with.
	 *
	 * @var string
	 */
	public const DEPENDENTS_EVENT = 'OCA\\OpenRegister\\Event\\FormDestinationDependentsEvent';

	/**
	 * OpenRegister's service that unpublishes a broken form and tells its author.
	 *
	 * @var string
	 */
	public const DEPENDENTS_CHECK = 'OCA\\OpenRegister\\Service\\Form\\FormDependentsCheck';

	/**
	 * The form an owning app announces.
	 *
	 * @var string
	 */
	public const FORM_DEPENDENT = 'OCA\\OpenRegister\\Service\\Form\\FormDependent';

	/**
	 * The owning app's setting: `unpublish` (default) or `refuse`. OpenRegister's own key.
	 *
	 * @var string
	 */
	public const BREAK_SETTING = 'formDestinationBreak';

	/**
	 * The rule a refused case type save names.
	 *
	 * @var string
	 */
	public const RULE = 'bound-form-invalid';

	/**
	 * How many case types one read lists.
	 *
	 * @var int
	 */
	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IEventDispatcher       $dispatcher      Asks the owning apps for their forms.
	 * @param IAppConfig             $appConfig       Reads each owning app's break setting.
	 * @param IntakeFormBindingCheck $check           Judges one mapping against the case schema and the intake requirements.
	 * @param IntakeFormBinding      $binding         Reads a case type's own Forms binding.
	 * @param LoggerInterface        $logger          Logger.
	 * @param CaseFieldWriter        $writer          Writes one field of a stored object.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly IntakeFormBindingCheck $check,
		private readonly IntakeFormBinding $binding,
		private readonly LoggerInterface $logger,
		private readonly CaseFieldWriter $writer = new CaseFieldWriter(),
	) {
	}//end __construct()

	/**
	 * Whether a stored object is a case type.
	 *
	 * @param array<string, mixed> $object The object payload, `@self` included.
	 *
	 * @return bool True when it belongs to the case type schema.
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function isCaseType(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_type_schema');
		$self = ($object['@self'] ?? []);
		$candidate = '';
		if (is_array($self) === true) {
			$candidate = (string)($self['schema'] ?? '');
		}

		if ($candidate === '') {
			$candidate = (string)($object['schema'] ?? '');
		}

		return ($expected !== '' && $candidate !== '' && ($candidate === $expected || str_ends_with($candidate, '/' . $expected) === true));
	}//end isCaseType()

	/**
	 * Judge every published form of another app that opens a case of this type.
	 *
	 * A draft case type is still being put together and is not judged. The
	 * case type's own Forms binding is part of the case type and is refused by
	 * the binding check itself, so it is left out here.
	 *
	 * @param array<string, mixed> $caseType The case type as it is being saved.
	 *
	 * @return array<string, mixed> OpenRegister's assessment shape: `refused`, `schema` and `affected` (id, app, title, break, findings, form).
	 *
	 * @psalm-return array{refused: bool, schema: string, affected: list<array<string, mixed>>}
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function assess(array $caseType): array {
		$assessment = ['refused' => false, 'schema' => '', 'affected' => []];
		if (($caseType['isDraft'] ?? false) === true) {
			return $assessment;
		}

		$schema = $this->check->caseSchema();
		$eventClass = self::DEPENDENTS_EVENT;
		if ($schema === null || class_exists($eventClass) === false) {
			// An OpenRegister without the form destination check has no forms to ask for.
			return $assessment;
		}

		$event = new $eventClass(schema: $schema);
		$this->dispatcher->dispatchTyped($event);

		if (method_exists($schema, 'getSlug') === true) {
			$assessment['schema'] = (string)$schema->getSlug();
		}

		$names = $this->namesOf(caseType: $caseType);
		foreach ($event->getForms() as $form) {
			$mapping = $form->mapping;
			$fixed = ($mapping['fixed'] ?? null);
			if ($form->app === Application::APP_ID || is_array($fixed) === false) {
				continue;
			}

			if (in_array(trim((string)($fixed['caseType'] ?? '')), $names, true) === false) {
				continue;
			}

			$findings = $this->check->findingsOfMapping(caseType: $caseType, mapping: $mapping, audience: $form->audience);
			if ($findings === []) {
				continue;
			}

			$break = 'unpublish';
			if ($this->appConfig->getValueString($form->app, self::BREAK_SETTING, 'unpublish') === 'refuse') {
				$break = 'refuse';
				$assessment['refused'] = true;
			}

			$assessment['affected'][] = [
				'id' => $form->id,
				'app' => $form->app,
				'title' => $form->title,
				'break' => $break,
				'findings' => $findings,
				'form' => $form,
			];
		}//end foreach

		return $assessment;
	}//end assess()

	/**
	 * After the case type is stored: unpublish each broken form and tell its author.
	 *
	 * OpenRegister does both, through the closure the owning app gave. An
	 * OpenRegister without that service leaves the forms as they are, and
	 * that is logged as an error: the forms then open no case.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return array<int, array<string, mixed>> Each affected form with its outcome (id, app, title, outcome, findings).
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function apply(array $assessment): array {
		if (($assessment['affected'] ?? []) === []) {
			return [];
		}

		$dependents = $this->settingsService->getOpenRegisterClass(self::DEPENDENTS_CHECK);
		if ($dependents === null || method_exists($dependents, 'apply') === false) {
			$this->logger->error(
				'Dossiq intake: published forms no longer fit their case type and OpenRegister cannot unpublish them',
				['forms' => array_column($assessment['affected'], 'id')]
			);

			return [];
		}

		$outcomes = $dependents->apply(assessment: $assessment);
		if (is_array($outcomes) === false) {
			return [];
		}

		return array_values($outcomes);
	}//end apply()

	/**
	 * The sentence a refused case type save answers with.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return string The sentence, naming each form and what it lacks.
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function sentenceFor(array $assessment): string {
		$parts = [];
		foreach (($assessment['affected'] ?? []) as $entry) {
			$messages = array_map(
				static fn (array $finding): string => trim((string)($finding['message'] ?? ((string)($finding['property'] ?? '') . ': ' . (string)($finding['code'] ?? '')))),
				array_filter((array)($entry['findings'] ?? []), 'is_array')
			);
			$parts[] = sprintf('"%s" (%s): %s', (string)($entry['title'] ?? ''), (string)($entry['app'] ?? ''), implode(' ', $messages));
		}

		return 'A published form would no longer open a case of this type. ' . implode(' ', $parts);
	}//end sentenceFor()

	/**
	 * The affected forms without the form objects, for a response or a log line.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return array<int, array<string, mixed>> id, app, title, break and findings per form.
	 */
	public function listed(array $assessment): array {
		return array_map(
			static fn (array $entry): array => array_diff_key($entry, ['form' => true]),
			array_values((array)($assessment['affected'] ?? []))
		);
	}//end listed()

	/**
	 * Every published case type that has a Forms form bound to it.
	 *
	 * @return array<int, array<string, mixed>> The case types.
	 *
	 * @throws RuntimeException When the case types cannot be read: a failed read is not "no forms".
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function boundCaseTypes(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The case type register is not configured.');
		}

		$found = $objectService->searchObjects(['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE]);
		if (is_array($found) === false) {
			throw new RuntimeException('The case types could not be listed.');
		}

		if (is_array($found['results'] ?? null) === true) {
			$found = $found['results'];
		}

		if (count($found) >= self::PAGE) {
			$this->logger->warning('Dossiq intake: more case types than one read lists; forms bound to the rest are not judged', ['listed' => self::PAGE]);
		}

		$bound = [];
		foreach ($found as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === false || ($row['isDraft'] ?? false) === true || $this->binding->formRef(caseType: $row) === '') {
				continue;
			}

			$bound[] = $row;
		}

		return $bound;
	}//end boundCaseTypes()

	/**
	 * Take a case type's Forms binding off, so the form opens no more cases.
	 *
	 * The mapping stays on the case type: binding the form again is one field.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the case type cannot be written, so OpenRegister reports the failure.
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function unbind(string $caseTypeId): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseTypeId === '') {
			throw new RuntimeException('The intake form of case type ' . $caseTypeId . ' could not be taken off.');
		}

		$this->writer->write(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			case: ['id' => $caseTypeId],
			changes: [IntakeFormBinding::FORM_REF => '']
		);
	}//end unbind()

	/**
	 * The names a form may use for this case type: its id, uuid and slug.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, string> The names, without empty ones.
	 */
	public function namesOf(array $caseType): array {
		$self = ($caseType['@self'] ?? []);
		$candidates = [($caseType['id'] ?? ''), ($caseType['uuid'] ?? ''), ($caseType['slug'] ?? '')];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? '');
			$candidates[] = ($self['uuid'] ?? '');
			$candidates[] = ($self['slug'] ?? '');
		}

		$names = [];
		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				$names[] = trim((string)$candidate);
			}
		}

		return array_values(array_unique($names));
	}//end namesOf()
}//end class
