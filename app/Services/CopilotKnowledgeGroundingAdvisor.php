<?php

namespace App\Services;

class CopilotKnowledgeGroundingAdvisor
{
    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     */
    public function shouldForceKnowledgeSearch(array $history, string $question, array $toolsUsed): bool
    {
        if (in_array('search_knowledge', $toolsUsed, true)) {
            return false;
        }

        if ($history === []) {
            return false;
        }

        return $this->isContextualFollowUp($question);
    }

    public function isContextualFollowUp(string $question): bool
    {
        $question = trim($question);

        if ($question === '') {
            return false;
        }

        if (mb_strlen($question) > 96) {
            return false;
        }

        return $this->containsFollowUpCue($question);
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     */
    public function buildSearchQuery(string $question, array $history): string
    {
        $priorQuestion = $history[array_key_last($history)]['question'] ?? '';

        if ($priorQuestion === '') {
            return trim($question);
        }

        return trim($question.' '.$priorQuestion);
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     */
    public function groundingDirective(array $history, string $question): ?string
    {
        if ($history === [] || ! $this->isContextualFollowUp($question)) {
            return null;
        }

        $priorQuestion = $history[array_key_last($history)]['question'];

        return sprintf(
            'The user is asking a contextual follow-up ("%s") continuing the topic from their prior question ("%s"). '.
            'You MUST call search_knowledge before answering. '.
            'Combine the follow-up with the prior user question topic in your search query. '.
            'Prior assistant replies are conversational context only, not authoritative evidence. '.
            'Do not ask permission to search documentation. '.
            'Only state that information is undocumented after search_knowledge returns no relevant results.',
            $question,
            $priorQuestion,
        );
    }

    private function containsFollowUpCue(string $question): bool
    {
        return (bool) preg_match(
            '/(?:^|[\s\?\¿])(?:and|also|y)\b|\b(?:what about|who has|who gets|qui[eé]n tiene|qui[eé]n tiene prioridad|priority|prioridad)\b/i',
            $question,
        );
    }
}
