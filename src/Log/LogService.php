<?php

namespace Baige\Monthpay\Log;

class LogService
{
    private $channel;
    private $logDir;
    private $fileName;

    public function __construct(string $channel = 'default', string $logPath = null)
    {
        $this->channel = $channel;
        $this->logDir = $logPath ?? dirname(__DIR__, 2) . '/logs';
        $this->fileName = date('Y-m-d').'.txt';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    protected function writeLog(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[{$timestamp}] [{$this->channel}] [{$level}] {$message} {$contextStr}" . PHP_EOL;

        file_put_contents($this->logDir . '/' . $this->fileName, $logLine, FILE_APPEND);
    }

    public function info(string $message, array $context = []): void
    {
        $this->writeLog('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->writeLog('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->writeLog('DEBUG', $message, $context);
    }

    public function setFileName(string $name): void
    {
        $this->fileName = $name;
    }
}
