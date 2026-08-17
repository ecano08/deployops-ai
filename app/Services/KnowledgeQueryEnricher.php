<?php

namespace App\Services;

class KnowledgeQueryEnricher
{
    private const int AMBIGUOUS_QUERY_LENGTH = 48;

    /**
     * @param  array<int, string>  $userQuestionHistory
     * @return array{query: string, lexical_terms: array<int, string>}
     */
    public function enrich(string $searchQuery, ?string $currentQuestion = null, array $userQuestionHistory = []): array
    {
        $searchQuery = trim($searchQuery);
        $currentQuestion = is_string($currentQuestion) ? trim($currentQuestion) : '';
        $userQuestionHistory = $this->normalizeUserQuestions($userQuestionHistory);

        $anchorTerms = $this->extractAnchorTerms($searchQuery);

        if ($currentQuestion !== '') {
            $anchorTerms = array_values(array_unique(array_merge(
                $anchorTerms,
                $this->extractAnchorTerms($currentQuestion),
            )));
        }

        foreach ($userQuestionHistory as $priorQuestion) {
            $anchorTerms = array_values(array_unique(array_merge(
                $anchorTerms,
                $this->extractAnchorTerms($priorQuestion),
            )));
        }

        $contextQuestions = $this->contextQuestions($currentQuestion, $userQuestionHistory);
        $supplementalTerms = $this->supplementalTerms($searchQuery, $contextQuestions, $anchorTerms);

        $enrichedQuery = $searchQuery;

        if ($supplementalTerms !== []) {
            $enrichedQuery = trim($searchQuery.' '.implode(' ', $supplementalTerms));
        }

        return [
            'query' => $enrichedQuery,
            'lexical_terms' => $anchorTerms,
        ];
    }

    /**
     * @param  array<int, string>  $userQuestionHistory
     * @return array<int, string>
     */
    private function normalizeUserQuestions(array $userQuestionHistory): array
    {
        $normalized = [];

        foreach ($userQuestionHistory as $question) {
            if (! is_string($question)) {
                continue;
            }

            $question = trim($question);

            if ($question === '') {
                continue;
            }

            $normalized[] = $question;
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $userQuestionHistory
     * @return array<int, string>
     */
    private function contextQuestions(string $currentQuestion, array $userQuestionHistory): array
    {
        $questions = $userQuestionHistory;

        if ($currentQuestion !== '') {
            $questions[] = $currentQuestion;
        }

        return $questions;
    }

    /**
     * @param  array<int, string>  $contextQuestions
     * @param  array<int, string>  $anchorTerms
     * @return array<int, string>
     */
    private function supplementalTerms(string $searchQuery, array $contextQuestions, array $anchorTerms): array
    {
        if ($contextQuestions === []) {
            return [];
        }

        if (! $this->shouldEnrich($searchQuery, $contextQuestions, $anchorTerms)) {
            return [];
        }

        $searchLower = mb_strtolower($searchQuery);
        $supplemental = [];

        foreach ($contextQuestions as $question) {
            foreach ($this->extractAnchorTerms($question) as $term) {
                $termLower = mb_strtolower($term);

                if (str_contains($searchLower, $termLower)) {
                    continue;
                }

                $supplemental[] = $term;
            }
        }

        return array_values(array_unique($supplemental));
    }

    /**
     * @param  array<int, string>  $contextQuestions
     * @param  array<int, string>  $anchorTerms
     */
    private function shouldEnrich(string $searchQuery, array $contextQuestions, array $anchorTerms): bool
    {
        if ($contextQuestions === []) {
            return false;
        }

        if (mb_strlen($searchQuery) <= self::AMBIGUOUS_QUERY_LENGTH) {
            return true;
        }

        if ($this->containsFollowUpCue($searchQuery)) {
            return true;
        }

        $latestQuestion = $contextQuestions[array_key_last($contextQuestions)];
        $latestTerms = $this->extractAnchorTerms($latestQuestion);

        foreach ($latestTerms as $term) {
            if (preg_match('/\d/', $term) === 1 && ! str_contains(mb_strtolower($searchQuery), mb_strtolower($term))) {
                return true;
            }
        }

        foreach ($anchorTerms as $term) {
            if (preg_match('/\d/', $term) === 1 && ! str_contains(mb_strtolower($searchQuery), mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    private function containsFollowUpCue(string $searchQuery): bool
    {
        return (bool) preg_match(
            '/\b(?:and|also|who|what about|same|priority|follow(?:-|\s)?up|that|those|this|these|it|they|them)\b/i',
            $searchQuery,
        );
    }

    /**
     * @return array<int, string>
     */
    private function extractAnchorTerms(string $text): array
    {
        $terms = [];

        if (preg_match_all(
            '/\b\d+(?:\.\d+)?(?:\s*(?:min(?:ute)?s?|minutos?|hours?|hrs?|horas?|days?|d[ií]as?|weeks?|semanas?|months?|meses?|%|percent))?\b/iu',
            $text,
            $matches,
        ) !== false) {
            foreach ($matches[0] as $match) {
                $terms[] = trim($match);
            }
        }

        if (preg_match_all('/\b\d+(?:\.\d+)?\b/u', $text, $numberMatches) !== false) {
            foreach ($numberMatches[0] as $number) {
                $terms[] = $number;
            }
        }

        if (preg_match_all('/\b[a-z][a-z0-9_-]{3,}\b/i', $text, $wordMatches) !== false) {
            foreach ($wordMatches[0] as $word) {
                $normalized = mb_strtolower($word);

                if ($this->isStopWord($normalized)) {
                    continue;
                }

                $terms[] = $word;
            }
        }

        return array_values(array_unique($terms));
    }

    private function isStopWord(string $word): bool
    {
        return in_array($word, [
            'about', 'after', 'also', 'and', 'are', 'been', 'being', 'both', 'could', 'customer',
            'customers', 'does', 'doesn', 'from', 'happens', 'have', 'having', 'into', 'just',
            'like', 'more', 'most', 'other', 'people', 'person', 'same', 'some', 'such', 'than',
            'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through',
            'user', 'users', 'very', 'want', 'wants', 'what', 'when', 'where', 'which', 'while',
            'with', 'within', 'would', 'your', 'para', 'paga', 'dentro', 'usuario',
            'cliente', 'clientes', 'persona', 'personas', 'mismo', 'misma', 'tiene', 'tienen', 'como',
            'cual', 'cuando', 'donde', 'quien', 'ellos', 'ellas', 'este', 'esta', 'estos', 'estas',
        ], true);
    }
}
