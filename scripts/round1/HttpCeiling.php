<?php

class Round1HttpCeiling
{
    public function __construct(private string $ledgerPath, private int $limit) {}

    public function before(string $url): void
    {
        if (! str_contains($url, 'generativelanguage.googleapis.com')) {
            return;
        }
        if ($this->limit < 1) {
            throw new RuntimeException('Round 1 HTTP ceiling is not set.');
        }

        $count = $this->attempts();
        if ($count >= $this->limit) {
            throw new RuntimeException('Round 1 HTTP ceiling reached before this request.');
        }

        $this->write($count + 1);
    }

    public function attempts(): int
    {
        if (! is_file($this->ledgerPath)) {
            return 0;
        }
        $decoded = json_decode((string) file_get_contents($this->ledgerPath), true);

        return is_array($decoded) ? (int) ($decoded['attempts'] ?? 0) : 0;
    }

    /** @param array<string, mixed>|null $usage @return array{stop: ?string, spent: float} */
    public function assess(?array $usage, float $spent, float $limit, string $model): array
    {
        $input = $usage['total_input_tokens'] ?? null;
        $output = $usage['total_output_tokens'] ?? null;
        $thought = $usage['total_thought_tokens'] ?? null;
        $cached = $usage['total_cached_tokens'] ?? null;
        if ($model !== 'gemini-3.1-flash-lite'
            || ! is_int($input) || ! is_int($output) || ! is_int($thought) || ! is_int($cached)
            || $input < 0 || $output < 0 || $thought < 0 || $cached < 0 || $cached > $input) {
            return ['stop' => 'usage or cost unavailable', 'spent' => $spent];
        }

        $spent = round($spent + (($input - $cached) * 0.25 + $cached * 0.025 + ($output + $thought) * 1.50) / 1_000_000, 6);
        if ($spent > $limit) {
            return ['stop' => 'spend stop after this request', 'spent' => $spent];
        }

        return ['stop' => null, 'spent' => $spent];
    }

    public function write(int $attempts): void
    {
        $directory = dirname($this->ledgerPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($this->ledgerPath, json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
