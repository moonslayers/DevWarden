<?php

namespace App\Enums;

enum AiProviderType: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case DeepSeek = 'deepseek';
    case OpenAiCompatible = 'openai-compatible';
}
