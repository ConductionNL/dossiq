<?php

/**
 * Procest procest:legal-hold:backfill command.
 *
 * Repairs the cases that passed through an Awb bezwaar/beroep proceeding while
 * {@see \OCA\Procest\Listener\BezwaarLegalHoldListener} was dead and therefore
 * never received the legal hold archiving law requires (procest#694).
 *
 * The listener was inert for its entire life: `resolveCaseObject()` called
 * `MagicMapper::findByUuid()`, a method that does not exist and has no
 * `__call()` fallback, so every invocation raised a fatal `\Error` that the
 * surrounding `catch (\Throwable)` swallowed — no hold, no exception, no log
 * line. procest#693 fixes it forward; this command repairs the backlog it left.
 *
 * Safety posture:
 * - Dry-run by DEFAULT. Nothing is written unless `--apply` is passed.
 * - Idempotent. A case that already carries an active hold is skipped, so the
 *   command is safe to re-run and safe to run after the listener is live.
 * - Additive only. It places holds; it never releases one and never deletes or
 *   range-updates anything. Retention data is legal-retention data.
 * - Only cases with at least one OPEN proceeding are held. A proceeding that
 *   already reached its terminal decision is closed, and placing a hold on it
 *   now only to release it would write a hold that never existed into the
 *   retention history. Those are reported, not written.
 * - The reason string names this remediation explicitly, so a backfilled hold
 *   is never mistaken for a contemporaneous one in an audit.
 *
 * @category Command
 * @package  OCA\Procest\Command
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Command;

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill the Awb legal holds the dead bezwaar listener never placed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Remediation spans several OpenRegister collaborators.
 */
class BackfillLegalHoldsCommand extends Command
{

    /**
     * OpenRegister LegalHoldService FQN (resolved lazily).
     *
     * @var string
     */
    private const LEGAL_HOLD_SERVICE = 'OCA\OpenRegister\Service\Archival\LegalHoldService';

    /**
     * OpenRegister ObjectService FQN (resolved lazily).
     *
     * @var string
     */
    private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

    /**
     * OpenRegister object mapper FQN (resolved lazily).
     *
     * @var string
     */
    private const OBJECT_MAPPER = 'OCA\OpenRegister\Db\MagicMapper';

    /**
     * The register the Awb schemas live in.
     *
     * @var string
     */
    private const REGISTER = 'procest';

    /**
     * Proceeding schemas whose existence opens an Awb proceeding.
     *
     * `beroep` is included here even though the listener itself does not act on
     * it: an appeal suspends destruction exactly as an objection does, and a
     * case sitting in beroep with no hold is the same compliance defect. The
     * gap in the listener is reported separately on procest#694.
     *
     * @var array<int, string>
     */
    private const OPENING_SCHEMAS = ['bezwaar', 'objection', 'beroep'];

    /**
     * Scan failures collected while listing objects, reported before exit.
     *
     * @var array<int, string>
     */
    private array $scanErrors = [];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    DI container (OpenRegister resolved lazily).
     * @param IUserSession       $userSession  Session used to impersonate an admin.
     * @param IGroupManager      $groupManager Resolves an admin to impersonate.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name, description and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'procest:legal-hold:backfill')
            ->setDescription(
                'Backfill Awb legal holds on cases with an open bezwaar/beroep proceeding (procest#694). Dry-run unless --apply.'
            )
            ->addOption(
                name: 'apply',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Actually place the holds. Without this flag the command only reports.'
            )
            ->addOption(
                name: 'include-deleted',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Also consider soft-deleted cases and proceedings (off by default).'
            );
    }//end configure()

    /**
     * Run the backfill (or the dry-run report).
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply          = (bool) $input->getOption('apply');
        $includeDeleted = (bool) $input->getOption('include-deleted');

        if ($this->impersonateAdmin(output: $output) === false) {
            return Command::FAILURE;
        }

        $objectService    = $this->resolveOr(fqn: self::OBJECT_SERVICE);
        $objectMapper     = $this->resolveOr(fqn: self::OBJECT_MAPPER);
        $legalHoldService = $this->resolveOr(fqn: self::LEGAL_HOLD_SERVICE);

        if ($objectService === null || $objectMapper === null || $legalHoldService === null) {
            $output->writeln('<error>OpenRegister is not available; cannot backfill legal holds.</error>');
            return Command::FAILURE;
        }

        $candidates = $this->collectCandidates(
            objectService: $objectService,
            includeDeleted: $includeDeleted,
            output: $output
        );

        foreach ($this->scanErrors as $scanError) {
            $output->writeln('  <error>[scan failed]</error> '.$scanError);
        }

        if (count($candidates) === 0) {
            $output->writeln('<info>No cases with an open Awb proceeding were found. Nothing to backfill.</info>');
            return Command::SUCCESS;
        }

        return $this->reportAndApply(
            candidates: $candidates,
            objectMapper: $objectMapper,
            legalHoldService: $legalHoldService,
            apply: $apply,
            includeDeleted: $includeDeleted,
            output: $output
        );
    }//end execute()

    /**
     * Walk the candidate cases, reporting each and holding it when applying.
     *
     * @param array<string, array<string, mixed>> $candidates       Cases keyed by UUID.
     * @param object                              $objectMapper     OpenRegister object mapper.
     * @param object                              $legalHoldService OpenRegister legal hold service.
     * @param bool                                $apply            Whether to write.
     * @param bool                                $includeDeleted   Whether soft-deleted cases are in scope.
     * @param OutputInterface                     $output           Console output.
     *
     * @return int Symfony command exit code.
     */
    private function reportAndApply(
        array $candidates,
        object $objectMapper,
        object $legalHoldService,
        bool $apply,
        bool $includeDeleted,
        OutputInterface $output
    ): int {
        $held       = 0;
        $already    = 0;
        $unresolved = 0;

        $output->writeln('');
        $output->writeln('<info>Cases with at least one OPEN Awb proceeding:</info>');

        foreach ($candidates as $caseId => $meta) {
            $caseObject = $this->findCase(
                objectMapper: $objectMapper,
                caseId: (string) $caseId,
                includeDeleted: $includeDeleted
            );

            if ($caseObject === null) {
                // A dangling reference: the proceeding names a case that does not
                // exist (or is soft-deleted and not in scope). Reported, never
                // silently dropped — a hold that cannot be placed is a finding.
                $output->writeln('  <comment>[unresolved]</comment> '.$caseId.' — '.$this->describe(meta: $meta));
                $unresolved++;
                continue;
            }

            if ($legalHoldService->hasActiveHold($caseObject) === true) {
                $output->writeln('  <comment>[already held]</comment> '.$caseId.' — '.$this->describe(meta: $meta));
                $already++;
                continue;
            }

            if ($apply === false) {
                $output->writeln('  <info>[would hold]</info> '.$caseId.' — '.$this->describe(meta: $meta));
                $held++;
                continue;
            }

            try {
                $legalHoldService->placeHold($caseObject, $this->reasonFor(meta: $meta));
                $output->writeln('  <info>[HELD]</info> '.$caseId.' — '.$this->describe(meta: $meta));
                $held++;
            } catch (\Throwable $e) {
                $output->writeln('  <error>[FAILED]</error> '.$caseId.' — '.$e->getMessage());
                $unresolved++;
            }
        }//end foreach

        $output->writeln('');
        $heldLabel = '  would hold  = ';
        if ($apply === true) {
            $heldLabel = '  held        = ';
        }

        $output->writeln('  candidates  = '.count($candidates));
        $output->writeln($heldLabel.$held);
        $output->writeln('  already held= '.$already);
        $output->writeln('  unresolved  = '.$unresolved);

        if ($apply === false) {
            $output->writeln('');
            $output->writeln('<comment>Dry run — nothing was written. Re-run with --apply to place these holds.</comment>');
        }

        return Command::SUCCESS;
    }//end reportAndApply()

    /**
     * Collect the cases that have at least one open Awb proceeding.
     *
     * A proceeding is OPEN when no terminal decision references it. Closed
     * proceedings are deliberately excluded: backfilling a hold on a concluded
     * case would fabricate retention history rather than repair it.
     *
     * @param object          $objectService  OpenRegister object service.
     * @param bool            $includeDeleted Whether soft-deleted objects are in scope.
     * @param OutputInterface $output         Console output.
     *
     * @return array<string, array<string, mixed>> Candidate cases keyed by case UUID.
     */
    private function collectCandidates(object $objectService, bool $includeDeleted, OutputInterface $output): array
    {
        $closedBezwaar = $this->terminatedProceedingIds(objectService: $objectService, schema: 'bezwaarDecision');
        $closedAppeal  = $this->terminatedProceedingIds(objectService: $objectService, schema: 'appealDecision');
        $closed        = array_merge($closedBezwaar, $closedAppeal);

        // Report the closed set explicitly. An empty set here silently disables
        // the "only hold OPEN proceedings" rule, which is the difference between
        // a targeted repair and holding every case that ever saw a proceeding —
        // exactly the kind of inert safety check this whole programme is about.
        $output->writeln(
            '  terminal decisions found: '.count($closed)
            .' (bezwaarDecision='.count($closedBezwaar).', appealDecision='.count($closedAppeal).')'
        );

        $candidates = [];

        foreach (self::OPENING_SCHEMAS as $schemaSlug) {
            $proceedings = $this->listObjects(
                objectService: $objectService,
                schema: $schemaSlug,
                includeDeleted: $includeDeleted
            );

            $closedHit = 0;

            foreach ($proceedings as $proceeding) {
                $uuid = (string) $proceeding['uuid'];
                if ($uuid !== '' && in_array($uuid, $closed, true) === true) {
                    $closedHit++;
                }

                if ($uuid !== '' && in_array($uuid, $closed, true) === true) {
                    // Terminal decision exists: this proceeding is concluded.
                    continue;
                }

                $caseId = $this->caseIdOf(proceeding: $proceeding['data']);
                if ($caseId === '') {
                    continue;
                }

                if (isset($candidates[$caseId]) === false) {
                    $candidates[$caseId] = ['schemas' => [], 'count' => 0];
                }

                $candidates[$caseId]['count']++;
                if (in_array($schemaSlug, $candidates[$caseId]['schemas'], true) === false) {
                    $candidates[$caseId]['schemas'][] = $schemaSlug;
                }
            }//end foreach

            // `closed` is reported per schema on purpose: if it is 0 while
            // terminal decisions exist, the "only hold OPEN proceedings" rule
            // is silently inert and every concluded case would be held too.
            // That exact failure happened during development, and only showed
            // up because this counter was printed.
            $output->writeln(
                '  scanned '.$schemaSlug.': '.count($proceedings).' object(s), '
                .$closedHit.' already concluded'
            );
        }//end foreach

        return $candidates;
    }//end collectCandidates()

    /**
     * List the proceeding UUIDs that already carry a terminal decision.
     *
     * @param object $objectService OpenRegister object service.
     * @param string $schema        The decision schema slug.
     *
     * @return array<int, string> Proceeding UUIDs that are concluded.
     */
    private function terminatedProceedingIds(object $objectService, string $schema): array
    {
        $ids = [];

        foreach ($this->listObjects(objectService: $objectService, schema: $schema, includeDeleted: true) as $decision) {
            foreach (['bezwaar', 'beroep', 'appeal', 'objection'] as $key) {
                $ref = ($decision['data'][$key] ?? null);
                if (is_string($ref) === true && $ref !== '') {
                    $ids[] = $ref;
                }
            }
        }

        return $ids;
    }//end terminatedProceedingIds()

    /**
     * List every object of a schema in the procest register.
     *
     * Returns an empty array when the schema does not exist on this instance,
     * so an instance that never installed a given Awb schema is a no-op rather
     * than a failure.
     *
     * @param object $objectService  OpenRegister object service.
     * @param string $schema         Schema slug.
     * @param bool   $includeDeleted Whether to include soft-deleted objects.
     *
     * @return array<int, array<string, mixed>> Rendered objects.
     */
    private function listObjects(object $objectService, string $schema, bool $includeDeleted): array
    {
        try {
            $filters = [];
            if ($includeDeleted === true) {
                $filters['_includeDeleted'] = true;
            }

            $objects = $objectService
                ->setRegister(self::REGISTER)
                ->setSchema($schema)
                ->findAll(
                    [
                        'limit'   => null,
                        'filters' => $filters,
                    ],
                    false,
                    false
                );

            if (is_array($objects) === false) {
                return [];
            }

            return array_map([$this, 'normaliseRow'], $objects);
        } catch (\Throwable $e) {
            // Never swallow silently: a scan that fails and a schema that is
            // genuinely empty are indistinguishable in the result, and that
            // ambiguity is what let the original dead listener hide.
            $this->scanErrors[] = $schema.': '.$e->getMessage();
            return [];
        }//end try
    }//end listObjects()

    /**
     * Normalise one findAll() result into a uuid + payload pair.
     *
     * `ObjectService::findAll()` returns ObjectEntity instances on this path
     * rather than the rendered arrays its docblock implies, and a future
     * release may well return arrays again. Rather than depend on either
     * shape, both are accepted — a listener-style guess at the shape is
     * precisely the kind of assumption that made the original bug invisible.
     *
     * @param mixed $row One findAll() result row.
     *
     * @return array{uuid: string, data: array<string, mixed>} Normalised row.
     */
    private function normaliseRow(mixed $row): array
    {
        $data = [];
        $uuid = '';

        if (is_object($row) === true) {
            foreach (['getUuid', 'getId'] as $getter) {
                if ($uuid === '' && method_exists($row, $getter) === true) {
                    $uuid = (string) ($row->$getter() ?? '');
                }
            }

            if (method_exists($row, 'getObject') === true && is_array($row->getObject()) === true) {
                $data = $row->getObject();
            }

            // Fall back to jsonSerialize(), the shape the rest of OpenRegister
            // renders to, so a future return-shape change cannot silently empty
            // the uuid again — an empty uuid here disables the closed-proceeding
            // filter without any visible error, which is exactly what happened
            // during development of this command.
            if ($uuid === '' && method_exists($row, 'jsonSerialize') === true) {
                $serialised = $row->jsonSerialize();
                if (is_array($serialised) === true) {
                    $uuid = $this->uuidFromArray(row: $serialised);
                    if ($data === []) {
                        $data = $serialised;
                    }
                }
            }//end if

            return ['uuid' => $uuid, 'data' => $data];
        }//end if

        if (is_array($row) === true) {
            return ['uuid' => $this->uuidFromArray(row: $row), 'data' => $row];
        }

        return ['uuid' => '', 'data' => []];
    }//end normaliseRow()

    /**
     * Read an object uuid out of a rendered object array, whatever its shape.
     *
     * @param array<string, mixed> $row A rendered OpenRegister object.
     *
     * @return string The uuid, or '' when no known key carries one.
     */
    private function uuidFromArray(array $row): string
    {
        $self = ($row['@self'] ?? []);
        if (is_array($self) === false) {
            $self = [];
        }

        foreach ([$self['uuid'] ?? null, $self['id'] ?? null, $row['uuid'] ?? null, $row['id'] ?? null] as $value) {
            if (is_scalar($value) === true && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }//end uuidFromArray()

    /**
     * Read the case UUID a proceeding relates to.
     *
     * @param array<string, mixed> $proceeding The rendered proceeding object.
     *
     * @return string The case UUID, or '' when absent.
     */
    private function caseIdOf(array $proceeding): string
    {
        $case = ($proceeding['case'] ?? null);
        if (is_string($case) === true) {
            return trim($case);
        }

        // An extended render inlines the related object instead of its id.
        if (is_array($case) === true) {
            return (string) ($case['id'] ?? ($case['@self']['id'] ?? ''));
        }

        return '';
    }//end caseIdOf()

    /**
     * Resolve a case ObjectEntity by UUID.
     *
     * Mirrors the fixed listener exactly (procest#693): `find()` with RBAC and
     * multitenancy disabled, because occ has no session user and no active
     * organisation, and an organisation-scoped read would find nothing and
     * silently reopen the same hole this command exists to close.
     *
     * @param object $objectMapper   OpenRegister object mapper.
     * @param string $caseId         The case UUID.
     * @param bool   $includeDeleted Whether soft-deleted cases are in scope.
     *
     * @return object|null The case ObjectEntity, or null when unresolvable.
     */
    private function findCase(object $objectMapper, string $caseId, bool $includeDeleted): ?object
    {
        try {
            $caseObject = $objectMapper->find(
                identifier: $caseId,
                includeDeleted: $includeDeleted,
                _rbac: false,
                _multitenancy: false
            );

            if (is_object($caseObject) === true) {
                return $caseObject;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }//end try
    }//end findCase()

    /**
     * Build the hold reason, naming the remediation so it is auditable.
     *
     * @param array<string, mixed> $meta Candidate metadata.
     *
     * @return string The reason recorded on the hold.
     */
    private function reasonFor(array $meta): string
    {
        $schemas = implode('/', ($meta['schemas'] ?? []));

        return 'Awb-procedure ('.$schemas.') geregistreerd — archivering opgeschort '
            .'[backfill procest#694: hold ontbrak doordat de listener nooit heeft gelopen; '
            .'geplaatst op de datum van herstel, niet terugwerkend]';
    }//end reasonFor()

    /**
     * Render a one-line description of a candidate.
     *
     * @param array<string, mixed> $meta Candidate metadata.
     *
     * @return string Human-readable description.
     */
    private function describe(array $meta): string
    {
        return ($meta['count'] ?? 0).' open proceeding(s): '.implode(', ', ($meta['schemas'] ?? []));
    }//end describe()

    /**
     * Impersonate an admin so OpenRegister writes are permitted.
     *
     * The occ context has no session ("Anonymous"), and the hold write goes
     * through MagicMapper::update(); without a user the audit trail would
     * attribute the remediation to nobody.
     *
     * @param OutputInterface $output Console output.
     *
     * @return bool True when a session user is available.
     */
    private function impersonateAdmin(OutputInterface $output): bool
    {
        if ($this->userSession->getUser() !== null) {
            return true;
        }

        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            $output->writeln('<error>No admin group found to run the backfill under.</error>');
            return false;
        }

        $users = $adminGroup->getUsers();
        if (count($users) === 0) {
            $output->writeln('<error>No admin user found to run the backfill under.</error>');
            return false;
        }

        $admin = reset($users);
        $this->userSession->setUser($admin);
        $output->writeln('<comment>Running as admin user "'.$admin->getUID().'".</comment>');

        return true;
    }//end impersonateAdmin()

    /**
     * Resolve an OpenRegister collaborator by FQN, or null when unavailable.
     *
     * @param string $fqn Fully-qualified class name.
     *
     * @return object|null The service, or null when OpenRegister is absent.
     */
    private function resolveOr(string $fqn): ?object
    {
        if (class_exists($fqn) === false) {
            return null;
        }

        try {
            $service = $this->container->get($fqn);
            if (is_object($service) === true) {
                return $service;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }//end try
    }//end resolveOr()
}//end class
