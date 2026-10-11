<?php

/**
 * The curated tool hermiq's conversational intake calls to file a case.
 *
 * One tool, and the only one dossiq marks for intake. hermiq recognises an
 * intake tool by three things together (decision 177): the `citizenIntake`
 * mark in the tool's annotations, `scope: create` and `action: create`. A tool
 * that reads or changes an existing case carries none of them.
 *
 * The argument names are hermiq's: it calls every intake tool with `type`,
 * `subject`, `person` and `messages`. The filing itself is
 * {@see CaseIntakeFiling}, which writes through the create form's own path.
 *
 * Needs OpenRegister with `reach` (openregister#4546) and `annotations`
 * (openregister build/dep-dossiq-mcptool-annotations) on `#[McpTool]`. Against
 * an older OpenRegister the attribute does not instantiate and the scanner
 * skips this tool: it is then absent, never wrongly marked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\CaseIntakeFiling;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use RuntimeException;

/**
 * File a case from an intake conversation.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */
class IntakeTools {

	/**
	 * The mark hermiq's intake grant reads (`IntakeToolGrant::INTAKE_ANNOTATION`).
	 *
	 * @var string
	 */
	public const INTAKE_MARK = 'citizenIntake';

	/**
	 * Constructor.
	 *
	 * @param CaseIntakeFiling $filing Writes the case through the create form's own path.
	 */
	public function __construct(
		private readonly CaseIntakeFiling $filing,
	) {
	}//end __construct()

	/**
	 * File a case for a resident who described their request in a conversation.
	 *
	 * A refusal is thrown, not returned: OpenRegister audits the invocation as
	 * failed and hands hermiq an error, which hermiq turns into a handover to a
	 * person. A returned error envelope would be audited and read as a filing.
	 *
	 * @param string                           $type     The request type: a case type id or identifier.
	 * @param string                           $subject  What the request is about.
	 * @param string                           $person   Who asked.
	 * @param array<int, array<string, mixed>> $messages The conversation, oldest first.
	 *
	 * @return array<string, mixed> The filed case's id, identifier and title.
	 *
	 * @throws RuntimeException With dossiq's own sentence when the create path refuses.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-intake-tool-creates-through-the-existing-path
	 */
	#[McpTool(
		name: 'fileCase',
		description: 'File a case for a resident from an intake conversation, through the create form\'s own path and checks. '
			. 'Creates only; a refusal names what is missing.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'create',
		subject: 'case',
		action: 'create',
		reach: 'instance',
		annotations: [self::INTAKE_MARK => true]
	)]
	public function fileCase(string $type, string $subject, string $person = '', array $messages = []): array {
		try {
			$case = $this->filing->file(type: $type, subject: $subject, person: $person, messages: $messages);
		} catch (RefusedException $e) {
			throw new RuntimeException(message: $e->getSentence(), previous: $e);
		}

		return [
			'id' => (string)($case['id'] ?? ($case['uuid'] ?? '')),
			'identifier' => (string)($case['identifier'] ?? ''),
			'title' => (string)($case['title'] ?? ''),
		];
	}//end fileCase()
}//end class
