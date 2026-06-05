<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Console;

use App\Modules\ContactFinder\DTO\ContactResult;
use App\Modules\ContactFinder\Providers\MockEnrichmentProvider;
use App\Modules\ContactFinder\Providers\MockListingProvider;
use App\Modules\ContactFinder\Providers\MockProviderRepository;
use App\Modules\ContactFinder\Providers\MockRegistryProvider;
use App\Modules\ContactFinder\Services\ConfidenceScorer;
use App\Modules\ContactFinder\Services\ContactFinderService;
use App\Modules\ContactFinder\Services\ContactReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The slice's entry point. Reads the company CSV, runs each row through the
 * mocked providers, and writes two artifacts:
 *   - contacts.csv   the six required fields (+ status/reason) for humans
 *   - contacts.jsonl the same plus full provenance (source_urls, raw, score breakdown)
 *
 * Synchronous on purpose for ~30 rows; at 1k+ rows this becomes one queued job
 * per row (the per-row design already supports it).
 */
final class FindContactsCommand extends Command
{
    protected $signature = 'contacts:find
        {--input=challenge/data/companies.csv : CSV of company_name,mailing_address}
        {--mocks=challenge/mocks/enrichment_responses.json : Mock provider fixture}
        {--out=output : Output directory for contacts.csv + contacts.jsonl}';

    protected $description = 'Find one decision-maker contact per company from the mocked providers, with confidence and provenance.';

    public function handle(): int
    {
        $inputPath = $this->resolvePath((string) $this->option('input'));
        $mocksPath = $this->resolvePath((string) $this->option('mocks'));

        if (! is_file($inputPath)) {
            $this->warn("Input CSV missing at {$inputPath}");

            return self::INVALID;
        }
        if (! is_file($mocksPath)) {
            $this->warn("Mock fixture missing at {$mocksPath}");

            return self::INVALID;
        }

        $outDir = $this->resolveOutDir((string) $this->option('out'));

        $repository = new MockProviderRepository($mocksPath);
        $service = new ContactFinderService(
            [
                new MockRegistryProvider($repository),
                new MockListingProvider($repository),
                new MockEnrichmentProvider($repository),
            ],
            new ContactReconciler(new ConfidenceScorer()),
        );

        $results = $service->findForCompanies($this->readCompanies($inputPath));

        $this->writeCsv("{$outDir}/contacts.csv", $results);
        $this->writeJsonl("{$outDir}/contacts.jsonl", $results);
        $this->renderSummary($results, $outDir);

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function readCompanies(string $csvPath): array
    {
        $names = [];
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            return $names;
        }

        fgetcsv($handle); // header row
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0]) && trim((string) $row[0]) !== '') {
                $names[] = (string) $row[0];
            }
        }
        fclose($handle);

        return $names;
    }

    /** @param list<ContactResult> $results */
    private function writeCsv(string $path, array $results): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return;
        }

        $headerWritten = false;
        foreach ($results as $result) {
            $row = $result->toRow();
            if (! $headerWritten) {
                fputcsv($handle, array_keys($row));
                $headerWritten = true;
            }
            fputcsv($handle, array_values($row));
        }
        fclose($handle);
    }

    /** @param list<ContactResult> $results */
    private function writeJsonl(string $path, array $results): void
    {
        $lines = array_map(
            static fn (ContactResult $r): string => (string) json_encode(
                $r->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            $results,
        );

        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines) . "\n");
    }

    /** @param list<ContactResult> $results */
    private function renderSummary(array $results, string $outDir): void
    {
        $counts = [
            ContactResult::STATUS_VERIFIED => 0,
            ContactResult::STATUS_NEEDS_REVIEW => 0,
            ContactResult::STATUS_CONFLICTING => 0,
            ContactResult::STATUS_UNVERIFIED => 0,
        ];
        foreach ($results as $result) {
            $counts[$result->status]++;
        }
        $total = count($results);

        $this->info("Processed {$total} companies.");
        $this->line(sprintf(
            '  verified: %d   needs_review: %d   conflicting: %d   unverified: %d',
            $counts[ContactResult::STATUS_VERIFIED],
            $counts[ContactResult::STATUS_NEEDS_REVIEW],
            $counts[ContactResult::STATUS_CONFLICTING],
            $counts[ContactResult::STATUS_UNVERIFIED],
        ));
        $this->line("Wrote {$outDir}/contacts.csv and {$outDir}/contacts.jsonl");

        Log::info('contacts:find completed', ['total' => $total] + $counts);
    }

    private function resolvePath(string $path): string
    {
        return is_file($path) ? $path : base_path($path);
    }

    private function resolveOutDir(string $path): string
    {
        $dir = $this->isAbsolute($path) ? $path : base_path($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return rtrim($dir, '/\\');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
