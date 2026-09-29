<?php
declare(strict_types=1);

final class AggregationService
{
    public const DAILY_ROW_LIMIT = 50000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function aggregateBatch(int $batchSize, string $timezoneName): int
    {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new InvalidArgumentException('Aggregation batch size is invalid.');
        }

        $timezone = new DateTimeZone($timezoneName);
        $databaseName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'pollyanna_agg_' . substr(hash('sha256', $databaseName), 0, 48);
        $lockStatement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $lockStatement->execute([':lock_name' => $lockName]);
        if ((int) $lockStatement->fetchColumn() !== 1) {
            return 0;
        }

        try {
            $this->pdo->beginTransaction();
            $select = $this->pdo->query(
                'SELECT id, identifier, created_at FROM pollyanna_likes '
                . 'ORDER BY id ASC LIMIT ' . $batchSize . ' FOR UPDATE'
            );
            $events = $select->fetchAll();
            if ($events === []) {
                $this->compactPeriodAggregates($batchSize, $timezone);
                $this->pdo->commit();
                return 0;
            }

            $lifetimeRows = [];
            $dailyRows = [];
            $hourlyRows = [];
            $eventIds = [];
            $utc = new DateTimeZone('UTC');

            foreach ($events as $event) {
                $eventIds[] = (int) $event['id'];
                $identifier = (string) $event['identifier'];
                $localTime = (new DateTimeImmutable((string) $event['created_at'], $utc))
                    ->setTimezone($timezone);
                $date = $localTime->format('Y-m-d');
                $monthStart = $localTime->format('Y-m-01');
                $hour = (int) $localTime->format('G');

                $lifetimeRows[$identifier] = ($lifetimeRows[$identifier] ?? 0) + 1;
                $dailyKey = $date . "\0" . $identifier;
                $dailyRows[$dailyKey] = [$date, $identifier, ($dailyRows[$dailyKey][2] ?? 0) + 1];
                $hourlyKey = $monthStart . "\0" . $hour;
                $hourlyRows[$hourlyKey] = [$monthStart, $hour, ($hourlyRows[$hourlyKey][2] ?? 0) + 1];
            }

            $this->incrementRows(
                'pollyanna_lifetime_totals',
                ['identifier', 'total_likes'],
                array_map(
                    static fn (string $identifier, int $count): array => [$identifier, $count],
                    array_keys($lifetimeRows),
                    array_values($lifetimeRows)
                ),
                'total_likes'
            );

            $this->incrementRows(
                'pollyanna_period_aggregates',
                ['period_type', 'period_start', 'identifier', 'like_count'],
                array_map(
                    static fn (array $row): array => ['day', $row[0], $row[1], $row[2]],
                    array_values($dailyRows)
                ),
                'like_count'
            );

            $this->incrementRows(
                'pollyanna_hourly_aggregates',
                ['month_start', 'hour_of_day', 'like_count'],
                array_values($hourlyRows),
                'like_count'
            );

            $idPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
            $delete = $this->pdo->prepare('DELETE FROM pollyanna_likes WHERE id IN (' . $idPlaceholders . ')');
            $delete->execute($eventIds);

            $currentMonth = (new DateTimeImmutable('now', $timezone))->format('Y-m-01');
            $oldestMonth = (new DateTimeImmutable($currentMonth, $timezone))
                ->modify('-23 months')
                ->format('Y-m-01');
            $prune = $this->pdo->prepare(
                'DELETE FROM pollyanna_hourly_aggregates WHERE month_start < :oldest_month'
            );
            $prune->execute([':oldest_month' => $oldestMonth]);

            $this->compactPeriodAggregates($batchSize, $timezone);

            $this->pdo->commit();
            return count($eventIds);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            try {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute([':lock_name' => $lockName]);
            } catch (Throwable $releaseError) {
                error_log('Pollyanna aggregation lock release failed: ' . $releaseError->getMessage());
            }
        }
    }

    private function incrementRows(string $table, array $columns, array $rows, string $counterColumn): void
    {
        if ($rows === []) {
            return;
        }

        $columnCount = count($columns);
        $tuple = '(' . implode(',', array_fill(0, $columnCount, '?')) . ')';
        $tuples = implode(',', array_fill(0, count($rows), $tuple));
        $quotedColumns = implode(',', $columns);
        $sql = 'INSERT INTO ' . $table . ' (' . $quotedColumns . ') VALUES ' . $tuples
            . ' ON DUPLICATE KEY UPDATE ' . $counterColumn . ' = '
            . $counterColumn . ' + VALUES(' . $counterColumn . ')';
        $parameters = [];
        foreach ($rows as $row) {
            array_push($parameters, ...$row);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
    }

    private function compactPeriodAggregates(int $batchSize, DateTimeZone $timezone): void
    {
        $today = new DateTimeImmutable('today', $timezone);
        $dailyCutoff = $today->modify('-5 years')->format('Y-m-d');
        $this->rollUpPeriodRows('day', $dailyCutoff, 'month', $batchSize);

        $dailyCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pollyanna_period_aggregates WHERE period_type = 'day'"
        )->fetchColumn();
        if ($dailyCount > self::DAILY_ROW_LIMIT) {
            $excess = min($batchSize, $dailyCount - self::DAILY_ROW_LIMIT);
            $this->rollUpPeriodRows('day', null, 'month', $excess);
        }

        $monthStart = new DateTimeImmutable('first day of this month', $timezone);
        $monthlyCutoff = $monthStart->modify('-5 years')->format('Y-m-d');
        $this->rollUpPeriodRows('month', $monthlyCutoff, 'year', $batchSize);
    }

    private function rollUpPeriodRows(
        string $sourceType,
        ?string $cutoff,
        string $destinationType,
        int $limit
    ): void {
        if ($limit < 1) {
            return;
        }

        $sql = 'SELECT period_start, identifier, like_count '
            . 'FROM pollyanna_period_aggregates WHERE period_type = :period_type';
        if ($cutoff !== null) {
            $sql .= ' AND period_start < :cutoff';
        }
        $sql .= ' ORDER BY period_start ASC, identifier ASC LIMIT ' . $limit . ' FOR UPDATE';
        $select = $this->pdo->prepare($sql);
        $select->bindValue(':period_type', $sourceType, PDO::PARAM_STR);
        if ($cutoff !== null) {
            $select->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        }
        $select->execute();
        $rows = $select->fetchAll();
        if ($rows === []) {
            return;
        }

        $groupedRows = [];
        foreach ($rows as $row) {
            $sourceDate = (string) $row['period_start'];
            $identifier = (string) $row['identifier'];
            $destinationStart = $destinationType === 'month'
                ? substr($sourceDate, 0, 7) . '-01'
                : substr($sourceDate, 0, 4) . '-01-01';
            $key = $destinationStart . "\0" . $identifier;
            if (!isset($groupedRows[$key])) {
                $groupedRows[$key] = [$destinationStart, $identifier, 0];
            }
            $groupedRows[$key][2] += (int) $row['like_count'];
        }

        $this->incrementRows(
            'pollyanna_period_aggregates',
            ['period_type', 'period_start', 'identifier', 'like_count'],
            array_map(
                static fn (array $row): array => [$destinationType, $row[0], $row[1], $row[2]],
                array_values($groupedRows)
            ),
            'like_count'
        );

        $selectors = implode(' OR ', array_fill(0, count($rows), '(period_start = ? AND identifier = ?)'));
        $delete = $this->pdo->prepare(
            'DELETE FROM pollyanna_period_aggregates WHERE period_type = ? AND (' . $selectors . ')'
        );
        $parameters = [$sourceType];
        foreach ($rows as $row) {
            $parameters[] = $row['period_start'];
            $parameters[] = $row['identifier'];
        }
        $delete->execute($parameters);
    }
}
