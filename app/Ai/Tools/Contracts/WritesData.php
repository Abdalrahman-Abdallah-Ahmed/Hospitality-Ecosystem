<?php

namespace App\Ai\Tools\Contracts;

/**
 * An AI tool that may change hotel data. It must reach a model wrapped in
 * GuardedTool (Admin AI) or AuditedTool (every other agent), which record one
 * data_change entry per call — succeeded, refused or failed (SPEC-083).
 *
 * AiActivityCoverageTest fails when a WritesData tool reaches a model
 * unwrapped.
 */
interface WritesData {}
