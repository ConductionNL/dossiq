<?php

/**
 * The one place a case type's handling switches are read.
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * What happens to a case of this type: the group, the handler, the messages
 * and the intake screen.
 *
 * 🔑 THE BEHAVIOUR ALREADY EXISTED AND WAS SCATTERED, WHICH IS WHY THIS CLASS
 * IS A READER AND NOT A NEW FEATURE. Measured against `development` before this
 * change: the group a case landed in came from `refusalDestination`
 * (Routing\RefusalOutcome), `intakeDestinations[].department`
 * (Intake\IntakeFanOut) and the case's own `assignedGroup` (AssigneeResolver);
 * the handler came from `defaultAssignee`, read separately in
 * ZgwZrcRulesService and Email\UnmatchedMailIntake; the acknowledgement mail
 * was decided in CaseTypeAcknowledgement and the termijn mails in
 * TermijnNotificationService, from a hardcoded list. An administrator changing
 * the handling group therefore had to find four screens, and one of the four
 * kept the old answer.
 *
 * 🔑 IT FALLS BACK TO THE LEGACY PROPERTY, DELIBERATELY. `defaultAssignee` is
 * also read declaratively by OpenRegister's `x-openregister-prefill` block,
 * which cannot address a nested property. Moving the value would have emptied
 * the assignee prefill on every existing instance and told nobody. So the
 * block is the authoring surface and the legacy property is what an
 * un-migrated case type still answers with. Every caller reads this class, so
 * there is still exactly one reader.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class CaseTypeHandling {

	/**
	 * The property holding the declared block.
	 */
	public const PROPERTY = 'handling';

	/**
	 * Every switch this class reads.
	 *
	 * A switch declared on a case type and absent from this list is read by
	 * nothing, and {@see \OCA\Dossiq\Service\CaseTypePublishService} refuses to
	 * publish it. A switch that nothing reads is a promise the product does not
	 * keep, and it is invisible until somebody relies on it.
	 *
	 * @var array<int, string>
	 */
	public const READ_SWITCHES = [
		'defaultGroup',
		'defaultHandler',
		'automaticMessages',
		'intakeScreen',
	];

	/**
	 * The messages that may be named in `automaticMessages`.
	 *
	 * Mirrors {@see \OCA\Dossiq\Service\TermijnNotificationService::TEMPLATES},
	 * which is the sender, and the fragment's enum, which is the authoring
	 * surface. Three copies of one vocabulary is one too many, so the test
	 * asserts the two PHP lists are equal rather than trusting they are.
	 *
	 * @var array<int, string>
	 */
	public const MESSAGES = [
		'ontvangstbevestiging',
		'extension',
		'ingebrekestelling-receipt',
		'dwangsom-payment',
		'hersteltermijn-request',
		'doorzending',
	];

	/**
	 * The declared block of a case type, with the legacy fallbacks applied.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array{defaultGroup: string, defaultHandler: string, automaticMessages: array<int, string>, intakeScreen: string}
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function block(array $caseType): array {
		return [
			'defaultGroup' => $this->defaultGroup(caseType: $caseType),
			'defaultHandler' => $this->defaultHandler(caseType: $caseType),
			'automaticMessages' => $this->automaticMessages(caseType: $caseType),
			'intakeScreen' => $this->intakeScreen(caseType: $caseType),
		];
	}//end block()

	/**
	 * The group a new case of this type goes to.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The group id, or '' when the type names none.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function defaultGroup(array $caseType): string {
		$declared = $this->switchValue(caseType: $caseType, name: 'defaultGroup');
		if (is_string($declared) === true && $declared !== '') {
			return $declared;
		}

		// The intake fan-out's first destination is the group an un-migrated
		// case type already routed to, so reading it keeps the answer the same
		// for every instance that has not filled the block in.
		$destinations = ($caseType['intakeDestinations'] ?? []);
		if (is_array($destinations) === true) {
			foreach ($destinations as $destination) {
				if (is_array($destination) === false) {
					continue;
				}

				$department = (string)($destination['department'] ?? '');
				if ($department !== '') {
					return $department;
				}
			}
		}

		return '';
	}//end defaultGroup()

	/**
	 * The user a new case of this type falls to.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The user id, or '' when the case stays unclaimed.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function defaultHandler(array $caseType): string {
		$declared = $this->switchValue(caseType: $caseType, name: 'defaultHandler');
		if (is_string($declared) === true && $declared !== '') {
			return $declared;
		}

		return (string)($caseType['defaultAssignee'] ?? '');
	}//end defaultHandler()

	/**
	 * The messages that go out by themselves for this case type.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array<int, string> The message names, in declaration order.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function automaticMessages(array $caseType): array {
		$declared = $this->switchValue(caseType: $caseType, name: 'automaticMessages');
		if (is_array($declared) === false) {
			// A type that declares nothing keeps sending what it sent before
			// this change: every message. Defaulting to none would have
			// silenced every acknowledgement on every existing instance on the
			// day this shipped, with the settings screen still showing them.
			return self::MESSAGES;
		}

		$names = [];
		foreach ($declared as $name) {
			if (is_string($name) === true && in_array($name, self::MESSAGES, true) === true) {
				$names[] = $name;
			}
		}

		return $names;
	}//end automaticMessages()

	/**
	 * Whether one named message goes out for this case type.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 * @param string               $message  The message name.
	 *
	 * @return boolean True when the message is sent.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function sends(array $caseType, string $message): bool {
		return in_array($message, $this->automaticMessages(caseType: $caseType), true);
	}//end sends()

	/**
	 * The manifest page a case of this type is registered on.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The page id, or '' for the standard intake screen.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function intakeScreen(array $caseType): string {
		$declared = $this->switchValue(caseType: $caseType, name: 'intakeScreen');
		if (is_string($declared) === true) {
			return $declared;
		}

		return '';
	}//end intakeScreen()

	/**
	 * The switch names a case type declares that nothing reads.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array<int, string> The unread switch names, sorted.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function unreadSwitches(array $caseType): array {
		$block = ($caseType[self::PROPERTY] ?? null);
		if (is_array($block) === false) {
			return [];
		}

		$unread = array_values(array_diff(array_keys($block), self::READ_SWITCHES));
		sort($unread);

		return array_map(static fn (mixed $name): string => (string)$name, $unread);
	}//end unreadSwitches()

	/**
	 * One raw value off the declared block.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 * @param string               $name     The switch name.
	 *
	 * @return mixed The value, or null when the block or the switch is absent.
	 */
	private function switchValue(array $caseType, string $name): mixed {
		$block = ($caseType[self::PROPERTY] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		return ($block[$name] ?? null);
	}//end switchValue()
}//end class
