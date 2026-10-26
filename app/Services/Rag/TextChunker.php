<?php

namespace App\Services\Rag;

class TextChunker
{
    public function __construct(
        private readonly int $chunkSize = 1000,
        private readonly int $overlap = 200,
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(
                'Chunk size must be greater than zero.'
            );
        }

        if ($overlap < 0 || $overlap >= $chunkSize) {
            throw new \InvalidArgumentException(
                'Overlap must be non-negative and smaller than chunk size.'
            );
        }
    }

    /**
     * Split text into overlapping chunks.
     *
     * @return array<int, string>
     */
    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;
        $step = $this->chunkSize - $this->overlap;
        while ($start < $length) {
            $chunk = trim(
                mb_substr($text, $start, $this->chunkSize)
            );

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $start += $step;
        }

        return $chunks;
    }
}
