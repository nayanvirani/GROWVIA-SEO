<?php

namespace App\Providers\AI;

interface AiContentProvider
{
    /**
     * @param  string  $task  task key, e.g. blog_draft, meta_suggestions
     * @param  array  $context  structured context object (section 10.3)
     * @param  array  $schema  ['required' => [...keys]] describing the expected JSON
     * @return array{data: array, model: string, input_tokens: int, output_tokens: int}
     */
    public function generate(string $task, array $context, array $schema): array;

    public function name(): string;
}
