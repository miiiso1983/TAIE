<?php

return [
    'endpoint' => env('AI_API_ENDPOINT'),
    'api_key' => env('AI_API_KEY') ?: env('OPENAI_API_KEY'),
    'chat_model' => env('AI_CHAT_MODEL', 'gpt-4o-mini'),
    'embedding_model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 45),
    'chunk_size' => (int) env('AI_CHUNK_SIZE', 1200),
    'chunk_overlap' => (int) env('AI_CHUNK_OVERLAP', 150),
    'maximum_embedding_chunks' => (int) env('AI_MAXIMUM_EMBEDDING_CHUNKS', 1000),
    'maximum_context_chars' => (int) env('AI_MAXIMUM_CONTEXT_CHARS', 24000),
    'maximum_retrieval_scan' => (int) env('AI_MAXIMUM_RETRIEVAL_SCAN', 50000),
    'retrieval_limit' => (int) env('AI_RETRIEVAL_LIMIT', 6),
    'minimum_similarity' => (float) env('AI_MINIMUM_SIMILARITY', 0.1),
    'max_document_chars' => (int) env('AI_MAX_DOCUMENT_CHARS', 500000),
    'max_question_chars' => (int) env('AI_MAX_QUESTION_CHARS', 8000),
];
