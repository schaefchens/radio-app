<?php
declare(strict_types=1);

namespace Arche\Support;

final class Files
{
    /**
     * Write through a temp file and rename, so a reader never sees half a
     * file. With $overwrite false an existing file is left untouched and false
     * is returned — the rule for immutable minute files.
     */
    public static function write(string $path, string $contents, bool $overwrite = true, int $mode = 0644): bool
    {
        if (!$overwrite && is_file($path)) return false;
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException('Cannot write ' . basename($path));
        }
        @chmod($tmp, $mode);
        if (!$overwrite && is_file($path)) {
            @unlink($tmp);
            return false;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot move ' . basename($path) . ' into place');
        }
        return true;
    }

    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * $dir with exactly $mode, whatever the umask (bootstrap sets 0027, so a
     * plain mkdir 0755 gives 0750). On the host the web server is another
     * user than PHP: a 0750 directory under /program answers every file in it
     * with 403. Missing levels are created with $mode; an existing $dir with
     * other permissions is repaired (its parents are left alone — the web
     * root and /_arche are not ours to change).
     */
    public static function ensureDir(string $dir, int $mode = 0755): void
    {
        if (is_dir($dir)) {
            $perms = @fileperms($dir);
            if ($perms !== false && ($perms & 0777) !== $mode) @chmod($dir, $mode);
            return;
        }
        $missing = [];
        for ($d = $dir; !is_dir($d) && $d !== dirname($d); $d = dirname($d)) $missing[] = $d;
        foreach (array_reverse($missing) as $d) {
            if (!@mkdir($d, $mode) && !is_dir($d)) throw new \RuntimeException('Cannot create directory ' . $dir);
            @chmod($d, $mode);
        }
    }

    /**
     * Delete regular files under $dir older than $beforeTs, at most $limit per
     * call, then remove directories left empty. Returns how many were removed.
     * Bounded so a tick never spends its budget on housekeeping.
     *
     * @param (callable(string):void)|null $onDelete told the path of every file removed
     */
    public static function prune(string $dir, int $beforeTs, int $limit, ?callable $onDelete = null): int
    {
        if (!is_dir($dir) || $limit <= 0) return 0;
        $removed = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir()) {
                @rmdir($f->getPathname()); // only succeeds when empty
                continue;
            }
            if ($f->getFilename()[0] === '.') continue; // .htaccess and friends
            if ($f->getMTime() < $beforeTs) {
                if (@unlink($f->getPathname())) {
                    $removed++;
                    if ($onDelete !== null) $onDelete($f->getPathname());
                }
                if ($removed >= $limit) break;
            }
        }
        return $removed;
    }
}
