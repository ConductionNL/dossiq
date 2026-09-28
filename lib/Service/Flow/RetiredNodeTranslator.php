<?php

/**
 * Turns the configuration of a retired dossiq step into the steps that replace it.
 *
 * Dossiq's own mail, notification, field, decision and document steps are
 * gone because their owners now provide them. A stored flow still names the
 * old type with the old configuration keys, so renaming the type is not
 * enough: the keys, the template syntax and sometimes the number of steps
 * differ. This class holds one translation per retired type.
 *
 * WHAT IT RETURNS is a list of steps (type + config) that together do what
 * the one retired step did. Most translations are one step. Two are two:
 * writing a field and evaluating a decision table both changed the STORED
 * case, and OpenRegister splits "compute onto the item" from "write the
 * object", so the translation is a compute step followed by an object write.
 *
 * WHAT IT REFUSES it refuses by throwing {@see UnmappableStep} with a reason,
 * never by producing a step that would do something else. The caller logs
 * the reason and leaves the step in place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\Dossiq\Service\Dmn\DecisionTableService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * One translation per retired step type.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class RetiredNodeTranslator {

	/**
	 * OpenRegister's send-email step.
	 *
	 * @var string
	 */
	public const SEND_EMAIL = 'openregister.send-email';

	/**
	 * OpenRegister's send-notification step.
	 *
	 * @var string
	 */
	public const SEND_NOTIFICATION = 'openregister.send-notification';

	/**
	 * OpenRegister's object-write step.
	 *
	 * @var string
	 */
	public const OBJECT_WRITE = 'openregister.object-write';

	/**
	 * OpenRegister's set-fields step.
	 *
	 * @var string
	 */
	public const SET_FIELDS = 'openregister.set-fields';

	/**
	 * OpenRegister's decision-table step.
	 *
	 * @var string
	 */
	public const DECISION_TABLE = 'openregister.decision-table';

	/**
	 * The mode of the send-email step's external-recipient allowlist that
	 * mails an address only when the item itself holds it.
	 *
	 * @var string
	 */
	public const EXTERNAL_OBJECT = 'object';

	/**
	 * The value dossiq's setField replaced with the current time.
	 *
	 * @var string
	 */
	public const NOW_MACRO = '__now__';

	/**
	 * Where the retired steps put their result on the item, and where the
	 * object write now puts its own.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT = 'actionResult';

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService The configured register and case schema.
	 * @param ContainerInterface    $container       Resolves the decision-table store, which needs OpenRegister.
	 * @param IL10N                 $l10n            Wording for a notification the old step left to dossiq's notifier.
	 * @param RetiredTemplateSyntax $syntax          Template rewrites.
	 * @param RetiredDocumentSteps  $documents       The document translations.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly RetiredTemplateSyntax $syntax,
		private readonly RetiredDocumentSteps $documents,
	) {
	}//end __construct()

	/**
	 * Translate one retired step's configuration.
	 *
	 * @param string               $translation The row's translation key.
	 * @param array<string, mixed> $config      The retired step's configuration.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The replacing steps, in order.
	 *
	 * @throws UnmappableStep When the configuration has no faithful equivalent.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function translate(string $translation, array $config): array {
		return match ($translation) {
			RetiredNodeMap::EMAIL_ACTION => [$this->emailFromAction(config: $config)],
			RetiredNodeMap::EMAIL_TRANSITION => [$this->emailFromTransition(config: $config)],
			RetiredNodeMap::NOTIFY_ROLE => [$this->notifyRole(config: $config)],
			RetiredNodeMap::NOTIFY_TRANSITION => [$this->notifyTransition(config: $config)],
			RetiredNodeMap::SET_FIELD => $this->setField(config: $config),
			RetiredNodeMap::DECISION => $this->decision(config: $config),
			RetiredNodeMap::DOCUMENT_CREATE => [$this->documents->create(config: $config)],
			RetiredNodeMap::DOCUMENT_MERGE => [$this->documents->merge(config: $config)],
			RetiredNodeMap::WEBHOOK => throw $this->webhook(config: $config),
			default => throw new UnmappableStep(message: 'no translation is known for "' . $translation . '"'),
		};
	}//end translate()

	/**
	 * `dossiq.action.sendEmail`: a templated mail to a role or a literal address.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The send-email step.
	 */
	private function emailFromAction(array $config): array {
		$recipient = $this->recipientFromRef(ref: trim((string)($config['recipientRef'] ?? '')));
		$body = $this->syntax->forMessaging(template: (string)($config['bodyTemplate'] ?? ''), what: 'body');
		if (trim($body) === '') {
			throw new UnmappableStep(message: 'the step has no body, which the send-email step refuses');
		}

		return [
			'type' => self::SEND_EMAIL,
			'config' => [
				'recipients' => [$recipient],
				'externalRecipients' => self::EXTERNAL_OBJECT,
				'subject' => $this->syntax->forMessaging(template: (string)($config['subjectTemplate'] ?? ''), what: 'subject'),
				'body' => $body,
			],
		];
	}//end emailFromAction()

	/**
	 * A catalogue recipient reference as a send-email recipient entry.
	 *
	 * `email:<address>` was a literal address; anything else named a case
	 * field whose value (or whose `email`) was the address.
	 *
	 * @param string $ref The reference.
	 *
	 * @return string The recipient entry.
	 *
	 * @throws UnmappableStep When there is no reference.
	 */
	private function recipientFromRef(string $ref): string {
		if ($ref === '') {
			throw new UnmappableStep(message: 'the step names no recipient');
		}

		if (str_starts_with($ref, 'email:') === true) {
			return substr($ref, 6);
		}

		if (preg_match('/^[a-zA-Z0-9_-]+$/', $ref) !== 1) {
			throw new UnmappableStep(message: 'the recipient "' . $ref . '" is not a field name the send-email step can read');
		}

		return '{{ ' . $ref . ' }}';
	}//end recipientFromRef()

	/**
	 * `dossiq.sendEmail`: a transition's mail to one literal address.
	 *
	 * The transition sent its `subject` and `body` as written, so they are
	 * carried unchanged. A step that named a stored email template cannot be
	 * carried: the send-email step has no template reference.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The send-email step.
	 */
	private function emailFromTransition(array $config): array {
		$template = trim((string)($config['template'] ?? ''));
		if ($template !== '') {
			throw new UnmappableStep(message: 
				'the step sends the stored email template "' . $template . '", and the send-email step has no template reference'
			);
		}

		$recipient = trim((string)($config['to'] ?? ($config['recipient'] ?? '')));
		if ($recipient === '') {
			throw new UnmappableStep(message: 'the step names no recipient');
		}

		$body = (string)($config['body'] ?? '');
		if (trim($body) === '') {
			throw new UnmappableStep(message: 'the step has no body, which the send-email step refuses');
		}

		return [
			'type' => self::SEND_EMAIL,
			'config' => [
				'recipients' => [$recipient],
				'externalRecipients' => self::EXTERNAL_OBJECT,
				'subject' => (string)($config['subject'] ?? ''),
				'body' => $body,
			],
		];
	}//end emailFromTransition()

	/**
	 * `dossiq.action.notifyRole`: tell everyone who holds a role on the case.
	 *
	 * The role was read from `case.<role>` and `case.<role>Members`; both
	 * become field references. dossiq's notifier worded an empty message
	 * itself, so that wording is written into the step.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The send-notification step.
	 */
	private function notifyRole(array $config): array {
		$role = trim((string)($config['roleSlug'] ?? ''));
		if ($role === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $role) !== 1) {
			throw new UnmappableStep(message: 'the step names no role field the send-notification step can read');
		}

		$message = trim($this->syntax->forMessaging(template: (string)($config['messageTemplate'] ?? ''), what: 'message'));
		if ($message === '') {
			$message = $this->l10n->t('Open the case to see what to do next.');
		}

		return [
			'type' => self::SEND_NOTIFICATION,
			'config' => [
				'recipients' => ['{{ ' . $role . ' }}', '{{ ' . $role . 'Members }}'],
				'title' => $this->l10n->t('A case you handle needs your attention'),
				'message' => $message,
			],
		];
	}//end notifyRole()

	/**
	 * `dossiq.notify`: tell one user, or the case's assignee, about a transition.
	 *
	 * @param array<string, mixed> $config The retired configuration; a transition
	 *                                     may add `transitionLabel`.
	 *
	 * @return array{type: string, config: array<string, mixed>} The send-notification step.
	 */
	private function notifyTransition(array $config): array {
		$recipient = trim((string)($config['userId'] ?? ($config['recipient'] ?? '')));
		if ($recipient === '') {
			$recipient = '{{ assignee }}';
		}

		$title = $this->l10n->t('A case you handle changed status');
		$label = trim((string)($config['transitionLabel'] ?? ''));
		if ($label !== '') {
			$title = $this->l10n->t('A case you handle changed status: %s', [$label]);
		}

		$message = trim((string)($config['message'] ?? ''));
		if ($message === '') {
			$message = $this->l10n->t('Open the case to see what changed.');
		}

		return [
			'type' => self::SEND_NOTIFICATION,
			'config' => [
				'recipients' => [$recipient],
				'title' => $title,
				'message' => $message,
			],
		];
	}//end notifyTransition()

	/**
	 * `dossiq.setField`: write one value onto the stored case.
	 *
	 * Two steps. The value goes onto the item first, so the steps after this
	 * one see it as they did, and then only that field is written to the
	 * stored case. `__now__` becomes OpenRegister's `now` expression.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The steps.
	 */
	private function setField(array $config): array {
		$field = trim((string)($config['field'] ?? ''));
		if ($field === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $field) !== 1) {
			throw new UnmappableStep(message: 'the step names no field that can be written as one property');
		}

		$value = ($config['value'] ?? null);
		$compute = ['set' => [$field => $value]];
		if ($value === self::NOW_MACRO) {
			$compute = ['compute' => [$field => ['now' => []]]];
		}

		if (is_string($value) === true && $value !== self::NOW_MACRO && str_contains($value, '{{') === true) {
			throw new UnmappableStep(message: 'the value holds "{{", which dossiq wrote as text and OpenRegister would read as a placeholder');
		}

		return [
			['type' => self::SET_FIELDS, 'config' => $compute],
			$this->caseWrite(fields: [$field], output: (string)($config['output'] ?? '')),
		];
	}//end setField()

	/**
	 * `dossiq.evaluateDecision`: decide by a stored table and write the outputs onto the case.
	 *
	 * OpenRegister's decision-table step carries the table itself, so the
	 * table the step named by key is read now and placed in the step. The
	 * outputs land on the item; the object write then stores them.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The steps.
	 */
	private function decision(array $config): array {
		$key = trim((string)($config['decisionKey'] ?? ''));
		if ($key === '') {
			throw new UnmappableStep(message: 'the step names no decision table');
		}

		$table = $this->decisionTable(key: $key);
		$outputMapping = (array)($config['outputMapping'] ?? []);
		$fields = [];
		foreach ((array)($table['outputs'] ?? []) as $output) {
			$name = trim((string)(((array)$output)['name'] ?? ''));
			if ($name !== '') {
				$fields[] = (string)($outputMapping[$name] ?? $name);
			}
		}

		if ($fields === []) {
			throw new UnmappableStep(message: 'the decision table "' . $key . '" declares no outputs to write');
		}

		$step = ['table' => $table];
		foreach (['inputMapping', 'outputMapping'] as $mapping) {
			if (is_array($config[$mapping] ?? null) === true && $config[$mapping] !== []) {
				$step[$mapping] = $config[$mapping];
			}
		}

		return [
			['type' => self::DECISION_TABLE, 'config' => $step],
			$this->caseWrite(fields: $fields, output: (string)($config['output'] ?? '')),
		];
	}//end decision()

	/**
	 * Read a stored decision table by key, without its storage metadata.
	 *
	 * @param string $key The table key.
	 *
	 * @return array<string, mixed> The table definition.
	 *
	 * @throws UnmappableStep When the table cannot be read.
	 */
	private function decisionTable(string $key): array {
		try {
			$service = $this->container->get(DecisionTableService::class);
			$table = null;
			if ($service instanceof DecisionTableService) {
				$table = $service->findByKey(key: $key);
			}
		} catch (Throwable $e) {
			throw new UnmappableStep(message: 'the decision table "' . $key . '" could not be read: ' . $e->getMessage());
		}

		if (is_array($table) === false || $table === []) {
			throw new UnmappableStep(message: 'no decision table has the key "' . $key . '"');
		}

		unset($table['@self'], $table['id'], $table['uuid']);

		return $table;
	}//end decisionTable()

	/**
	 * An object write that stores the given item fields onto the stored case, and only those.
	 *
	 * @param array<int, string> $fields The fields to store.
	 * @param string             $output Where the write's result goes on the item.
	 *
	 * @return array{type: string, config: array<string, mixed>} The object-write step.
	 *
	 * @throws UnmappableStep When dossiq's register or case schema is not configured.
	 */
	private function caseWrite(array $fields, string $output): array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $schema === '') {
			throw new UnmappableStep(message: 'dossiq has no register or case schema configured to write the case to');
		}

		$map = [];
		foreach ($fields as $field) {
			$map[$field] = '{{ ' . $field . ' }}';
		}

		if (trim($output) === '') {
			$output = self::DEFAULT_OUTPUT;
		}

		return [
			'type' => self::OBJECT_WRITE,
			'config' => [
				'register' => $register,
				'schema' => $schema,
				'operation' => 'update',
				'match' => ['@self.uuid' => '{{ id }}'],
				'fields' => $map,
				'output' => $output,
			],
		];
	}//end caseWrite()

	/**
	 * `dossiq.webhook` and `dossiq.action.callWebhook`: a call to a raw URL.
	 *
	 * Integriq owns outbound calls, and each of its steps goes through a
	 * configured source or a subscription. None takes a URL, so there is no
	 * step to become; the reason names the host so an administrator can set
	 * up the source.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return UnmappableStep The refusal to throw.
	 */
	private function webhook(array $config): UnmappableStep {
		$url = trim((string)($config['url'] ?? ''));
		$host = '';
		if ($url !== '') {
			$host = (string)parse_url($url, PHP_URL_HOST);
		}

		if ($host === '') {
			$host = 'an unnamed host';
		}

		return new UnmappableStep(message: 
			'it calls ' . $host . ' directly, and every Integriq step needs a configured source or subscription instead of a URL'
		);
	}//end webhook()
}//end class
