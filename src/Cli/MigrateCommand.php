<?php

namespace Panda\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\FieldCipher;
use Panda\Crypto\Envelope;
use PDO;
use Exception;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';

    protected function configure(): void
    {
        $this
            ->setName('migrate')
            ->setDescription('Migrate plaintext columns in a database to encrypted envelopes in chunks')
            ->addArgument('table', InputArgument::REQUIRED, 'Database table name')
            ->addArgument('column', InputArgument::REQUIRED, 'Column name to encrypt')
            ->addArgument('kid', InputArgument::REQUIRED, 'Key Identifier to encrypt with')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of rows to process per chunk', '100')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between chunks in milliseconds', '100')
            ->addOption('pk', 'p', InputOption::VALUE_REQUIRED, 'Primary key column name', 'id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Estimate migration scope without modifying data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getArgument('table');
        $column = $input->getArgument('column');
        $kid = $input->getArgument('kid');
        $batchSize = (int)$input->getOption('batch-size');
        $delay = (int)$input->getOption('delay');
        $pk = $input->getOption('pk');
        $dryRun = $input->getOption('dry-run');

        // Load DB configurations from environment
        $connection = getenv('DB_CONNECTION') ?: 'mysql';
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306');
        $database = getenv('DB_DATABASE') ?: '';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        if (empty($database)) {
            $output->writeln("<error>Error: DB_DATABASE environment variable is not configured.</error>");
            return Command::FAILURE;
        }

        try {
            $dsn = "{$connection}:host={$host};port={$port};dbname={$database}";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (Exception $e) {
            $output->writeln("<error>Connection failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $isPgsql = ($connection === 'pgsql');

        // Ensure checkpoint table exists
        $this->ensureCheckpointTable($pdo, $isPgsql);

        // Load KeyProvider and FieldCipher
        $keyProvider = new LocalKeyProvider();
        $cipher = new FieldCipher($keyProvider);

        // Fetch last checkpoint
        $checkpoint = $this->getCheckpoint($pdo, $table, $column);
        $output->writeln("Migration target: <info>{$table}.{$column}</info> using KID: <info>{$kid}</info>");
        $output->writeln("Last processed primary key ID: <comment>" . ($checkpoint ?: 'None') . "</comment>");

        if ($dryRun) {
            $output->writeln("<comment>[DRY RUN] Scanning target table...</comment>");
            $this->runDryRun($pdo, $table, $column, $pk, $checkpoint, $output);
            return Command::SUCCESS;
        }

        // Run actual migration
        $this->runMigration($pdo, $cipher, $table, $column, $kid, $pk, $checkpoint, $batchSize, $delay, $isPgsql, $output);

        return Command::SUCCESS;
    }

    private function ensureCheckpointTable(PDO $pdo, bool $isPgsql): void
    {
        if ($isPgsql) {
            $sql = "CREATE TABLE IF NOT EXISTS panda_migration_state (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(128) NOT NULL,
                column_name VARCHAR(128) NOT NULL,
                last_processed_id VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT unique_target UNIQUE (table_name, column_name)
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS panda_migration_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(128) NOT NULL,
                column_name VARCHAR(128) NOT NULL,
                last_processed_id VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_target (table_name, column_name)
            )";
        }
        $pdo->exec($sql);
    }

    private function getCheckpoint(PDO $pdo, string $table, string $column): ?string
    {
        $stmt = $pdo->prepare("SELECT last_processed_id FROM panda_migration_state WHERE table_name = :table AND column_name = :column");
        $stmt->execute(['table' => $table, 'column' => $column]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    private function setCheckpoint(PDO $pdo, string $table, string $column, string $lastId, bool $isPgsql): void
    {
        if ($isPgsql) {
            $sql = "INSERT INTO panda_migration_state (table_name, column_name, last_processed_id) 
                    VALUES (:table, :column, :last_id) 
                    ON CONFLICT (table_name, column_name) 
                    DO UPDATE SET last_processed_id = EXCLUDED.last_processed_id, updated_at = CURRENT_TIMESTAMP";
        } else {
            $sql = "INSERT INTO panda_migration_state (table_name, column_name, last_processed_id) 
                    VALUES (:table, :column, :last_id) 
                    ON DUPLICATE KEY UPDATE last_processed_id = :last_id2";
        }

        $stmt = $pdo->prepare($sql);
        $params = ['table' => $table, 'column' => $column, 'last_id' => $lastId];
        if (!$isPgsql) {
            $params['last_id2'] = $lastId;
        }
        $stmt->execute($params);
    }

    private function runDryRun(PDO $pdo, string $table, string $column, string $pk, ?string $checkpoint, OutputInterface $output): void
    {
        $sql = "SELECT COUNT(*) FROM {$table}";
        $params = [];
        if ($checkpoint !== null) {
            $sql .= " WHERE {$pk} > :checkpoint";
            $params['checkpoint'] = $checkpoint;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $remainingCount = (int)$stmt->fetchColumn();

        $output->writeln("Total remaining rows to scan: <info>{$remainingCount}</info>");
        if ($remainingCount > 0) {
            // Sample a batch to estimate encrypted vs plaintext
            $sqlSample = "SELECT {$column} FROM {$table}";
            if ($checkpoint !== null) {
                $sqlSample .= " WHERE {$pk} > :checkpoint";
            }
            $sqlSample .= " ORDER BY {$pk} ASC LIMIT 100";

            $stmtSample = $pdo->prepare($sqlSample);
            $stmtSample->execute($params);
            $samples = $stmtSample->fetchAll();

            $encryptedCount = 0;
            $plaintextCount = 0;
            foreach ($samples as $sample) {
                $val = $sample[$column];
                if ($this->isEncrypted($val)) {
                    $encryptedCount++;
                } else {
                    $plaintextCount++;
                }
            }

            $estEncrypted = round(($encryptedCount / count($samples)) * $remainingCount);
            $estPlaintext = round(($plaintextCount / count($samples)) * $remainingCount);

            $output->writeln("Estimated already-encrypted rows: <comment>{$estEncrypted}</comment>");
            $output->writeln("Estimated plaintext rows to encrypt: <comment>{$estPlaintext}</comment>");
            $output->writeln("Estimated duration: <info>" . round($estPlaintext * 0.005, 2) . " seconds</info> (at 200 rows/sec)");
        }
    }

    private function isEncrypted(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        try {
            Envelope::fromJson($value);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function runMigration(
        PDO $pdo,
        FieldCipher $cipher,
        string $table,
        string $column,
        string $kid,
        string $pk,
        ?string $checkpoint,
        int $batchSize,
        int $delay,
        bool $isPgsql,
        OutputInterface $output
    ): void {
        $lastId = $checkpoint;
        $totalProcessed = 0;
        $totalEncrypted = 0;

        while (true) {
            $sql = "SELECT {$pk}, {$column} FROM {$table}";
            $params = [];
            if ($lastId !== null) {
                $sql .= " WHERE {$pk} > :last_id";
                $params['last_id'] = $lastId;
            }
            $sql .= " ORDER BY {$pk} ASC LIMIT {$batchSize}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            $pdo->beginTransaction();
            try {
                $currentBatchLastId = $lastId;
                foreach ($rows as $row) {
                    $currentBatchLastId = (string)$row[$pk];
                    $val = $row[$column];

                    if ($this->isEncrypted($val)) {
                        continue;
                    }

                    if ($val !== null && $val !== '') {
                        $envelope = $cipher->encrypt($val, $kid, 'migration-cli', "{$table}.{$column}");
                        $updateStmt = $pdo->prepare("UPDATE {$table} SET {$column} = :encrypted WHERE {$pk} = :id");
                        $updateStmt->execute([
                            'encrypted' => $envelope->toJson(),
                            'id' => $row[$pk]
                        ]);
                        $totalEncrypted++;
                    }
                }

                $lastId = $currentBatchLastId;
                $this->setCheckpoint($pdo, $table, $column, $lastId, $isPgsql);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $output->writeln("<error>Batch failed at ID {$currentBatchLastId}: {$e->getMessage()}</error>");
                throw $e;
            }

            $totalProcessed += count($rows);
            $output->writeln("Processed <comment>{$totalProcessed}</comment> rows... Encrypted: <comment>{$totalEncrypted}</comment>");

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $output->writeln("<info>Migration finished! Total rows processed: {$totalProcessed}, Encrypted: {$totalEncrypted}</info>");
    }
}
