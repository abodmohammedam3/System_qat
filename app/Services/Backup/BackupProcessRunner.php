<?php

namespace App\Services\Backup;

use Symfony\Component\Process\Process;
use RuntimeException;
use InvalidArgumentException;

class BackupProcessRunner
{
    /**
     * إنشاء ملف اعتمادات مؤقت (--defaults-extra-file) لتمرير بيانات MySQL
     * دون ظهور كلمة المرور في قائمة العمليات ps/process list (الفحص F-15).
     * الملف بصلاحيات 0600 ويُحذف تلقائياً بعد انتهاء العملية.
     *
     * @return string المسار المطلوب تمريره كأول وسيط للأمر
     */
    private static function createDefaultsFile(string $user, string $pass): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mycnf_');

        if ($path === false) {
            throw new RuntimeException('Unable to create temporary defaults file for MySQL client');
        }

        $content = "[client]\n"
            . "user={$user}\n"
            . "password={$pass}\n";

        file_put_contents($path, $content);
        @chmod($path, 0600);

        return $path;
    }

    private static function deleteDefaultsFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Run mysqldump command to produce a .sql dump file.
     */
    public function runDump(string $binaryPath, string $outputPath, array $dbConfig, int $timeout = 600): void
    {
        if (empty($binaryPath) || !file_exists($binaryPath)) {
            throw new RuntimeException("mysqldump binary path is invalid or missing: {$binaryPath}");
        }

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = (string) ($dbConfig['port'] ?? 3306);
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';
        $database = $dbConfig['database'] ?? '';

        if (empty($database)) {
            throw new InvalidArgumentException("Database name is not configured.");
        }

        // بيانات الاعتماد عبر ملف مؤقت 0600 — لا كلمة مرور في سطر الأوامر (F-15)
        $defaultsFile = self::createDefaultsFile($user, $pass);

        $command = [
            $binaryPath,
            "--defaults-extra-file={$defaultsFile}",
            "--host={$host}",
            "--port={$port}",
        ];

        $command[] = '--single-transaction';
        $command[] = '--routines';
        $command[] = '--triggers';

        // استبعاد الجداول النظامية من النسخة (لا wildcards — خيار لكل جدول)
        // حتى لا يحذف الاستيراد القديم هذه الجداول عبر DROP TABLE في اللقطة
        foreach ((array) config('backup.excluded_tables', []) as $protectedTable) {
            if (is_string($protectedTable) && $protectedTable !== '') {
                $command[] = "--ignore-table={$database}.{$protectedTable}";
            }
        }

        $command[] = $database;

        $handle = @fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Unable to open output file for writing: {$outputPath}");
        }

        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            
            $process->run(function ($type, $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (!$process->isSuccessful()) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                throw new RuntimeException("mysqldump process failed: " . trim($process->getErrorOutput()));
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            throw $e;
        } finally {
            self::deleteDefaultsFile($defaultsFile);
        }
    }

    /**
     * Run mysql import command from a .sql dump file.
     */
    public function runRestore(string $binaryPath, string $inputPath, array $dbConfig, int $timeout = 600): void
    {
        if (empty($binaryPath) || !file_exists($binaryPath)) {
            throw new RuntimeException("mysql binary path is invalid or missing: {$binaryPath}");
        }

        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Backup import file does not exist: {$inputPath}");
        }

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = (string) ($dbConfig['port'] ?? 3306);
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';
        $database = $dbConfig['database'] ?? '';

        if (empty($database)) {
            throw new InvalidArgumentException("Database name is not configured.");
        }

        // بيانات الاعتماد عبر ملف مؤقت 0600 — لا كلمة مرور في سطر الأوامر (F-15)
        $defaultsFile = self::createDefaultsFile($user, $pass);

        $command = [
            $binaryPath,
            "--defaults-extra-file={$defaultsFile}",
            "--host={$host}",
            "--port={$port}",
        ];

        $command[] = '--default-character-set=utf8mb4';
        $command[] = $database;

        $handle = @fopen($inputPath, 'r');
        if (!$handle) {
            throw new RuntimeException("Unable to open input file for reading: {$inputPath}");
        }

        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->setInput($handle);
            $process->run();

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (!$process->isSuccessful()) {
                throw new RuntimeException("mysql restore process failed: " . trim($process->getErrorOutput()));
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $e;
        } finally {
            self::deleteDefaultsFile($defaultsFile);
        }
    }
}

