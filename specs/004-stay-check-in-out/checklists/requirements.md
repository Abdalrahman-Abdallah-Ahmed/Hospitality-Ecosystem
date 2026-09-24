# Specification Quality Checklist: Stay Lifecycle and Check-in/out

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-24
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- One spec covers the whole of Phase 4 (SPEC-023, SPEC-024, SPEC-025): stay-per-line has no
  user value without check-in/out, and check-out depends on it.
- Permission names (`stays.view`, `stays.check_in`, `stays.check_out`) appear in FR-026
  because the master plan and the project's permission procedure require specs to fix them.
- Clarified on 2026-09-24 (see spec Clarifications): reservation is checked in on its
  first room; a room that isn't clean can be checked into, with a warning; undo is out of
  scope.
- Hard dependencies: SPEC-021 (assignment), SPEC-003 (status split), SPEC-004 (default
  teams). None of them has a spec folder yet.
- After `/speckit-analyze` (2026-09-24): housekeeping defaults became hotel settings chosen by id (FR-013, constitution i18n rule); FR-020a added (create-time status rules, AI tool statuses); FR-021 now covers deletion; arrivals definition includes past-departure stays.
