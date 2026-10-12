<?php

/**
 * Wiring test: saving a case type re-checks the published forms of other apps that open a case of it.
 *
 * Runs from the registrar to the effect, with OpenRegister's real event, its
 * real `FormDependent` and its real `FormDependentsCheck`, so the assessment
 * dossiq builds is read by the code that unpublishes and notifies.
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
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar;
use OCA\Dossiq\Listener\CaseTypeBoundFormsListener;
use OCA\Dossiq\Listener\IntakeFormDependentsListener;
use OCA\Dossiq\Service\Intake\BoundFormsCheck;
use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Service\Intake\IntakeFormBindingCheck;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The registered listeners judge the forms bound to a case type, and answer for dossiq's own.
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar
 * @covers \OCA\Dossiq\Listener\CaseTypeBoundFormsListener
 * @covers \OCA\Dossiq\Listener\IntakeFormDependentsListener
 * @covers \OCA\Dossiq\Service\Intake\BoundFormsCheck
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBindingCheck
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBinding
 * @uses   \OCA\Dossiq\Service\Intake\IntakeRequirements
 * @uses   \OCA\Dossiq\Service\CaseFieldWriter
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BoundFormsRegistrationTest extends TestCase {

	private const CASE_TYPE_SCHEMA = '7';

	private const CASE_SCHEMA = '3';

	private const CASE_TYPE_ID = '6f1d0c7a-52a4-4b61-9d3e-0c2f8a7b1e10';

	/**
	 * The forms the other apps announce, as portaliq's listener would.
	 *
	 * @var array<int, FormDependent>
	 */
	private array $announced = [];

	/**
	 * The ids of the forms whose unpublish closure ran.
	 *
	 * @var array<int, string>
	 */
	private array $unpublished = [];

	/**
	 * The users OpenRegister notified.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	/**
	 * What each owning app set for `formDestinationBreak`.
	 *
	 * @var array<string, string>
	 */
	private array $breaks = [];

	/**
	 * How often the owning apps were asked.
	 *
	 * @var int
	 */
	private int $asked = 0;

	/**
	 * The stored case types, and the writes made to them.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Fresh state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->announced = [];
		$this->unpublished = [];
		$this->notified = [];
		$this->breaks = [];
		$this->asked = 0;
		$this->store = new class() {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $caseTypes = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $patches = [];

			public bool $down = false;

			public function searchObjects(array $query): array {
				if ($this->down === true) {
					throw new RuntimeException('store down');
				}

				return ['results' => $this->caseTypes];
			}//end searchObjects()

			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->patches[] = ['id' => $objectId, 'data' => $data, 'schema' => $schema];

				return array_merge(['id' => $objectId], $data);
			}//end patchObject()
		};
	}//end setUp()

	/**
	 * A published form of another app that opens a case of a type.
	 *
	 * @param string             $id         The form id.
	 * @param string             $caseType   The fixed case type.
	 * @param array<int, string> $properties The case properties its answers fill.
	 * @param string             $app        The owning app.
	 *
	 * @return FormDependent The form.
	 */
	private function form(string $id, string $caseType, array $properties, string $app = 'portaliq'): FormDependent {
		return new FormDependent(
			id: $id,
			app: $app,
			title: 'Formulier ' . $id,
			author: 'maker-' . $id,
			mapping: [
				'fields' => array_map(static fn (string $property): array => ['field' => $property, 'property' => $property, 'type' => 'text'], $properties),
				'fixed' => ['caseType' => $caseType],
			],
			unpublish: function () use ($id): void {
				$this->unpublished[] = $id;
			},
			audience: 'public',
		);
	}//end form()

	/**
	 * The check, wired to fakes of OpenRegister's edges and its real dependents service.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return BoundFormsCheck The check.
	 */
	private function check(?LoggerInterface $logger = null): BoundFormsCheck {
		$logger = ($logger ?? $this->createMock(LoggerInterface::class));

		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setUuid('schema-uuid-case');
		$mapper = new class($schema) {
			public function __construct(private Schema $schema) {
			}//end __construct()

			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return $this->schema;
			}//end find()
		};

		$validator = $this->createMock(FormDestinationValidator::class);
		$validator->method('validate')->willReturn([]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->breaks[$app] ?? $default)
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				self::assertInstanceOf(FormDestinationDependentsEvent::class, $event);
				self::assertSame('case', $event->getSchema()->getSlug());
				$this->asked++;
				foreach ($this->announced as $form) {
					$event->addForm($form);
				}
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $user) use ($notification): INotification {
				$this->notified[] = $user;

				return $notification;
			}
		);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);

		// OpenRegister's own service applies the assessment dossiq builds.
		$dependents = new FormDependentsCheck($dispatcher, $validator, $appConfig, $notifications, $logger);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'case_schema' => self::CASE_SCHEMA,
				'case_type_schema' => self::CASE_TYPE_SCHEMA,
				'register' => 'dossiq',
			][$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => ([
				IntakeFormBindingCheck::VALIDATOR => $validator,
				IntakeFormBindingCheck::SCHEMA_MAPPER => $mapper,
				BoundFormsCheck::DEPENDENTS_CHECK => $dependents,
			][$class] ?? null)
		);

		return new BoundFormsCheck(
			$settings,
			$dispatcher,
			$appConfig,
			new IntakeFormBindingCheck($settings, new IntakeFormBinding(), $logger),
			new IntakeFormBinding(),
			$logger
		);
	}//end check()

	/**
	 * The listeners the registrar names for an event.
	 *
	 * @param string $event The event class.
	 *
	 * @return array<int, string> The listener classes.
	 */
	private function registeredFor(string $event): array {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);
		(new IntakeListenerRegistrar())->register(context: $context);

		return ($registered[$event] ?? []);
	}//end registeredFor()

	/**
	 * Hand a case type save to the bound forms listener the registrar named for it.
	 *
	 * @param Event $event The save event.
	 *
	 * @return void
	 */
	private function save(Event $event): void {
		self::assertSame(
			1,
			count(array_keys($this->registeredFor(event: $event::class), CaseTypeBoundFormsListener::class, true)),
			'The bound forms check is registered once for ' . $event::class . '.'
		);

		(new CaseTypeBoundFormsListener($this->check(), $this->createMock(LoggerInterface::class)))->handle($event);
	}//end save()

	/**
	 * A stored or about to be stored object.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $fields The object.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(string $schema, array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::CASE_TYPE_ID);
		$entity->setSchema($schema);
		$entity->setObject($fields);

		return $entity;
	}//end entity()

	/**
	 * A published case type that asks for the communication channel before a case exists.
	 *
	 * @param bool $draft Whether it is still a draft.
	 *
	 * @return ObjectEntity The case type.
	 */
	private static function caseType(bool $draft = false): ObjectEntity {
		return self::entity(
			self::CASE_TYPE_SCHEMA,
			[
				'title' => 'Klacht',
				'slug' => 'klacht',
				'isDraft' => $draft,
				'intakeRequirements' => ['requiredBeforeCreation' => ['communicationChannel']],
			]
		);
	}//end caseType()

	/**
	 * Where the owning app says refuse, the case type save is refused and names the form and what it lacks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testAFormThatLacksANewIntakeRequirementRefusesThePublishWhereItsAppSaysSo(): void {
		$this->breaks = ['portaliq' => 'refuse'];
		$this->announced = [
			$this->form('klacht-form', 'klacht', ['title']),
			$this->form('other-type-form', 'verhuizing', ['title']),
			$this->form('complete-form', self::CASE_TYPE_ID, ['title', 'communicationChannel']),
		];

		$publish = new ObjectUpdatingEvent(self::caseType(), self::caseType(draft: true));
		$this->save($publish);

		self::assertTrue($publish->isPropagationStopped());
		$errors = $publish->getErrors();
		self::assertSame(BoundFormsCheck::RULE, $errors['code']);
		self::assertSame(['klacht-form'], array_column($errors['forms'], 'id'));
		self::assertSame('required-unmapped', $errors['forms'][0]['findings'][0]['code']);
		self::assertSame('communicationChannel', $errors['forms'][0]['findings'][0]['property']);
		self::assertArrayNotHasKey('form', $errors['forms'][0], 'The response carries no closure.');
		self::assertStringContainsString('Formulier klacht-form', $errors['message']);
		self::assertSame([], $this->unpublished, 'A refused save unpublishes nothing.');

		$create = new ObjectCreatingEvent(self::caseType());
		$this->save($create);
		self::assertTrue($create->isPropagationStopped(), 'A case type created published is judged the same way.');
	}//end testAFormThatLacksANewIntakeRequirementRefusesThePublishWhereItsAppSaysSo()

	/**
	 * By default the save goes through, and after it the broken form is unpublished and its author told.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testByDefaultTheBrokenFormIsUnpublishedAfterTheSaveAndItsAuthorIsTold(): void {
		$this->announced = [
			$this->form('klacht-form', self::CASE_TYPE_ID, ['title']),
			$this->form('other-type-form', 'verhuizing', ['title']),
			$this->form('complete-form', 'klacht', ['communicationChannel']),
		];

		$publish = new ObjectUpdatingEvent(self::caseType(), self::caseType(draft: true));
		$this->save($publish);
		self::assertFalse($publish->isPropagationStopped(), 'Unpublish is not a refusal.');
		self::assertSame([], $this->unpublished, 'Nothing is unpublished before the case type is stored.');

		$this->save(new ObjectUpdatedEvent(self::caseType(), self::caseType(draft: true)));

		self::assertSame(['klacht-form'], $this->unpublished);
		self::assertSame(['maker-klacht-form'], $this->notified);

		$this->unpublished = [];
		$this->save(new ObjectCreatedEvent(self::caseType()));
		self::assertSame(['klacht-form'], $this->unpublished, 'A case type created published is handled the same way.');
	}//end testByDefaultTheBrokenFormIsUnpublishedAfterTheSaveAndItsAuthorIsTold()

	/**
	 * A draft case type, another object and dossiq's own binding ask nothing of the forms.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testADraftAnotherObjectAndTheOwnBindingAreLeftAlone(): void {
		$this->breaks = ['portaliq' => 'refuse', 'dossiq' => 'refuse'];
		$this->announced = [$this->form('klacht-form', 'klacht', ['title'])];

		$draft = new ObjectUpdatingEvent(self::caseType(draft: true), self::caseType(draft: true));
		$this->save($draft);
		self::assertFalse($draft->isPropagationStopped(), 'A draft case type saves.');

		$case = new ObjectUpdatingEvent(self::entity(self::CASE_SCHEMA, ['title' => 'Een zaak']), self::entity(self::CASE_SCHEMA, []));
		$this->save($case);
		self::assertFalse($case->isPropagationStopped());
		self::assertSame(0, $this->asked, 'Neither save asks the owning apps anything.');

		// The case type's own Forms binding is refused by the binding check, not here.
		$this->announced = [$this->form(self::CASE_TYPE_ID, self::CASE_TYPE_ID, ['title'], 'dossiq')];
		$own = new ObjectUpdatingEvent(self::caseType(), self::caseType());
		$this->save($own);
		self::assertFalse($own->isPropagationStopped());
		self::assertSame(1, $this->asked);
	}//end testADraftAnotherObjectAndTheOwnBindingAreLeftAlone()

	/**
	 * dossiq answers OpenRegister's question for the case schema with its own published Forms bindings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testDossiqAnnouncesItsOwnFormsBindingsForTheCaseSchema(): void {
		self::assertSame([IntakeFormDependentsListener::class], $this->registeredFor(event: FormDestinationDependentsEvent::class));
		self::assertSame(BoundFormsCheck::DEPENDENTS_EVENT, FormDestinationDependentsEvent::class);
		self::assertSame(BoundFormsCheck::FORM_DEPENDENT, FormDependent::class);
		self::assertSame(BoundFormsCheck::DEPENDENTS_CHECK, FormDependentsCheck::class);
		self::assertSame(BoundFormsCheck::BREAK_SETTING, FormDependentsCheck::BREAK_SETTING);

		$this->store->caseTypes = [
			[
				'id' => 'type-bound',
				'title' => 'Verhuizing doorgeven',
				'intakeFormRef' => 'form-abc',
				'intakeFormMapping' => ['fields' => [['field' => 'Onderwerp', 'property' => 'title', 'type' => 'text']]],
				'@self' => ['owner' => 'beheerder'],
			],
			['id' => 'type-draft', 'title' => 'Concept', 'intakeFormRef' => 'form-def', 'isDraft' => true],
			['id' => 'type-unbound', 'title' => 'Zonder formulier'],
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => 'schema-uuid-case'][$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');
		$listener = new IntakeFormDependentsListener($settings, $this->check(), new IntakeFormBinding(), $logger);

		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setUuid('schema-uuid-case');
		$event = new FormDestinationDependentsEvent($schema);
		$listener->handle($event);

		self::assertCount(1, $event->getForms());
		$form = $event->getForms()[0];
		self::assertSame('type-bound', $form->id);
		self::assertSame('dossiq', $form->app);
		self::assertSame('Verhuizing doorgeven', $form->title);
		self::assertSame('beheerder', $form->author);
		self::assertSame('title', $form->mapping['fields'][0]['property']);
		self::assertSame('type-bound', $form->mapping['fixed']['caseType']);
		self::assertSame('forms', $form->mapping['fixed']['intakeChannel']);

		// Unpublishing takes the binding off and keeps the mapping.
		($form->unpublish)();
		self::assertSame([['id' => 'type-bound', 'data' => ['intakeFormRef' => ''], 'schema' => self::CASE_TYPE_SCHEMA]], $this->store->patches);

		$other = new Schema();
		$other->setSlug('ticket');
		$otherEvent = new FormDestinationDependentsEvent($other);
		$listener->handle($otherEvent);
		self::assertSame([], $otherEvent->getForms(), 'Another schema is not dossiq\'s to answer for.');

		$listener->handle(new ObjectCreatedEvent(self::caseType()));
	}//end testDossiqAnnouncesItsOwnFormsBindingsForTheCaseSchema()

	/**
	 * A failed read of the case types is not "no forms": it is logged as an error and the schema save goes on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function testAFailedReadOfTheCaseTypesIsLoggedAsAnError(): void {
		$this->store->down = true;
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('schema-uuid-case');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(self::stringContains('could not list the intake forms'));

		$schema = new Schema();
		$schema->setUuid('schema-uuid-case');
		$event = new FormDestinationDependentsEvent($schema);
		(new IntakeFormDependentsListener($settings, $this->check(), new IntakeFormBinding(), $logger))->handle($event);

		self::assertSame([], $event->getForms());
	}//end testAFailedReadOfTheCaseTypesIsLoggedAsAnError()
}//end class
