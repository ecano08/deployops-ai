<?php

namespace App\Services;

readonly class GroundedContextPackage
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $documents
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array<string, mixed>>  $unknowns
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(
        public string $query,
        public array $facts,
        public array $documents,
        public array $conflicts,
        public array $unknowns,
        public array $sources,
    ) {}

    /**
     * @return array{
     *     query: string,
     *     facts: list<array<string, mixed>>,
     *     documents: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     unknowns: list<array<string, mixed>>,
     *     sources: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'facts' => $this->facts,
            'documents' => $this->documents,
            'conflicts' => $this->conflicts,
            'unknowns' => $this->unknowns,
            'sources' => $this->sources,
        ];
    }
}
