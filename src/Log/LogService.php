<?php

namespace Baige\Monthpay\Log;

class LogService
{
    private $channel;
    private $logDir;
    private $maxFileSize;

    public function __construct(string $channel = 'default', $logPath = '', int $maxFileSize = 10485760)
    {
        $this->channel = $channel;
        $this->logDir =   !empty($logPath) ? $logPath :'./log';
        $this->maxFileSize = $maxFileSize; // 默认10MB

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    protected function writeLog(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[{$timestamp}] [{$this->channel}] [{$level}] {$message} {$contextStr}" . PHP_EOL;

        $filePath = $this->getLogFilePath();
        file_put_contents($filePath, $logLine, FILE_APPEND);
    }

    private function getLogFilePath(): string
    {
        $baseName = date('Y-m-d') . '.txt';
        $fullPath = $this->logDir . '/' . $baseName;

        // 如果当天文件未超过阈值，直接返回
        if (!file_exists($fullPath) || filesize($fullPath) <= $this->maxFileSize) {
            return $fullPath;
        }

        // 查找当天的轮转文件
        $pattern = $this->logDir . '/' . date('Y-m-d') . '_*.txt';
        $rotatedFiles = glob($pattern);
        sort($rotatedFiles); // 按文件名排序

        // 检查最新的轮转文件是否还有空间
        if (!empty($rotatedFiles)) {
            $latestFile = end($rotatedFiles);
            if (filesize($latestFile) <= $this->maxFileSize) {
                return $latestFile;
            }
            // 提取当前序号，创建下一个
            preg_match('/_(\d+)\.txt$/', $latestFile, $matches);
            $nextIndex = isset($matches[1]) ? (int)$matches[1] + 1 : 1;
        } else {
            $nextIndex = 1;
        }

        return $this->logDir . '/' . date('Y-m-d') . '_' . $nextIndex . '.txt';
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


}
