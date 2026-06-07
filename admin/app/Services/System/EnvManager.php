<?php

namespace App\Services\System;

use RuntimeException;

/**
 * Read + safely edit env files (Laravel .env, voice-engine/.env, etc).
 *
 * Use cases:
 *   - Brain-settings admin page writes LLM provider + creds + device
 *     into voice-engine/.env so Python picks them up at next restart
 *     (or via /admin/reload if we wire that up).
 *
 * Behaviour notes:
 *   - Preserves comments, blank lines, and ordering of existing keys.
 *   - Adds new keys at the end with a section comment.
 *   - Writes atomically (temp file + rename) so a crash mid-write
 *     doesn't leave the .env corrupted.
 *   - Values are NOT shell-quoted; if a value contains spaces or
 *     special characters, the caller is responsible for quoting.
 */
class EnvManager
{
    public function __construct(private string $envPath) {}

    /** Path to the .env file we manage. */
    public function path(): string
    {
        return $this->envPath;
    }

    /**
     * Read the entire .env into a key → value map. Comments + blanks
     * are dropped from the map (they're preserved on write via raw
     * line storage in :meth:`set` / :meth:`setMany`).
     */
    public function all(): array
    {
        if (!is_file($this->envPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->envPath, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = $this->unquote(trim($v));
        }
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Set (or update) one key. See setMany() for the multi-key path
     * which is far more efficient for >1 update.
     */
    public function set(string $key, string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Set multiple keys at once. Existing keys are updated in-place
     * (preserving their position + surrounding comments); new keys
     * are appended at the bottom of the file under a section header.
     */
    public function setMany(array $kv): void
    {
        if (empty($kv)) return;

        $lines = is_file($this->envPath)
            ? file($this->envPath, FILE_IGNORE_NEW_LINES)
            : [];

        $remaining = $kv;
        foreach ($lines as $i => $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#') || !str_contains($trim, '=')) {
                continue;
            }
            [$k] = explode('=', $trim, 2);
            $k = trim($k);
            if (array_key_exists($k, $remaining)) {
                $lines[$i] = $k . '=' . $remaining[$k];
                unset($remaining[$k]);
            }
        }

        if (!empty($remaining)) {
            $lines[] = '';
            $lines[] = '# Managed by admin > Brain Settings (' . date('Y-m-d H:i') . ')';
            foreach ($remaining as $k => $v) {
                $lines[] = $k . '=' . $v;
            }
        }

        $content = implode("\n", $lines) . "\n";
        $tmp = $this->envPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Could not write temp env file: {$tmp}");
        }
        if (!@rename($tmp, $this->envPath)) {
            @unlink($tmp);
            throw new RuntimeException("Could not move temp env to: {$this->envPath}");
        }
    }

    private function unquote(string $v): string
    {
        if (strlen($v) >= 2) {
            $first = $v[0];
            $last  = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($v, 1, -1);
            }
        }
        return $v;
    }
}
