<?php

namespace App\Providers\AI;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Main AI with an automatic backup: when the main provider is out of free quota, overloaded or
 * too slow, the same request is sent to the backup. Other errors (bad key, bad request) are not
 * retried, so configuration problems stay visible.
 */
class FailoverAiProvider implements AiContentProvider
{
    private const RETRYABLE = '/free limit|quota|rate limit|busy|overloaded|high demand|timed out|cURL error 28|Connection refused|HTTP 5\d\d/i';

    private string $used;

    public function __construct(private readonly AiContentProvider $primary, private readonly AiContentProvider $backup)
    {
        $this->used = $primary->name();
    }

    public function name(): string
    {
        return $this->used;
    }

    public function generate(string $task, array $context, array $schema): array
    {
        try {
            $this->used = $this->primary->name();

            return $this->primary->generate($task, $context, $schema);
        } catch (Throwable $e) {
            if (! preg_match(self::RETRYABLE, $e->getMessage())) {
                throw $e;
            }
            Log::info("AI failover: {$this->primary->name()} unavailable for {$task}, using {$this->backup->name()}", ['reason' => mb_substr($e->getMessage(), 0, 200)]);
            $this->used = $this->backup->name().' (backup)';
            try {
                return $this->backup->generate($task, $context, $schema);
            } catch (Throwable $backupError) {
                throw new RuntimeException('Main AI: '.mb_substr($e->getMessage(), 0, 160).' Backup AI: '.mb_substr($backupError->getMessage(), 0, 160));
            }
        }
    }
}
