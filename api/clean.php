<?php
// api/clean.php
// Deletes oldest files in uploads/ until folder is under MAX_BYTES MB.
// Usage: php clean.php [--dry-run]
declare(strict_types=1);

define('APP_ROOT', __DIR__);

const MAX_BYTES  = 200 * 1024 * 1024; //MAX_BYTES MB
const UPLOAD_DIR = __DIR__ . '/uploads/';

$dryRun = in_array('--dry-run', $argv ?? [], true);

function folderSize(string $path): int
{
    $total = 0;
    $iter  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile()) $total += $file->getSize();
    }
    return $total;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

$currentSize = folderSize(UPLOAD_DIR);
echo "Current uploads size: " . formatBytes($currentSize) . "\n";

if ($currentSize <= MAX_BYTES) {
    echo "Under MAX_BYTES MB — nothing to do.\n";
    exit(0);
}

// Collect all files with their modification time, skip system files.
$files = [];
$iter  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if (!$file->isFile()) continue;
    if (str_starts_with($file->getFilename(), '.')) continue;
    $files[] = ['path' => $file->getPathname(), 'mtime' => $file->getMTime(), 'size' => $file->getSize()];
}

// Sort oldest first.
usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

$deletedFiles = 0;
$freedBytes   = 0;

foreach ($files as $f) {
    if ($currentSize <= MAX_BYTES) break;

    if (!$dryRun) unlink($f['path']);
    $currentSize  -= $f['size'];
    $freedBytes   += $f['size'];
    $deletedFiles++;

    echo ($dryRun ? '[dry-run] ' : '') . "Deleted: " . basename($f['path']) . " (" . formatBytes($f['size']) . ") → uploads now: " . formatBytes($currentSize) . "\n";
}

echo "\n--- Done ---\n";
echo "Files deleted : $deletedFiles\n";
echo "Space freed   : " . formatBytes($freedBytes) . "\n";
echo "Final size    : " . formatBytes($currentSize) . "\n";
