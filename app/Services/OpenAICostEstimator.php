<?php

namespace App\Services;

class OpenAICostEstimator
{
    /**
     * @return array{input: float, output: float}
     */
    private function pricingForModel(string $model): array
    {
        /** @var array<string, array{input: float, output: float}> $pricing */
        $pricing = config('services.openai.pricing', []);

        if (isset($pricing[$model])) {
            return $pricing[$model];
        }

        return $pricing['default'] ?? ['input' => 0.001, 'output' => 0.003];
    }

    public function estimate(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = $this->pricingForModel($model);

        $inputCost = ($inputTokens / 1000) * $pricing['input'];
        $outputCost = ($outputTokens / 1000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }
}
