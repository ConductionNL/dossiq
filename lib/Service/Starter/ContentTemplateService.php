<?php

/**
 * The template library, once it covers more than documents and mail.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\EmailTemplateService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One library, six kinds, offered where each kind is created.
 *
 * 🔑 A KIND ON ONE RECORD, NOT FOUR SERVICES. GLPI carries a task template, a
 * solution template, a followup template and a validation template as four
 * dropdowns over four tables, and the fourth one is always the one that never
 * got a scope field. Here a template is a `contentTemplate` row with a `kind`,
 * so scoping, searching and permissions are written once.
 *
 * 🔑 MAIL IS THE EXCEPTION, AND IT IS DELIBERATE. `emailTemplate` already holds
 * every mail template, with a copy-on-write version chain
 * {@see EmailTemplateService} maintains and the mail editor reads. Migrating
 * that into a second store would have rewritten a working feature to make a
 * list look tidy, and an instance mid-migration would have had two answers to
 * "which templates do I have". So mail stays where it is and this class reads
 * both, which is what makes it one library rather than two.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
class ContentTemplateService {

	/**
	 * The app config key naming the content template schema.
	 */
	public const TEMPLATES = 'content_template_schema';

	/**
	 * A prepared document.
	 */
	public const KIND_DOCUMENT = 'document';

	/**
	 * A prepared mail. Held in `emailTemplate`, read through here.
	 */
	public const KIND_MAIL = 'mail';

	/**
	 * A prepared task.
	 */
	public const KIND_TASK = 'task';

	/**
	 * A prepared note.
	 */
	public const KIND_NOTE = 'note';

	/**
	 * A prepared request for approval.
	 */
	public const KIND_APPROVAL = 'approval';

	/**
	 * A prepared outcome text.
	 */
	public const KIND_RESULT = 'result';

	/**
	 * Every kind the library holds.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [
		self::KIND_DOCUMENT,
		self::KIND_MAIL,
		self::KIND_TASK,
		self::KIND_NOTE,
		self::KIND_APPROVAL,
		self::KIND_RESULT,
	];

	/**
	 * Constructor.
	 *
	 * @param StarterStore         $store  The OpenRegister seam.
	 * @param EmailTemplateService $mail   The mail templates, which keep their own store.
	 * @param LoggerInterface      $logger Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly EmailTemplateService $mail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The templates of one kind that this case type may use.
	 *
	 * A template scoped to no case type is offered on every case type. A
	 * template scoped to some is offered only on those, which is what keeps a
	 * bezwaar note template off a vergunning case.
	 *
	 * @param string $kind       The kind, one of {@see self::KINDS}.
	 * @param string $caseTypeId The case type the handler is working on.
	 * @param string $search     Part of the name, or '' for all of them.
	 *
	 * @return array<int, array<string, mixed>>|null The templates, or null when
	 *                                               the store is unreachable.
	 */
	public function offered(string $kind, string $caseTypeId, string $search = ''): ?array {
		if (in_array($kind, self::KINDS, true) === false) {
			return [];
		}

		if ($kind === self::KIND_MAIL) {
			return $this->mailTemplates(caseTypeId: $caseTypeId, search: $search);
		}

		$rows = $this->store->rows(configKey: self::TEMPLATES, filters: ['kind' => $kind]);
		if ($rows === null) {
			return null;
		}

		$offered = [];
		foreach ($rows as $row) {
			if ($this->inScope(row: $row, caseTypeId: $caseTypeId) === false) {
				continue;
			}

			$name = (string)($row['name'] ?? '');
			if ($search !== '' && stripos($name, $search) === false) {
				continue;
			}

			$offered[] = [
				'id' => $this->store->idOf(row: $row),
				'kind' => $kind,
				'name' => $name,
				'body' => (string)($row['body'] ?? ''),
				'presets' => $this->presets(row: $row),
			];
		}//end foreach

		return $offered;
	}//end offered()

	/**
	 * The fields one template presets, ready to drop into the dialog that
	 * creates the thing.
	 *
	 * @param string $templateId The template's id.
	 *
	 * @return array<string, mixed>|null The presets, or null when there is no
	 *                                   such template.
	 */
	public function apply(string $templateId): ?array {
		$row = $this->store->row(configKey: self::TEMPLATES, id: $templateId);
		if ($row === null) {
			return null;
		}

		$presets = $this->presets(row: $row);
		$body = (string)($row['body'] ?? '');
		if ($body !== '') {
			$presets['body'] = $body;
		}

		return $presets;
	}//end apply()

	/**
	 * Whether a template may be used on a case type.
	 *
	 * @param array<string, mixed> $row        The template row.
	 * @param string               $caseTypeId The case type.
	 *
	 * @return boolean True when the template is offered there.
	 */
	private function inScope(array $row, string $caseTypeId): bool {
		$scope = ($row['caseTypes'] ?? []);
		if (is_array($scope) === false || $scope === []) {
			return true;
		}

		if ($caseTypeId === '') {
			return false;
		}

		foreach ($scope as $id) {
			if ((string)$id === $caseTypeId) {
				return true;
			}
		}

		return false;
	}//end inScope()

	/**
	 * The preset block of a template, always an array.
	 *
	 * @param array<string, mixed> $row The template row.
	 *
	 * @return array<string, mixed> The presets.
	 */
	private function presets(array $row): array {
		$presets = ($row['presets'] ?? []);
		if (is_array($presets) === false) {
			return [];
		}

		return $presets;
	}//end presets()

	/**
	 * The mail templates, in the shape the rest of the library answers in.
	 *
	 * @param string $caseTypeId The case type the handler is working on.
	 * @param string $search     Part of the name, or '' for all of them.
	 *
	 * @return array<int, array<string, mixed>>|null The templates.
	 */
	private function mailTemplates(string $caseTypeId, string $search): ?array {
		try {
			$rows = $this->mail->listTemplates(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq starter: could not list mail templates',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$offered = [];
		foreach ($rows as $row) {
			$name = (string)($row['name'] ?? '');
			if ($search !== '' && stripos($name, $search) === false) {
				continue;
			}

			$offered[] = [
				'id' => (string)($row['id'] ?? ''),
				'kind' => self::KIND_MAIL,
				'name' => $name,
				'body' => (string)($row['body'] ?? ''),
				'presets' => ['subject' => (string)($row['subject'] ?? '')],
			];
		}

		return $offered;
	}//end mailTemplates()
}//end class
