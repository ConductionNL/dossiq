<?php

/**
 * Dossiq dossiq:intake:drain-portaalverzoek command.
 *
 * Decision 179 (Q8): the portal's objection and complaint used to write a
 * `portaalVerzoek`, and nothing ever turned one into a case, although
 * REQ-POR-008 says a case is opened. The actions now open a case directly.
 * This command drains the requests already stored: each becomes a case of the
 * case type its kind names, through OpenRegister's form submit, or it is
 * reported with the findings that refused it.
 *
 * Safety posture:
 * - Dry-run by DEFAULT. Nothing is written unless `--apply` is passed.
 * - Idempotent. A request that already holds a case id, or whose id a case
 *   already carries as `externalReference`, is counted and skipped.
 * - Additive. A request is marked with its case id, never deleted. The schema
 *   is removed in a later release, once this command reports zero pending.
 * - Exit code 0 only when nothing is pending, so a script can wait on it.
 *
 * The mapping is configuration ({@see self::PLAN}); the engine is the generic
 * {@see IntakeObjectDrain}.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\Intake\IntakeObjectDrain;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Drains stored portal requests into cases.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class DrainIntakeObjectsCommand extends Command {

	use SearchesObjects;

	/**
	 * OpenRegister's form submit, by name: present from openregister#4563.
	 *
	 * @var string
	 */
	private const FORM_SUBMIT = 'OCA\\OpenRegister\\Service\\Form\\FormSubmitService';

	/**
	 * The appconfig key of the source schema.
	 *
	 * @var string
	 */
	private const SOURCE_SCHEMA = 'portaal_verzoek_schema';

	/**
	 * How a portal request becomes a case. Configuration, not code.
	 *
	 * @var array<string, mixed>
	 */
	public const PLAN = [
		'caseTypeField' => 'kind',
		'caseTypes' => [
			'bezwaarschrift' => IntakeCaseTypes::OBJECTION,
			'klachtschrift' => IntakeCaseTypes::COMPLAINT,
		],
		'titleField' => 'subject',
		'titles' => ['bezwaarschrift' => 'Bezwaar', 'klachtschrift' => 'Klacht'],
		'fields' => [
			'rationale' => 'description',
			'againstCaseId' => 'contestedCase',
			'againstDecisionId' => 'contestedDecision',
			'attachments' => 'attachments',
			'submitterRef' => 'portalSubject',
			// The term counts from when the resident sent it, not from the drain.
			'submittedAt' => 'receivedAt',
		],
		'fixed' => ['intakeChannel' => 'website'],
		'deliveredField' => 'newCaseId',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves OpenRegister's form submit.
	 * @param SettingsService    $settingsService Register, schemas and the object service.
	 * @param IntakeObjectDrain  $drain           The generic drain.
	 * @param IUserSession       $userSession     The session the drain runs under.
	 * @param IGroupManager      $groupManager    Finds an administrator to run as.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly IntakeObjectDrain $drain,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:intake:drain-portaalverzoek')
			->setDescription(
				description: 'Turn every stored portal request (portaalVerzoek) into a case, or report why not. Dry-run unless --apply.'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Actually open the cases. Without this flag the command only reports.'
			);
	}//end configure()

	/**
	 * Run the drain and print the report.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when nothing is pending, 1 otherwise.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$sourceSchema = $this->settingsService->getConfigValue(self::SOURCE_SCHEMA);
		$objectService = $this->settingsService->getObjectService();
		$submitter = $this->formSubmit();
		$admin = $this->admin(output: $output);

		if ($objectService === null || $submitter === null || $admin === null || $register === '' || $caseSchema === '') {
			$output->writeln('<error>OpenRegister with its form submit (openregister#4563) and the dossiq register are needed to drain.</error>');
			return Command::FAILURE;
		}

		if ($sourceSchema === '') {
			$output->writeln('<info>No portaalVerzoek schema is configured. Nothing to drain.</info>');
			return Command::SUCCESS;
		}

		$sources = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $sourceSchema,
			filters: ['_limit' => 10000]
		);
		$report = $this->drain->drain(
			sources: $sources,
			plan: self::PLAN,
			existing: fn (array $source): string => $this->caseHolding(
				objectService: $objectService,
				register: $register,
				caseSchema: $caseSchema,
				source: $source
			),
			submit: fn (array $case): array => $this->submit(
				submitter: $submitter,
				register: $register,
				caseSchema: $caseSchema,
				case: $case,
				admin: $admin
			),
			markSource: function (array $source, string $caseId) use ($objectService, $register, $sourceSchema): void {
				$this->markSource(objectService: $objectService, register: $register, schema: $sourceSchema, source: $source, caseId: $caseId);
			},
			apply: $apply
		);

		return $this->print(report: $report, apply: $apply, output: $output);
	}//end execute()

	/**
	 * Print the report.
	 *
	 * @param array<string, array<int, mixed>> $report The report.
	 * @param bool                             $apply  Whether cases were written.
	 * @param OutputInterface                  $output The output.
	 *
	 * @return int 0 when nothing is pending.
	 */
	private function print(array $report, bool $apply, OutputInterface $output): int {
		foreach ($report['delivered'] as $row) {
			$outcome = ' (dry run)';
			if ($row['case'] !== '') {
				$outcome = ' -> case ' . $row['case'];
			}

			$output->writeln('  delivered ' . $row['source'] . $outcome);
		}

		foreach ($report['refused'] as $row) {
			$codes = array_map(static fn (array $finding): string => ($finding['property'] ?? '') . ':' . ($finding['code'] ?? ''), $row['findings']);
			$output->writeln('  <error>refused</error> ' . $row['source'] . ' ' . implode(', ', $codes));
		}

		$pending = count($report['refused']);
		$hint = '';
		if ($apply === false) {
			$pending += count($report['delivered']);
			$hint = ' Dry run: pass --apply to open the cases.';
		}

		$output->writeln(
			sprintf(
				'Delivered %d, already a case %d, refused %d, pending %d.%s',
				count($report['delivered']),
				count($report['already']),
				count($report['refused']),
				$pending,
				$hint
			)
		);

		if ($pending === 0) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end print()

	/**
	 * Submit one case through OpenRegister's form submit.
	 *
	 * @param object               $submitter  The FormSubmitService.
	 * @param string               $register   The register id.
	 * @param string               $caseSchema The case schema id.
	 * @param array<string, mixed> $case       The case.
	 * @param IUser                $admin      The subject the case is written for.
	 *
	 * @return array<string, mixed> `id`, or `findings`.
	 */
	private function submit(object $submitter, string $register, string $caseSchema, array $case, IUser $admin): array {
		try {
			$answer = $submitter->submit(
				destination: ['register' => $register, 'schema' => $caseSchema],
				mapping: null,
				payload: $case,
				subject: $admin
			);
		} catch (Throwable $e) {
			$findings = [['property' => '', 'code' => 'refused', 'message' => $e->getMessage()]];
			if (method_exists($e, 'getFindings') === true && $e->getFindings() !== []) {
				$findings = $e->getFindings();
			}

			return ['findings' => $findings];
		}

		return ['id' => (string)($answer['id'] ?? '')];
	}//end submit()

	/**
	 * The id of a case that already carries this source's id as its earlier reference.
	 *
	 * @param object               $objectService The object service.
	 * @param string               $register      The register id.
	 * @param string               $caseSchema    The case schema id.
	 * @param array<string, mixed> $source        The source object.
	 *
	 * @return string The case id, or the empty string.
	 */
	private function caseHolding(object $objectService, string $register, string $caseSchema, array $source): string {
		$sourceId = trim((string)($source['id'] ?? ''));
		if ($sourceId === '') {
			return '';
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			filters: ['externalReference' => $sourceId, '_limit' => 1]
		);

		return trim((string)(($rows[0] ?? [])['id'] ?? ''));
	}//end caseHolding()

	/**
	 * Record the case id on the source, so the next run skips it.
	 *
	 * @param object               $objectService The object service.
	 * @param string               $register      The register id.
	 * @param string               $schema        The source schema id.
	 * @param array<string, mixed> $source        The source object.
	 * @param string               $caseId        The case id.
	 *
	 * @return void
	 */
	private function markSource(object $objectService, string $register, string $schema, array $source, string $caseId): void {
		$sourceId = (string)($source['id'] ?? '');
		$payload = array_merge($source, [(string)self::PLAN['deliveredField'] => $caseId]);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		$objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: $sourceId);
	}//end markSource()

	/**
	 * OpenRegister's form submit, or null on an OpenRegister without it.
	 *
	 * @return object|null The service.
	 */
	private function formSubmit(): ?object {
		// The container answers an unknown class with an exception, which is
		// exactly "this OpenRegister has no form submit".
		try {
			$service = $this->container->get(self::FORM_SUBMIT);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end formSubmit()

	/**
	 * The user the drain writes as: the session's, or the first administrator.
	 *
	 * @param OutputInterface $output The output.
	 *
	 * @return IUser|null The user, or null when there is none.
	 */
	private function admin(OutputInterface $output): ?IUser {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user;
		}

		$users = ($this->groupManager->get('admin')?->getUsers() ?? []);
		$admin = reset($users);
		if (($admin instanceof IUser) === false) {
			$output->writeln('<error>No admin user found to run the drain under.</error>');
			return null;
		}

		$this->userSession->setUser($admin);
		$output->writeln('<comment>Running as admin user "' . $admin->getUID() . '".</comment>');

		return $admin;
	}//end admin()
}//end class
