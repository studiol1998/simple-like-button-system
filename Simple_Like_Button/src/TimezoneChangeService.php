<?php
declare(strict_types=1);

final class TimezoneChangeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function begin(string $newTimezone): array
    {
        try {
            new DateTimeZone($newTimezone);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('The selected timezone is invalid.', 0, $error);
        }
        if (pollyanna_timezone_change_state() !== null) {
            throw new RuntimeException('A timezone change is already in progress.');
        }

        $settings = pollyanna_aggregation_settings();
        if ($newTimezone === $settings['timezone']) {
            throw new InvalidArgumentException('The selected timezone is already active.');
        }
        $state = [
            'old_timezone' => $settings['timezone'],
            'new_timezone' => $newTimezone,
            'previous_system_state' => pollyanna_system_state(),
            'started_at' => gmdate('c'),
            'pending_before_aggregation' => (new LikeService($this->pdo))->pendingCount(),
            'phase' => 'aggregating',
        ];
        pollyanna_save_timezone_change_state($state);
        try {
            pollyanna_save_system_state('maintenance');
            if (!pollyanna_acquire_like_request_lock($this->pdo, 20)) {
                throw new RuntimeException('Could not pause Like requests for the timezone change.');
            }
            pollyanna_release_like_request_lock($this->pdo);
        } catch (Throwable $error) {
            pollyanna_save_system_state($state['previous_system_state']);
            pollyanna_clear_timezone_change_state();
            throw $error;
        }

        return ['old_timezone' => $settings['timezone'], 'new_timezone' => $newTimezone];
    }

    public function aggregateNextBatch(): array
    {
        $state = $this->requireState();
        $pendingBefore = (new LikeService($this->pdo))->pendingCount();
        $processed = 0;
        if ($pendingBefore > 0) {
            $settings = pollyanna_aggregation_settings();
            $processed = (new AggregationService($this->pdo))->aggregateBatch(
                min($settings['aggregation_batch_size'], 500),
                $state['old_timezone']
            );
        }
        $pendingAfter = (new LikeService($this->pdo))->pendingCount();
        return [
            'phase' => 'aggregating',
            'processed' => $processed,
            'pending' => $pendingAfter,
            'old_timezone' => $state['old_timezone'],
            'new_timezone' => $state['new_timezone'],
            'ready_to_archive' => $pendingAfter === 0,
        ];
    }

    public function archiveAndReset(): array
    {
        $state = $this->requireState();
        if (!pollyanna_acquire_like_request_lock($this->pdo, 20)) {
            throw new RuntimeException('Could not pause Like requests while saving the archive.');
        }

        $committed = false;
        try {
            if ((new LikeService($this->pdo))->pendingCount() !== 0) {
                throw new RuntimeException('Pending Likes remain. Aggregate them before saving the archive.');
            }
            $archiveDirectory = $this->writeArchive($state);
            $this->pdo->beginTransaction();
            if ((new LikeService($this->pdo))->pendingCount() !== 0) {
                throw new RuntimeException('New pending Likes appeared before the data reset.');
            }
            $this->pdo->exec('DELETE FROM pollyanna_lifetime_totals');
            $this->pdo->exec('DELETE FROM pollyanna_period_aggregates');
            $this->pdo->exec('DELETE FROM pollyanna_hourly_aggregates');
            pollyanna_save_timezone($state['new_timezone']);
            $this->pdo->commit();
            $committed = true;

            $state['phase'] = 'committed';
            $state['archive_directory'] = $archiveDirectory;
            pollyanna_save_timezone_change_state($state);
            pollyanna_save_system_state($state['previous_system_state']);
            pollyanna_clear_timezone_change_state();
            return ['timezone' => $state['new_timezone'], 'archive_directory' => $archiveDirectory];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (!$committed) {
                pollyanna_save_timezone($state['old_timezone']);
            }
            throw $error;
        } finally {
            pollyanna_release_like_request_lock($this->pdo);
        }
    }

    public function recoverCommittedChange(): ?array
    {
        $state = pollyanna_timezone_change_state();
        if ($state === null) {
            return null;
        }
        $settings = pollyanna_aggregation_settings();
        if ($state['phase'] === 'committed') {
            if ($settings['timezone'] !== $state['new_timezone']) {
                throw new RuntimeException('The interrupted timezone change needs manual review.');
            }
            pollyanna_clear_timezone_change_state();
            pollyanna_save_system_state($state['previous_system_state']);
            return ['timezone' => $state['new_timezone'], 'archive_directory' => $state['archive_directory'] ?? ''];
        }
        $isEmpty = $this->aggregateTablesAreEmpty();
        if (
            $state['phase'] !== 'committed'
            && $settings['timezone'] === $state['new_timezone']
            && !$isEmpty
        ) {
            pollyanna_save_timezone($state['old_timezone']);
            return null;
        }
        if ($settings['timezone'] !== $state['new_timezone']) {
            return null;
        }
        if ($settings['timezone'] !== $state['new_timezone'] || !$isEmpty) {
            throw new RuntimeException('The interrupted timezone change needs manual review.');
        }
        pollyanna_clear_timezone_change_state();
        pollyanna_save_system_state($state['previous_system_state']);
        return ['timezone' => $state['new_timezone'], 'archive_directory' => $state['archive_directory'] ?? ''];
    }

    private function requireState(): array
    {
        $state = pollyanna_timezone_change_state();
        if ($state === null || pollyanna_system_state() !== 'maintenance') {
            throw new RuntimeException('No active timezone change is in progress.');
        }
        return $state;
    }

    private function aggregateTablesAreEmpty(): bool
    {
        foreach (['pollyanna_lifetime_totals', 'pollyanna_period_aggregates', 'pollyanna_hourly_aggregates'] as $table) {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                return false;
            }
        }
        return (new LikeService($this->pdo))->pendingCount() === 0;
    }

    private function writeArchive(array $state): string
    {
        $projectDirectory = dirname(__DIR__);
        $logDirectory = $projectDirectory . '/log';
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
            throw new RuntimeException('The archive directory could not be created.');
        }
        $denyRule = "Require all denied\n";
        $accessFile = $logDirectory . '/.htaccess';
        if (is_file($accessFile)) {
            $existingRule = file_get_contents($accessFile);
            if ($existingRule === false || !str_contains($existingRule, 'Require all denied')) {
                throw new RuntimeException('The archive directory is not protected from web access.');
            }
        } elseif (file_put_contents($accessFile, $denyRule, LOCK_EX) === false) {
            throw new RuntimeException('The archive directory could not be protected from web access.');
        }
        if (file_put_contents($logDirectory . '/index.html', '', LOCK_EX) === false) {
            throw new RuntimeException('The archive directory could not be protected from directory listing.');
        }

        $stamp = gmdate('Ymd\THis\Z');
        $suffix = bin2hex(random_bytes(4));
        $temporary = $logDirectory . '/.timezone-' . $stamp . '-' . $suffix . '.tmp';
        $finalName = $stamp . '-' . $suffix;
        $final = $logDirectory . '/' . $finalName;
        if (!mkdir($temporary, 0750)) {
            throw new RuntimeException('A temporary archive directory could not be created.');
        }
        try {
            $this->writeCsv($temporary . '/lifetime_totals.csv',
                ['identifier', 'total_likes'],
                'SELECT identifier, total_likes FROM pollyanna_lifetime_totals ORDER BY identifier');
            $this->writeCsv($temporary . '/period_aggregates.csv',
                ['period_type', 'period_start', 'identifier', 'like_count'],
                'SELECT period_type, period_start, identifier, like_count FROM pollyanna_period_aggregates '
                . 'ORDER BY period_type, period_start, identifier');
            $this->writeCsv($temporary . '/hourly_aggregates.csv',
                ['month_start', 'hour_of_day', 'like_count'],
                'SELECT month_start, hour_of_day, like_count FROM pollyanna_hourly_aggregates '
                . 'ORDER BY month_start, hour_of_day');
            $metadata = [
                'changed_at' => gmdate('c'),
                'timezone_change_started_at' => $state['started_at'] ?? null,
                'old_timezone' => $state['old_timezone'],
                'new_timezone' => $state['new_timezone'],
                'pending_records_before_aggregation' => (int) ($state['pending_before_aggregation'] ?? 0),
                'csv_identifier_safety' => 'Formula-leading identifiers are prefixed with an apostrophe.',
            ];
            $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (file_put_contents($temporary . '/metadata.json', $json, LOCK_EX) === false) {
                throw new RuntimeException('Archive metadata could not be saved.');
            }
            if (!rename($temporary, $final)) {
                throw new RuntimeException('The completed archive could not be finalized.');
            }
        } catch (Throwable $error) {
            $this->removeDirectory($temporary);
            throw $error;
        }
        return 'log/' . $finalName;
    }

    private function writeCsv(string $path, array $header, string $query): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('An archive CSV could not be opened for writing.');
        }
        try {
            if (fputcsv($handle, $header, ',', '"', '\\') === false) {
                throw new RuntimeException('An archive CSV header could not be written.');
            }
            foreach ($this->pdo->query($query) as $row) {
                $values = array_values($row);
                if (isset($values[0]) && is_string($values[0])) {
                    $values[0] = $this->spreadsheetSafeIdentifier($values[0]);
                }
                if (count($values) > 2 && is_string($values[2])) {
                    $values[2] = $this->spreadsheetSafeIdentifier($values[2]);
                }
                if (fputcsv($handle, $values, ',', '"', '\\') === false) {
                    throw new RuntimeException('An archive CSV row could not be written.');
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('An archive CSV could not be flushed to disk.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function spreadsheetSafeIdentifier(string $identifier): string
    {
        return preg_match('/\A[\x00-\x20]*[=+\-@]/', $identifier) === 1
            ? "'" . $identifier
            : $identifier;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
