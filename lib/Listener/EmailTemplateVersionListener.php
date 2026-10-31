<?php

/**
 * Dossiq email template version listener.
 *
 * An applicant message template keeps every earlier wording. A change to its
 * name, subject or body keeps the earlier wording as an inactive copy and
 * moves the template itself to the next version, pointing at that copy. The
 * listener subscribes to OpenRegister's pre-persist object events, so the
 * history is kept on every write path: the generic object API behind the
 * case type's Messages list as much as the email template endpoints.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Versions an email template on every write path.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class EmailTemplateVersionListener implements IEventListener {

	/**
	 * The schema slug of an email template, matched when no config names it.
	 *
	 * @var string
	 */
	public const GUARDED_SCHEMA_SLUG = 'emailTemplate';

	/**
	 * The fields whose change makes a new version.
	 *
	 * @var array<int, string>
	 */
	public const WORDING = ['name', 'subject', 'body'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Config values.
	 * @param EmailTemplateRepository $repository      Saves the copy of the earlier wording.
	 * @param IL10N                   $l10n            Words the refusal.
	 * @param LoggerInterface         $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EmailTemplateRepository $repository,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Start a new template at version 1, and version a changed one.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->onCreate(event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->onUpdate(event: $event);
		}
	}//end handle()

	/**
	 * A new template without a version starts at 1 and active.
	 *
	 * @param ObjectCreatingEvent $event The event.
	 *
	 * @return void
	 */
	private function onCreate(ObjectCreatingEvent $event): void {
		$entity = $event->getObject();
		if ($this->isEmailTemplate(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$object = (array)($entity->getObject() ?? []);
		$defaults = [];
		if (isset($object['version']) === false) {
			$defaults['version'] = 1;
		}

		if (isset($object['isActive']) === false) {
			$defaults['isActive'] = true;
		}

		if ($defaults !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $defaults));
		}
	}//end onCreate()

	/**
	 * A changed wording keeps the earlier one as an inactive copy and moves
	 * the template to the next version.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 *
	 * @return void
	 */
	private function onUpdate(ObjectUpdatingEvent $event): void {
		$entity = $event->getNewObject();
		$old = $event->getOldObject();
		if ($old === null || $this->isEmailTemplate(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$before = (array)($old->getObject() ?? []);
		$after = (array)($entity->getObject() ?? []);
		if (($after['isActive'] ?? true) === false || self::wordingChanged(before: $before, after: $after) === false) {
			return;
		}

		$copy = $before;
		unset($copy['id'], $copy['uuid'], $copy['@self']);
		$copy['isActive'] = false;
		$copy['version'] = (int)($before['version'] ?? 1);

		try {
			$saved = $this->repository->saveTemplate(payload: $copy);
		} catch (Throwable $e) {
			$event->setErrors([
				'error'   => 'email-template-version-not-kept',
				'message' => $this->l10n->t('The earlier wording of this message could not be kept, so the change was not saved. Try again later.'),
			]);
			$event->stopPropagation();
			$this->logger->warning(
				'Dossiq: could not keep the earlier wording of an email template',
				['template' => $old->getUuid(), 'error' => $e->getMessage()]
			);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), [
			'version'         => ($copy['version'] + 1),
			'isActive'        => true,
			'previousVersion' => (string)($saved['id'] ?? ($saved['@self']['id'] ?? '')),
		]));
	}//end onUpdate()

	/**
	 * Whether the name, subject or body differs between two states.
	 *
	 * @param array<string, mixed> $before The stored state.
	 * @param array<string, mixed> $after  The state being saved.
	 *
	 * @return bool True when the wording changed.
	 */
	public static function wordingChanged(array $before, array $after): bool {
		foreach (self::WORDING as $field) {
			if (array_key_exists($field, $after) === true && ($after[$field] ?? null) !== ($before[$field] ?? null)) {
				return true;
			}
		}

		return false;
	}//end wordingChanged()

	/**
	 * Whether a schema reference names the email template schema.
	 *
	 * @param string $schema The entity's schema id or slug.
	 *
	 * @return bool True for the email template schema.
	 */
	private function isEmailTemplate(string $schema): bool {
		if ($schema === '') {
			return false;
		}

		foreach ([self::GUARDED_SCHEMA_SLUG, $this->settingsService->getConfigValue('email_template_schema')] as $name) {
			if ($name !== '' && ($schema === $name || str_ends_with($schema, '/' . $name) === true)) {
				return true;
			}
		}

		return false;
	}//end isEmailTemplate()
}//end class
