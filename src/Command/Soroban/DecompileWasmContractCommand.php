<?php

namespace App\Command\Soroban;

use App\Command\Support\NetworkOptionTrait;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Service\SorobanRpcService;
use App\Service\Stellar\Soroban\Sep55ContractVerificationService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:soroban:decompile-contract',
    description: 'Decompile contract WASM by contract id and optionally persist metadata.',
)]
final class DecompileWasmContractCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly SorobanRpcService $sorobanRpcService,
        private readonly Sep55ContractVerificationService $sep55ContractVerificationService,
        private readonly ContractRepository $contractRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Contract ID (C... or 64-hex)')
            ->addNetworkOption('mainnet|testnet|futurenet', 'testnet')
            ->addOption('print-source', null, InputOption::VALUE_NONE, 'Print decompiled source to stdout')
            ->addOption('save-wasm', null, InputOption::VALUE_NONE, 'Save WASM bytes to var/wasm/<contractId>.wasm')
            ->addOption('force-recompile', null, InputOption::VALUE_NONE, 'Ignore cached source and force recompile')
            ->addOption('persist-contract', null, InputOption::VALUE_NONE, 'Persist basic metadata into contracts table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $validated = $this->validateInput($input, $io);
        if ($validated === null) {
            return Command::FAILURE;
        }

        $result = $this->runDecompile($validated['contractId'], $validated['network'], $validated['forceRecompile']);
        if ($result === null) {
            $io->error('Failed to decompile source code. Contract may be invalid, SAC, or unreachable.');
            return Command::FAILURE;
        }

        $sep55Verification = $this->runSep55Verification($result);
        $wasmPath = $this->saveWasmIfRequested($result, $validated['saveWasm']);
        $this->persistContractMetadata($result, $validated['network'], $validated['persistContract'], $sep55Verification);
        $this->renderResult($io, $result, $validated['network'], $validated['printSource'], $wasmPath, $sep55Verification);

        $io->success('WASM decompile completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array{contractId:string,network:string,printSource:bool,persistContract:bool,saveWasm:bool,forceRecompile:bool}|null
     */
    private function validateInput(InputInterface $input, SymfonyStyle $io): ?array
    {
        $contractId = trim((string) $input->getOption('contract'));
        if ($contractId === '') {
            $io->error('--contract is required.');
            return null;
        }

        return [
            'contractId' => $contractId,
            'network' => $this->resolveNetworkOption($input, $this->stellarNetworkResolver),
            'printSource' => (bool) $input->getOption('print-source'),
            'persistContract' => (bool) $input->getOption('persist-contract'),
            'saveWasm' => (bool) $input->getOption('save-wasm'),
            'forceRecompile' => (bool) $input->getOption('force-recompile'),
        ];
    }

    /**
     * @return array{
     *   contractId:string,
     *   contractIdHex:string,
     *   wasmId:?string,
     *   wasmSha256:string,
     *   wasmCodeBase64:?string,
     *   contractSourceCode:?string
     * }|null
     */
    private function runDecompile(string $contractId, string $network, bool $forceRecompile = false): ?array
    {
        $result = $this->sorobanRpcService->getContractWasmByContractId(
            $contractId,
            $network,
            true,
            $forceRecompile,
        );
        if (!is_array($result) || !is_string($result['wasmSha256'] ?? null)) {
            return null;
        }

        return $result;
    }

    /**
     * @param array{
     *   contractId:string,
     *   contractIdHex:string,
     *   wasmId:?string,
     *   wasmSha256:string,
     *   wasmCodeBase64:?string,
     *   contractSourceCode:?string
     * } $result
     * @param array{
     *   isVerified:bool,
     *   githubAddress:?string,
     *   commitHash:?string,
     *   attestationUrl:?string,
     *   error:?string
     * } $sep55Verification
     */
    private function renderResult(
        SymfonyStyle $io,
        array $result,
        string $network,
        bool $printSource,
        ?string $wasmPath,
        array $sep55Verification,
    ): void
    {
        $rows = [
            ['contractId', $result['contractId']],
            ['contractIdHex', $result['contractIdHex']],
            ['network', $network],
            ['wasmId', (string) ($result['wasmId'] ?? '')],
            ['wasmSha256', $result['wasmSha256']],
            ['sourceLength', (string) strlen((string) ($result['contractSourceCode'] ?? ''))],
            ['sep55Verified', $sep55Verification['isVerified'] ? '1' : '0'],
            ['githubAddress', (string) ($sep55Verification['githubAddress'] ?? '')],
            ['sep55CommitHash', (string) ($sep55Verification['commitHash'] ?? '')],
            ['sep55AttestationUrl', (string) ($sep55Verification['attestationUrl'] ?? '')],
            ['sep55Error', (string) ($sep55Verification['error'] ?? '')],
        ];
        if ($wasmPath !== null) {
            $rows[] = ['wasmPath', $wasmPath];
        }

        $io->table(
            ['Field', 'Value'],
            $rows
        );

        if (!$printSource) {
            return;
        }

        $io->section('Decompiled Source');
        $io->writeln($result['contractSourceCode'] ?? '');
    }

    /**
     * @param array{contractId:string,contractIdHex:string,wasmId:string|null,wasmSha256:string,contractSourceCode:string|null} $result
     * @param array{
     *   isVerified:bool,
     *   githubAddress:?string,
     *   commitHash:?string,
     *   attestationUrl:?string,
     *   error:?string
     * } $sep55Verification
     */
    private function persistContractMetadata(array $result, string $network, bool $createIfMissing, array $sep55Verification): void
    {
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $contract = $this->contractRepository->findOneByContractIdAndNetwork($result['contractId'], $networkCode);
        if (!$contract instanceof Contract && !$createIfMissing) {
            return;
        }
        $contract ??= new Contract();

        if ($contract->getId() === null) {
            $contract->setContractId($result['contractId']);
            $contract->setNetwork($networkCode);
            $contract->setCreatedAt(new \DateTimeImmutable());
        }

        $contract->setContractIdHex($result['contractIdHex']);
        $contract->setSourceCodeVerified(is_string($result['contractSourceCode']) && $result['contractSourceCode'] !== '');

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $sourceKey = is_string($result['wasmId'] ?? null) && $result['wasmId'] !== ''
            ? $result['wasmId']
            : $result['wasmSha256'];
        $checkedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->entityManager->getConnection()->update(
            'contracts',
            [
                'wasm_id' => $sourceKey,
                'sep55_verified' => $sep55Verification['isVerified'] ? 1 : 0,
                'github_address' => $sep55Verification['githubAddress'],
                'sep55_commit_hash' => $sep55Verification['commitHash'],
                'sep55_attestation_url' => $sep55Verification['attestationUrl'],
                'sep55_error' => $sep55Verification['error'],
                'sep55_last_checked_at' => $checkedAt,
            ],
            ['id' => (int) $contract->getId()],
            [
                'id' => ParameterType::INTEGER,
                'sep55_verified' => ParameterType::INTEGER,
                'github_address' => $sep55Verification['githubAddress'] !== null ? ParameterType::STRING : ParameterType::NULL,
                'sep55_commit_hash' => $sep55Verification['commitHash'] !== null ? ParameterType::STRING : ParameterType::NULL,
                'sep55_attestation_url' => $sep55Verification['attestationUrl'] !== null ? ParameterType::STRING : ParameterType::NULL,
                'sep55_error' => $sep55Verification['error'] !== null ? ParameterType::STRING : ParameterType::NULL,
                'sep55_last_checked_at' => ParameterType::STRING,
            ],
        );
    }

    /**
     * @param array{contractId:string,wasmCodeBase64:?string} $result
     */
    private function saveWasmIfRequested(array $result, bool $saveWasm): ?string
    {
        if (!$saveWasm) {
            return null;
        }

        $wasmCodeBase64 = is_string($result['wasmCodeBase64'] ?? null) ? $result['wasmCodeBase64'] : null;
        if ($wasmCodeBase64 === null || $wasmCodeBase64 === '') {
            return null;
        }

        $wasmBytes = base64_decode($wasmCodeBase64, true);
        if (!is_string($wasmBytes) || $wasmBytes === '') {
            return null;
        }

        $projectRoot = dirname(__DIR__, 3);
        $targetDir = $projectRoot . '/var/wasm';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $targetPath = sprintf('%s/%s.wasm', $targetDir, $result['contractId']);
        $written = @file_put_contents($targetPath, $wasmBytes);
        if (!is_int($written) || $written <= 0) {
            return null;
        }

        return $targetPath;
    }

    /**
     * @param array<string,mixed> $result
     * @return array{
     *   isVerified:bool,
     *   githubAddress:?string,
     *   commitHash:?string,
     *   attestationUrl:?string,
     *   error:?string
     * }
     */
    private function runSep55Verification(array $result): array
    {
        $wasmSha256 = is_string($result['wasmSha256'] ?? null) ? strtolower(trim((string) $result['wasmSha256'])) : '';
        $wasmCodeBase64 = is_string($result['wasmCodeBase64'] ?? null) ? trim((string) $result['wasmCodeBase64']) : '';
        if ($wasmSha256 === '' || $wasmCodeBase64 === '') {
            return [
                'isVerified' => false,
                'githubAddress' => null,
                'commitHash' => null,
                'attestationUrl' => null,
                'error' => 'WASM bytes are missing for SEP-55 verification.',
            ];
        }

        $wasmBytes = base64_decode($wasmCodeBase64, true);
        if (!is_string($wasmBytes) || $wasmBytes === '') {
            return [
                'isVerified' => false,
                'githubAddress' => null,
                'commitHash' => null,
                'attestationUrl' => null,
                'error' => 'Invalid WASM base64 payload.',
            ];
        }

        $verified = $this->sep55ContractVerificationService->verifyFromWasm($wasmSha256, $wasmBytes);

        return [
            'isVerified' => (bool) ($verified['isVerified'] ?? false),
            'githubAddress' => is_string($verified['githubAddress'] ?? null) ? $verified['githubAddress'] : null,
            'commitHash' => is_string($verified['commitHash'] ?? null) ? $verified['commitHash'] : null,
            'attestationUrl' => is_string($verified['attestationUrl'] ?? null) ? $verified['attestationUrl'] : null,
            'error' => is_string($verified['error'] ?? null) ? $verified['error'] : null,
        ];
    }
}
