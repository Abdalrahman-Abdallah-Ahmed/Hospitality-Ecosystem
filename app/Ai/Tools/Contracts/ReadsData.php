<?php

namespace App\Ai\Tools\Contracts;

/**
 * An AI tool that only reads. It needs no audit entry of its own: the AI run
 * it happens in records that the agent was at work (SPEC-083).
 *
 * Every class in app/Ai/Tools implements exactly one of ReadsData and
 * WritesData; AiActivityCoverageTest fails on a tool that implements neither.
 */
interface ReadsData {}
