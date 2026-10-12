<?php

/**
 * A case type save reaches the intake form check, from the registration on.
 *
 * The check has its own unit tests. This one starts at the registrar: the
 * listener the registrar names for the pre-persist events is the one that is
 * built and handed a case type save, so a check nobody registered fails here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar;
use OCA\Dossiq\Listener\IntakeFormBindingListener;
use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Service\Intake\IntakeFormBindingCheck;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The registered listener refuses a case type whose intake form the case schema refuses.
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar
 * @covers \OCA\Dossiq\Listener\IntakeFormBindingListener
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBindingCheck
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBinding
 * @uses   \OCA\Dossiq\Service\Intake\IntakeRequirements
 */
class IntakeFormBindingRegistrationTest extends TestCase {

	private const CASE_TYPE_SCHEMA = '7';

	/**
	 * The listeners the registrar names for one event.
	 *
	 * @param string $event The event class.
	 *
	 * @return array<int, string> The listener classes.
	 */
	private function registeredFor(string $event): array {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		(new IntakeListenerRegistrar())->register(context: $context);

		return ($registered[$event] ?? []);
	}//end registeredFor()

	/**
	 * Hand an event to the binding listener the registrar named for it.
	 *
	 * @param Event $event The pre-persist event.
	 *
	 * @return void
	 */
	private function dispatch(Event $event): void {
		$named = array_values(
			array_filter(
				$this->registeredFor(event: $event::class),
				static fn (string $listener): bool => $listener === IntakeFormBindingListener::class
			)
		);
		self::assertCount(1, $named, 'The intake form check is registered once for ' . $event::class . '.');

		$validator = new class() {
			public function validate(array $mapping, object $schema, array $options = []): array {
				$findings = [];
				foreach ($mapping['fields'] as $field) {
					if ($field['property'] !== 'title') {
						$findings[] = ['field' => $field['field'], 'property' => $field['property'], 'code' => 'property-unknown', 'message' => 'Unknown.'];
					}
				}

				return $findings;
			}//end validate()
		};
		$mapper = new class() {
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return (object)['id' => $id];
			}//end find()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => '3', 'case_type_schema' => self::CASE_TYPE_SCHEMA][$key] ?? $default)
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => ([
				IntakeFormBindingCheck::VALIDATOR => $validator,
				IntakeFormBindingCheck::SCHEMA_MAPPER => $mapper,
			][$class] ?? null)
		);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new ($named[0])($settings, new IntakeFormBindingCheck($settings, new IntakeFormBinding(), $logger), $logger);
		self::assertInstanceOf(IEventListener::class, $listener);
		$listener->handle($event);
	}//end dispatch()

	/**
	 * An object of a schema, as it is about to be stored.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $fields The object.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(string $schema, array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('6f1d0c7a-52a4-4b61-9d3e-0c2f8a7b1e10');
		$entity->setSchema($schema);
		$entity->setObject($fields);

		return $entity;
	}//end entity()

	/**
	 * A case type bound to a form, with one answer into a property.
	 *
	 * @param string $property The case property.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private static function boundType(string $property): array {
		return [
			'title' => 'Verhuizing doorgeven',
			'intakeFormRef' => 'form-abc',
			'intakeFormMapping' => ['fields' => [['field' => 'Onderwerp', 'property' => $property, 'type' => 'text']]],
		];
	}//end boundType()

	/**
	 * A new case type whose form writes into a property the case does not have is refused before it is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testANewCaseTypeWithAWrongBindingIsRefused(): void {
		$event = new ObjectCreatingEvent(self::entity(self::CASE_TYPE_SCHEMA, self::boundType('onderwerp')));
		$this->dispatch($event);

		self::assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame(IntakeFormBindingCheck::RULE, $errors['error']);
		self::assertSame('onderwerp', $errors['findings'][0]['property']);
		self::assertStringContainsString('Unknown.', $errors['message']);
	}//end testANewCaseTypeWithAWrongBindingIsRefused()

	/**
	 * An edit that breaks the binding is refused too, and a valid one is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testAnEditIsJudgedTheSameWay(): void {
		$before = self::entity(self::CASE_TYPE_SCHEMA, self::boundType('title'));

		$broken = new ObjectUpdatingEvent(self::entity(self::CASE_TYPE_SCHEMA, self::boundType('onderwerp')), $before);
		$this->dispatch($broken);
		self::assertTrue($broken->isPropagationStopped());

		$valid = new ObjectUpdatingEvent(self::entity(self::CASE_TYPE_SCHEMA, self::boundType('title')), $before);
		$this->dispatch($valid);
		self::assertFalse($valid->isPropagationStopped());
		self::assertSame([], $valid->getErrors());
	}//end testAnEditIsJudgedTheSameWay()

	/**
	 * Publishing a case type whose form leaves out an intake requirement is refused, naming the property.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testPublishingReChecksTheFormAgainstTheIntakeRequirements(): void {
		$draft = array_merge(
			self::boundType('title'),
			['isDraft' => true, 'intakeRequirements' => ['requiredBeforeCreation' => ['communicationChannel']]]
		);
		$before = self::entity(self::CASE_TYPE_SCHEMA, $draft);

		$stillDraft = new ObjectUpdatingEvent(self::entity(self::CASE_TYPE_SCHEMA, $draft), $before);
		$this->dispatch($stillDraft);
		self::assertFalse($stillDraft->isPropagationStopped(), 'A draft case type saves.');

		$publish = new ObjectUpdatingEvent(self::entity(self::CASE_TYPE_SCHEMA, array_merge($draft, ['isDraft' => false])), $before);
		$this->dispatch($publish);

		self::assertTrue($publish->isPropagationStopped());
		self::assertSame('required-unmapped', $publish->getErrors()['findings'][0]['code']);
		self::assertSame('communicationChannel', $publish->getErrors()['findings'][0]['property']);
	}//end testPublishingReChecksTheFormAgainstTheIntakeRequirements()

	/**
	 * An object of another schema, and a case type without a form, are left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testOtherSavesAreLeftAlone(): void {
		$case = new ObjectCreatingEvent(self::entity('3', self::boundType('onderwerp')));
		$this->dispatch($case);
		self::assertFalse($case->isPropagationStopped(), 'Only a case type is judged.');

		$unbound = self::boundType('onderwerp');
		unset($unbound['intakeFormRef']);
		$event = new ObjectCreatingEvent(self::entity(self::CASE_TYPE_SCHEMA, $unbound));
		$this->dispatch($event);
		self::assertFalse($event->isPropagationStopped(), 'A case type with no form bound is not judged.');
	}//end testOtherSavesAreLeftAlone()
}//end class
