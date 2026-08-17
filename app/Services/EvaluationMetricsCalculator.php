<?php

namespace App\Services;

use App\Models\EvaluationCase;

class EvaluationMetricsCalculator
{
    private const int MAX_LATENCY_MS = 30_000;

    /**
     * @param  array<int, string>  $toolsUsed
     * @param  array<int, string>  $sourcesUsed
     * @return array{
     *   response_success: bool,
     *   expected_tool_usage: bool|null,
     *   expected_source_usage: bool|null,
     *   groundedness: bool,
     *   latency_acceptable: bool,
     *   passed: bool
     * }
     */
    public function calculate(
        EvaluationCase $evaluationCase,
        ?string $answer,
        ?string $errorMessage,
        array $toolsUsed,
        array $sourcesUsed,
        int $latencyMs,
    ): array {
        $responseSuccess = $errorMessage === null && is_string($answer) && trim($answer) !== '';

        $expectedToolUsage = $this->evaluateExpectedTools($evaluationCase->expected_tools, $toolsUsed);
        $expectedSourceUsage = $this->evaluateExpectedSources($evaluationCase->expected_sources, $sourcesUsed);
        $groundedness = $this->evaluateGroundedness($evaluationCase, $answer, $sourcesUsed);
        $latencyAcceptable = $latencyMs <= self::MAX_LATENCY_MS;

        $checks = array_filter([
            $responseSuccess,
            $expectedToolUsage ?? true,
            $expectedSourceUsage ?? true,
            $groundedness,
            $latencyAcceptable,
        ], static fn (?bool $value): bool => $value !== null);

        $passed = $checks !== [] && ! in_array(false, $checks, true);

        return [
            'response_success' => $responseSuccess,
            'expected_tool_usage' => $expectedToolUsage,
            'expected_source_usage' => $expectedSourceUsage,
            'groundedness' => $groundedness,
            'latency_acceptable' => $latencyAcceptable,
            'passed' => $passed,
        ];
    }

    /**
     * @param  array<int, string>|null  $expectedTools
     * @param  array<int, string>  $toolsUsed
     */
    private function evaluateExpectedTools(?array $expectedTools, array $toolsUsed): ?bool
    {
        if ($expectedTools === null || $expectedTools === []) {
            return null;
        }

        $used = array_values(array_unique($toolsUsed));

        foreach ($expectedTools as $expectedTool) {
            if (! in_array($expectedTool, $used, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>|null  $expectedSources
     * @param  array<int, string>  $sourcesUsed
     */
    private function evaluateExpectedSources(?array $expectedSources, array $sourcesUsed): ?bool
    {
        if ($expectedSources === null || $expectedSources === []) {
            return null;
        }

        foreach ($expectedSources as $expectedSource) {
            if (! in_array($expectedSource, $sourcesUsed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $sourcesUsed
     */
    private function evaluateGroundedness(EvaluationCase $evaluationCase, ?string $answer, array $sourcesUsed): bool
    {
        if (! is_string($answer) || trim($answer) === '') {
            return false;
        }

        if (preg_match_all('/\b[\w.-]+\.(?:md|pdf|txt|docx)\b/i', $answer, $matches) === 1) {
            foreach ($matches[0] as $citedSource) {
                if (! in_array($citedSource, $sourcesUsed, true)) {
                    return false;
                }
            }
        }

        foreach ($this->requiredPhrases($evaluationCase->expected_behavior) as $phrase) {
            if (! str_contains(strtolower($answer), strtolower($phrase))) {
                return false;
            }
        }

        if (str_contains(strtolower($evaluationCase->expected_behavior), 'must not fabricate')
            && preg_match('/\b(?:definitely|certainly|confirmed)\b/i', $answer) === 1
            && $sourcesUsed === []) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function requiredPhrases(string $expectedBehavior): array
    {
        $phrases = [];

        foreach (preg_split('/\r\n|\r|\n/', $expectedBehavior) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with(strtolower($line), 'must:')) {
                $phrase = trim(substr($line, 5));

                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
        }

        return $phrases;
    }
}
