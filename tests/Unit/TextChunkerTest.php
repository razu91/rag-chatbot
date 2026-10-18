<?php

namespace Tests\Unit;

use App\Services\Rag\TextChunker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_it_splits_text_into_overlapping_chunks(): void
    {
        $chunker = new TextChunker(
            chunkSize: 10,
            overlap: 2,
        );

        $chunks = $chunker->split('abcdefghijklmnop');

        $this->assertSame([
            'abcdefghij',
            'ijklmnop',
        ], $chunks);
    }

    public function test_it_returns_empty_array_for_empty_text(): void
    {
        $chunker = new TextChunker();

        $this->assertSame([], $chunker->split(''));
    }

    public function test_overlap_must_be_smaller_than_chunk_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker(
            chunkSize: 10,
            overlap: 10,
        );
    }
}
