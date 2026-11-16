# Test Suite Performance — Stage Log

This file records the measurements behind the
[test suite speed and module runs design](designs/test-suite-speed-and-module-runs.md)
(revision 3). Each stage adds its results and its exit check here, and later
stages are judged against these figures. Stage 0 measures the suite as it is,
with no change to tests, code, config or CI.

## 1. Method

### Machine

*   Windows 11 Pro, AMD Ryzen 5 PRO 4650G: 6 cores, 12 logical CPUs, 15 GB
    RAM.
*   PHP 8.4.16 CLI (ZTS, Laragon build).
*   Postgres 17.11 with pgvector in the `hospitality-postgres` Docker
    container (Docker 29.8.2). Its volume is shared with another system's
    `pilot-postgres` container, so the container's settings were read only,
    never changed.
*   Nothing else ran on the machine during the measured runs.

### Default worker count: 12, not 16

The design says the current default is 16 workers. It is **12**: with no
`--processes`, the parallel runner (ParaTest) starts one worker per logical
CPU, and this machine has 12. The worker databases left by earlier default runs
also stopped at `Hospitality_Ecosystem_testing_test_12`. So the 2026-11-14
reference below was most likely a 12-worker run, and the Stage 0 baseline is
the 12-worker row of the sweep. The design doc is corrected in its own change.

### Runs

Each worker count in the sweep (4, 6, 8, 10, 12, 16) ran as one block of
back-to-back full runs. Each run was:

```text
php artisan config:clear
php artisan test --parallel --processes=<N> --log-junit=storage/test-reports/w<N>-r<k>.xml
```

*   **Warm run** (design definition): it starts within 5 minutes of a
    completed run of the same command, Postgres has not restarted since, and it
    uses the same worker count. Each worker count makes its own worker
    databases, so **the first run of each block is cold and discarded**.
*   **Excluded run:** a warm-position run that failed. It was replaced by
    another run in the same block, up to 7 runs per block. The one exception,
    at 4 workers, is explained under §3.2.
*   **Wall time:** measured around `php artisan test`, from start to exit. It
    includes worker start-up and each worker's migrations. Pest's own reported
    duration was within 1 s of it on every run.
*   **Summed time:** the sum of the JUnit `time` of all test cases, so it
    leaves out start-up and migrations. One dataset case counts as one test.
*   **Medians:** every reported figure is the median of the 3 warm runs. A
    file's summed time is its median across those runs. A folder's figure is
    the sum of its files' medians, so the folder total can differ slightly
    from the run median.
*   **Planned folders:** files are grouped by the move table in the design
    (Detailed Implementation Plan, item 8). The 12 files already in
    `tests/Feature/Superadmin/` count under `Superadmin`, and
    `tests/Unit/ExampleTest.php` under `Unit`. All 219 files mapped; none
    were left over.
*   The JUnit files are in `storage/test-reports/`, which git ignores. A
    script outside the repository produced the tables below.
*   **Dev database:** a read-only count of tables, `migrations` rows and
    `users` rows was taken before and after the measurement. The counts were
    the same (60, 119, 17).

### Interruption

The first 12-worker run started on 2026-11-15 at 14:36. During it, the
`pilot-postgres` container was replaced by `hospitality-postgres`, and the run
ended with "the database system is starting up". It was a cold run and was
thrown away. The whole measurement then started again from the beginning, at
14:47, once Postgres had finished recovering (about 5 minutes).

## 2. Pre-Stage-0 reference

| Date | Workers | Wall | Summed | Tests | Runs |
| --- | --- | --- | --- | --- | --- |
| 2026-11-14 | default (12; recorded as 16) | 403 s | 3,483 s | 3,597 | 1 |

## 3. Stage 0 results (2026-11-15)

### 3.1 Baseline (12 workers)


| Run | Started | Wall (s) | Summed (s) | Tests | Kind |
| --- | --- | --- | --- | --- | --- |
| w12-r1 | 2026-11-15 14:47:24 | 302.6 | – | 3597 | cold (first of block) |
| w12-r2 | 2026-11-15 14:52:27 | 299.3 | 2,951.2 | 3597 | warm |
| w12-r3 | 2026-11-15 14:57:28 | 298.0 | 2,944.5 | 3597 | warm |
| w12-r4 | 2026-11-15 15:02:27 | 298.6 | 2,944.1 | 3597 | warm |

- **Median wall:** 298.6 s
- **Median summed test time:** 2,944.5 s
- **Tests:** 3,597 (reference 3,597; difference +0)
- **Worker-seconds vs summed:** 12 × 298.6 = 3,583 worker-s; not running tests: 18%

The baseline is **105 s faster than the 2026-11-14 reference** (299 s against
403 s), with the same 3,597 tests. Stage 0 changed nothing that the tests run
on, so the gap comes from the machine on the day of that one run, not from any
improvement. Every later stage is judged against this median, not against 403
s.

### 3.2 Worker sweep


| Workers | Warm 1 (s) | Warm 2 (s) | Warm 3 (s) | Median (s) | Summed median (s) | vs fastest |
| --- | --- | --- | --- | --- | --- | --- |
| 4 | 418.4 | 416.0 | 420.9 | **418.4** | 1,600.9 | 42.9% |
| 6 | 340.2 | 340.7 | 340.5 | **340.5** | 1,907.5 | 16.3% |
| 8 | 302.3 | 302.4 | 303.4 | **302.4** | 2,234.5 | 3.3% |
| 10 | 292.7 | 294.7 | 292.7 | **292.7** | 2,574.7 | 0.0% |
| 12 | 299.3 | 298.0 | 298.6 | **298.6** | 2,944.5 | 2.0% |
| 16 | 303.0 | 303.6 | 303.0 | **303.0** | 3,627.9 | 3.5% |

Cold and excluded runs (not in the medians):

- `w12-r1`: cold, 302.6 s (first of block)
- `w4-r1`: cold, 421.6 s (first of block)
- `w4-r5`: excluded, 417.2 s (1 failure (AiActivityOutreachTest); not needed)
- `w4-r6`: excluded, 421.9 s (1 failure (AiActivityOutreachTest); not needed)
- `w4-r7`: excluded, 421.8 s (1 failure (AiActivityOutreachTest); not needed)
- `w6-r1`: cold, 340.5 s (first of block)
- `w6-r2`: excluded, 348.5 s (219 errors: worker DB _test_5 lost its tables mid-run)
- `w6-r3`: excluded, 342.4 s (1 error: ProactiveTriggersTest unique violation)
- `w8-r1`: cold, 302.0 s (first of block)
- `w10-r1`: cold, 295.6 s (first of block)
- `w16-r1`: cold, 304.6 s (first of block)

**Chosen count: 8** (median 302.4 s; fastest 292.7 s at 10 workers; 5% limit 307.3 s)

**Reading the sweep:**

*   Wall time drops steeply up to 8 workers and is almost flat from 8 to 16
    (292.7–303.0 s). 10 workers was fastest, but 8, 12 and 16 are each within
    3.5% of it.
*   More workers make each test slower. Summed time rises from 1,601 s at 4
    workers to 3,628 s at 16, while the 3,597 tests stay the same. The CPU,
    not the worker count, is the limit above 8 workers.
*   One file sets the floor. At 8 workers, `CrossTenantRouteTest` alone sums
    218 s, which is 72% of the run's wall time. Pest gives each worker whole
    files, so no worker count can bring the run below that file's time.

**Failed runs.** None of these failures come from the measurement. They are
flaky tests that already existed, and Stage 0 does not change tests:

*   **4 workers, every run** (7 of 7): `AiActivityOutreachTest`, "it records
    how the guest answered a pitch", dataset "accepted". The rows `pitch.offered`
    and `pitch.accepted` tie on `occurred_at` and `created_at`, so Postgres may
    return them in either order. At 4 workers it lost the tie every time. A
    replacement run could never pass, so **runs 2–4 were kept**, each with this
    one failed assertion. One assertion out of 16,300 does not change the timing,
    and 4 workers is the slowest count, so it does not affect the choice.
*   **6 workers, run 2:** 219 errors, all in worker database
    `Hospitality_Ecosystem_testing_test_5`. Its tables disappeared partway
    through the run ("relation users does not exist"), so the 13 files that
    worker ran next all failed. Some file run earlier in that worker left the
    database without its tables. Which file did it was not traced.
*   **6 workers, run 3:** one unique-key violation in `ProactiveTriggersTest`.

These three need their own fix (tracked outside this stage). They matter for
Stage 1, because a worker count that changes how files are grouped can bring
them out.

### 3.3 Files (12 workers, median of 3)


| # | File | Folder | Tests | Summed (s) |
| --- | --- | --- | --- | --- |
| 1 | `tests/Feature/CrossTenantRouteTest.php` | Invariants | 315 | 236.7 |
| 2 | `tests/Feature/PermissionAuthorizationTest.php` | Invariants | 112 | 106.1 |
| 3 | `tests/Feature/AccessLevelSeederTest.php` | Core | 20 | 90.9 |
| 4 | `tests/Feature/Superadmin/ImpersonationTest.php` | Superadmin | 112 | 61.0 |
| 5 | `tests/Feature/Superadmin/DashboardTest.php` | Superadmin | 129 | 53.4 |
| 6 | `tests/Feature/Superadmin/AccessGuardTest.php` | Superadmin | 92 | 52.6 |
| 7 | `tests/Feature/Superadmin/RouteMoveTest.php` | Superadmin | 154 | 52.6 |
| 8 | `tests/Feature/ReservationRoomsTest.php` | Reservations | 41 | 46.6 |
| 9 | `tests/Feature/MultiHotelAccessTest.php` | Core | 52 | 44.5 |
| 10 | `tests/Feature/AdminToolPermissionTest.php` | Invariants | 11 | 41.9 |
| 11 | `tests/Feature/TenantIsolationTest.php` | Invariants | 63 | 41.6 |
| 12 | `tests/Feature/VoucherTemplateControllerTest.php` | Reservations | 40 | 37.9 |
| 13 | `tests/Feature/AccountOwnershipTest.php` | Core | 36 | 37.1 |
| 14 | `tests/Feature/Superadmin/UserAdministrationTest.php` | Superadmin | 63 | 35.4 |
| 15 | `tests/Feature/AdminToolIsolationTest.php` | Invariants | 17 | 35.2 |
| 16 | `tests/Feature/ReservationLifecycleTest.php` | Reservations | 34 | 34.9 |
| 17 | `tests/Feature/SuspensionJobTest.php` | Core | 54 | 33.6 |
| 18 | `tests/Feature/AiActivityCoverageTest.php` | AiPlatform | 12 | 30.2 |
| 19 | `tests/Feature/RoomAssignmentTest.php` | Reservations | 28 | 29.1 |
| 20 | `tests/Feature/ReservationGuestsTest.php` | Reservations | 34 | 28.4 |
| 21 | `tests/Feature/BookingControllerTest.php` | Activities | 35 | 27.2 |
| 22 | `tests/Feature/VoucherEmailTest.php` | Reservations | 31 | 26.5 |
| 23 | `tests/Feature/RoomAssignmentConcurrencyTest.php` | Reservations | 1 | 24.6 |
| 24 | `tests/Feature/GuestRequestNoticeTest.php` | Concierge | 21 | 24.5 |
| 25 | `tests/Feature/ProactiveGuardrailsTest.php` | Recommendations | 19 | 24.4 |
| 26 | `tests/Feature/KnowledgeSearchToolTest.php` | Invariants | 10 | 23.2 |
| 27 | `tests/Feature/CreateUserTest.php` | Core | 34 | 23.0 |
| 28 | `tests/Feature/GuestMemoryExtractionTest.php` | Concierge | 18 | 22.2 |
| 29 | `tests/Feature/AdminWriteToolsTest.php` | AiPlatform | 19 | 22.0 |
| 30 | `tests/Feature/NoShowTest.php` | Reservations | 19 | 21.9 |
| 31 | `tests/Feature/AdminReadToolsTest.php` | AiPlatform | 12 | 21.8 |
| 32 | `tests/Feature/ActivityControllerTest.php` | Activities | 24 | 21.7 |
| 33 | `tests/Feature/HousekeepingLifecycleTest.php` | Housekeeping | 15 | 21.0 |
| 34 | `tests/Feature/ReservationImportTest.php` | ImportExport | 24 | 21.0 |
| 35 | `tests/Feature/ReservationVoucherTest.php` | Reservations | 23 | 20.1 |
| 36 | `tests/Feature/HousekeepingConcurrencyTest.php` | Housekeeping | 5 | 19.9 |
| 37 | `tests/Feature/ReservationImportMappingTest.php` | ImportExport | 18 | 19.2 |
| 38 | `tests/Feature/CostVisibilityTest.php` | Invariants | 7 | 19.0 |
| 39 | `tests/Feature/PitchEligibilityTest.php` | Recommendations | 38 | 18.9 |
| 40 | `tests/Feature/ProactiveTriggersTest.php` | Recommendations | 12 | 18.7 |
| 41 | `tests/Feature/SuspensionAdministrationTest.php` | Superadmin | 35 | 18.6 |
| 42 | `tests/Feature/ReservationAvailabilityGuardTest.php` | Reservations | 20 | 18.6 |
| 43 | `tests/Feature/ReservationImportMappingConfirmTest.php` | ImportExport | 19 | 18.3 |
| 44 | `tests/Feature/RoomOverlapConstraintTest.php` | Rooms | 23 | 18.0 |
| 45 | `tests/Feature/CheckOutTest.php` | Reservations | 14 | 17.7 |
| 46 | `tests/Feature/SuspensionTest.php` | Core | 25 | 17.5 |
| 47 | `tests/Feature/ReservationImportIdempotencyTest.php` | ImportExport | 11 | 16.9 |
| 48 | `tests/Feature/AdvisorConfirmationTest.php` | AiPlatform | 16 | 16.8 |
| 49 | `tests/Feature/ActivityAvailabilityServiceTest.php` | Activities | 13 | 16.7 |
| 50 | `tests/Feature/ReservationControllerTest.php` | Reservations | 24 | 16.6 |
| 51 | `tests/Feature/ReservationRoomOccupancyTest.php` | Reservations | 12 | 16.4 |
| 52 | `tests/Feature/AdminToolParityTest.php` | AiPlatform | 9 | 15.9 |
| 53 | `tests/Feature/HousekeepingInspectionTest.php` | Housekeeping | 9 | 15.9 |
| 54 | `tests/Feature/RecommendationApprovalTest.php` | Recommendations | 16 | 15.6 |
| 55 | `tests/Feature/RoomMoveTest.php` | Reservations | 13 | 15.6 |
| 56 | `tests/Feature/ExportControllerTest.php` | ImportExport | 21 | 15.3 |
| 57 | `tests/Feature/Superadmin/KnowledgeRebuildTest.php` | Superadmin | 8 | 15.1 |
| 58 | `tests/Feature/ConciergeGuestRestrictionsTest.php` | Concierge | 13 | 15.1 |
| 59 | `tests/Feature/CheckInTest.php` | Reservations | 16 | 14.5 |
| 60 | `tests/Feature/InsightCitationsTest.php` | AiPlatform | 15 | 14.4 |
| 61 | `tests/Feature/RoomControllerTest.php` | Rooms | 24 | 14.3 |
| 62 | `tests/Feature/TaskNotificationTest.php` | Tasks | 10 | 14.2 |
| 63 | `tests/Feature/AccountUsageAiBlockTest.php` | AiPlatform | 9 | 14.1 |
| 64 | `tests/Feature/ConciergeBookingToolsTest.php` | Concierge | 16 | 13.9 |
| 65 | `tests/Feature/DeclineRetryTest.php` | Recommendations | 9 | 13.6 |
| 66 | `tests/Feature/AdminGuestMessagesRemovalTest.php` | AiPlatform | 5 | 13.6 |
| 67 | `tests/Feature/AdminCreateToolsTest.php` | AiPlatform | 9 | 13.6 |
| 68 | `tests/Feature/ActivityAvailabilityControllerTest.php` | Activities | 8 | 13.6 |
| 69 | `tests/Feature/RecommendationQueueTest.php` | Recommendations | 8 | 13.5 |
| 70 | `tests/Feature/BookingUpdateTest.php` | Activities | 19 | 13.4 |
| 71 | `tests/Feature/ReservationStatusDeprecationTest.php` | Reservations | 12 | 13.3 |
| 72 | `tests/Feature/AdminToolsReviewFixesTest.php` | AiPlatform | 12 | 12.8 |
| 73 | `tests/Feature/GuestControllerTest.php` | Concierge | 20 | 12.7 |
| 74 | `tests/Feature/AdminRecommendationToolsTest.php` | AiPlatform | 4 | 12.6 |
| 75 | `tests/Feature/HousekeepingStartOfDayTest.php` | Housekeeping | 11 | 12.5 |
| 76 | `tests/Feature/RoomIssueReportTest.php` | Housekeeping | 9 | 12.2 |
| 77 | `tests/Feature/WhatsAppDeviceTest.php` | Concierge | 30 | 11.8 |
| 78 | `tests/Feature/UsageMeteringTest.php` | AiPlatform | 25 | 11.8 |
| 79 | `tests/Feature/AvailabilityServiceTest.php` | Rooms | 20 | 11.8 |
| 80 | `tests/Feature/ConversionAnalyticsTest.php` | Recommendations | 15 | 11.7 |
| 81 | `tests/Feature/RecommendationOutcomeTest.php` | Recommendations | 20 | 11.6 |
| 82 | `tests/Feature/AdminAiAuditTest.php` | AiPlatform | 3 | 11.5 |
| 83 | `tests/Feature/AiCostAttributionTest.php` | AiPlatform | 25 | 11.3 |
| 84 | `tests/Feature/BookingCancellationRequestTest.php` | Activities | 15 | 11.2 |
| 85 | `tests/Feature/GuestRequestRoutingTest.php` | Concierge | 11 | 11.2 |
| 86 | `tests/Feature/Superadmin/HotelAdministrationTest.php` | Superadmin | 23 | 11.1 |
| 87 | `tests/Feature/StayListTest.php` | Reservations | 6 | 11.0 |
| 88 | `tests/Feature/CheckInOutConcurrencyTest.php` | Reservations | 3 | 11.0 |
| 89 | `tests/Feature/PitchActivityToolTest.php` | Recommendations | 10 | 10.7 |
| 90 | `tests/Feature/ReservationImportNormalizationTest.php` | ImportExport | 82 | 10.6 |
| 91 | `tests/Feature/HousekeepingBoardTest.php` | Housekeeping | 8 | 10.5 |
| 92 | `tests/Feature/MultiRoomCheckInOutTest.php` | Reservations | 9 | 10.5 |
| 93 | `tests/Feature/GenericIndexFilterTypesTest.php` | Core | 17 | 10.0 |
| 94 | `tests/Feature/TaskControllerTest.php` | Tasks | 14 | 10.0 |
| 95 | `tests/Feature/RoomUpgradeTest.php` | Reservations | 8 | 10.0 |
| 96 | `tests/Feature/HotelControllerTest.php` | Core | 18 | 9.9 |
| 97 | `tests/Feature/StaffRoleControllerTest.php` | Core | 17 | 9.9 |
| 98 | `tests/Feature/CreateReservationToolTest.php` | Reservations | 15 | 9.8 |
| 99 | `tests/Feature/RecommendationMatchingTest.php` | Recommendations | 15 | 9.7 |
| 100 | `tests/Feature/VoucherRendererTest.php` | Reservations | 11 | 9.7 |
| 101 | `tests/Feature/RoomTypeControllerTest.php` | Rooms | 18 | 9.6 |
| 102 | `tests/Feature/BookingTest.php` | Activities | 16 | 9.5 |
| 103 | `tests/Feature/ProactivePitchTest.php` | Recommendations | 7 | 9.4 |
| 104 | `tests/Feature/KnowledgeDocumentIndexingTest.php` | Knowledge | 13 | 9.4 |
| 105 | `tests/Feature/OutOfOrderTest.php` | Rooms | 12 | 9.2 |
| 106 | `tests/Feature/TransactionsFeatureFlagTest.php` | Finance | 15 | 9.0 |
| 107 | `tests/Feature/SuspensionWhatsAppTest.php` | Core | 16 | 8.8 |
| 108 | `tests/Feature/WhatsAppWebhookTest.php` | Concierge | 21 | 8.7 |
| 109 | `tests/Feature/AuthTest.php` | Core | 9 | 8.7 |
| 110 | `tests/Feature/ReservationImportGuestMatchingTest.php` | ImportExport | 8 | 8.5 |
| 111 | `tests/Feature/UnassignedArrivalsTest.php` | Reservations | 9 | 8.5 |
| 112 | `tests/Feature/ConciergeMemoryTest.php` | Concierge | 9 | 8.5 |
| 113 | `tests/Feature/Superadmin/PlatformAuditTest.php` | Superadmin | 15 | 8.4 |
| 114 | `tests/Feature/KnowledgeDocumentReplaceTest.php` | Knowledge | 8 | 8.1 |
| 115 | `tests/Feature/ReservationImportPreviewTest.php` | ImportExport | 12 | 8.0 |
| 116 | `tests/Feature/TaskStayLinkTest.php` | Tasks | 6 | 8.0 |
| 117 | `tests/Feature/KnowledgeDocumentExtractionTest.php` | Knowledge | 11 | 7.9 |
| 118 | `tests/Feature/PasswordResetTest.php` | Core | 9 | 7.9 |
| 119 | `tests/Feature/ExpireRecommendationsJobTest.php` | Recommendations | 7 | 7.9 |
| 120 | `tests/Feature/ProactiveOptOutTest.php` | Recommendations | 8 | 7.7 |
| 121 | `tests/Feature/RecommendationEditApprovalTest.php` | Recommendations | 7 | 7.5 |
| 122 | `tests/Feature/AiActivityApiTest.php` | AiPlatform | 14 | 7.5 |
| 123 | `tests/Feature/StayAiToolsTest.php` | Reservations | 8 | 7.5 |
| 124 | `tests/Feature/AvailabilityControllerTest.php` | Rooms | 14 | 7.5 |
| 125 | `tests/Feature/TeamControllerTest.php` | Tasks | 11 | 7.3 |
| 126 | `tests/Feature/Superadmin/GuestMemoryExtractionSuperadminTest.php` | Superadmin | 7 | 7.2 |
| 127 | `tests/Feature/CreationNotificationTest.php` | Reservations | 10 | 7.2 |
| 128 | `tests/Feature/KnowledgeDocumentVisionTest.php` | Knowledge | 10 | 7.1 |
| 129 | `tests/Feature/Superadmin/GlobalKnowledgeTest.php` | Superadmin | 7 | 7.1 |
| 130 | `tests/Feature/KnowledgeDocumentControllerTest.php` | Knowledge | 11 | 7.0 |
| 131 | `tests/Feature/StaySyncTest.php` | Reservations | 8 | 7.0 |
| 132 | `tests/Feature/GuestEscalationTest.php` | Concierge | 7 | 6.8 |
| 133 | `tests/Feature/AiCapabilityCoverageTest.php` | AiPlatform | 9 | 6.8 |
| 134 | `tests/Feature/HotelPolicyControllerTest.php` | Core | 12 | 6.7 |
| 135 | `tests/Feature/AiRunTest.php` | AiPlatform | 8 | 6.7 |
| 136 | `tests/Feature/KnowledgeDocumentLifecycleTest.php` | Knowledge | 9 | 6.7 |
| 137 | `tests/Feature/GuestContactTest.php` | Concierge | 10 | 6.7 |
| 138 | `tests/Feature/ConciergeReadToolsTest.php` | Concierge | 7 | 6.5 |
| 139 | `tests/Feature/StayServiceTest.php` | Reservations | 10 | 6.3 |
| 140 | `tests/Feature/Superadmin/HotelAccessOptionsTest.php` | Superadmin | 9 | 6.3 |
| 141 | `tests/Feature/RoomChangeRequestTest.php` | Reservations | 7 | 6.3 |
| 142 | `tests/Feature/RecommendationDeliveryTest.php` | Recommendations | 10 | 6.2 |
| 143 | `tests/Feature/EventLogTest.php` | Core | 12 | 6.1 |
| 144 | `tests/Feature/KnowledgeDocumentCorrectionTest.php` | Knowledge | 6 | 6.0 |
| 145 | `tests/Feature/AdminToolsetArchTest.php` | Invariants | 7 | 6.0 |
| 146 | `tests/Feature/AiActivityOutreachTest.php` | AiPlatform | 5 | 6.0 |
| 147 | `tests/Feature/GuestMemoryApiTest.php` | Concierge | 9 | 5.9 |
| 148 | `tests/Feature/ConversationViewerTest.php` | Concierge | 9 | 5.7 |
| 149 | `tests/Feature/NoShowToolTest.php` | Reservations | 5 | 5.6 |
| 150 | `tests/Feature/DashboardControllerTest.php` | Core | 10 | 5.5 |
| 151 | `tests/Feature/AiToolInventoryTest.php` | Invariants | 5 | 5.5 |
| 152 | `tests/Feature/GenericCrudTest.php` | Core | 11 | 5.5 |
| 153 | `tests/Feature/KnowledgeChunkSyncTest.php` | Knowledge | 11 | 5.4 |
| 154 | `tests/Feature/Superadmin/AccountAdministrationTest.php` | Superadmin | 17 | 5.4 |
| 155 | `tests/Feature/AvailabilityToolsTest.php` | Rooms | 11 | 5.1 |
| 156 | `tests/Feature/DeletedHotelAccessTest.php` | Core | 7 | 5.0 |
| 157 | `tests/Feature/BookingCapacityConcurrencyTest.php` | Activities | 2 | 4.9 |
| 158 | `tests/Feature/ProactiveSettingsTest.php` | Recommendations | 9 | 4.8 |
| 159 | `tests/Feature/GuestEscalationConcurrencyTest.php` | Concierge | 2 | 4.6 |
| 160 | `tests/Feature/SenderRecognitionServiceTest.php` | Concierge | 10 | 4.6 |
| 161 | `tests/Feature/TransactionImportTest.php` | Finance | 9 | 4.6 |
| 162 | `tests/Feature/StayMigrationTest.php` | Reservations | 7 | 4.3 |
| 163 | `tests/Feature/AiActivityJobsTest.php` | AiPlatform | 4 | 4.2 |
| 164 | `tests/Feature/PitchFromApprovedPoolTest.php` | Recommendations | 4 | 4.2 |
| 165 | `tests/Feature/OperationalDefaultsTest.php` | Core | 6 | 4.2 |
| 166 | `tests/Feature/KnowledgeRetrievalIsolationTest.php` | Invariants | 5 | 4.1 |
| 167 | `tests/Feature/RoomTypePermissionAuthorizationTest.php` | Invariants | 6 | 4.1 |
| 168 | `tests/Feature/GuestContactPreferenceTest.php` | Concierge | 4 | 4.0 |
| 169 | `tests/Feature/CancellationOutcomeNoticeTest.php` | Activities | 5 | 3.9 |
| 170 | `tests/Feature/DashboardCacheTest.php` | Core | 5 | 3.9 |
| 171 | `tests/Feature/GuestIdentityServiceTest.php` | Concierge | 8 | 3.8 |
| 172 | `tests/Feature/BoundedListTest.php` | Invariants | 2 | 3.7 |
| 173 | `tests/Feature/FilterableTest.php` | Core | 8 | 3.7 |
| 174 | `tests/Feature/SuspensionModelTest.php` | Core | 9 | 3.6 |
| 175 | `tests/Feature/HousekeepingManualStatusTest.php` | Housekeeping | 5 | 3.6 |
| 176 | `tests/Feature/ReservationRoomsMigrationTest.php` | Reservations | 7 | 3.5 |
| 177 | `tests/Feature/ReservationImportDryRunTest.php` | ImportExport | 2 | 3.5 |
| 178 | `tests/Feature/AiAdvisorControllerTest.php` | AiPlatform | 7 | 3.5 |
| 179 | `tests/Feature/OpsReadinessCommandTest.php` | Core | 15 | 3.4 |
| 180 | `tests/Feature/TransactionControllerTest.php` | Finance | 6 | 3.3 |
| 181 | `tests/Feature/ReservationAvailabilityConcurrencyTest.php` | Reservations | 1 | 3.3 |
| 182 | `tests/Feature/TransactionServiceTest.php` | Finance | 7 | 3.3 |
| 183 | `tests/Feature/AiActivityRecorderTest.php` | AiPlatform | 5 | 3.0 |
| 184 | `tests/Feature/KnowledgeBaseArticleControllerTest.php` | Knowledge | 5 | 3.0 |
| 185 | `tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php` | Invariants | 5 | 2.9 |
| 186 | `tests/Feature/FreshInstallTest.php` | Core | 5 | 2.7 |
| 187 | `tests/Feature/ProactiveMessageLogTest.php` | Recommendations | 3 | 2.7 |
| 188 | `tests/Feature/ApiKeyTest.php` | Core | 7 | 2.6 |
| 189 | `tests/Feature/RoomStatusSplitTest.php` | Rooms | 4 | 2.5 |
| 190 | `tests/Feature/HotelGroupTest.php` | Core | 7 | 2.5 |
| 191 | `tests/Feature/SqlDumpTest.php` | ImportExport | 4 | 2.5 |
| 192 | `tests/Feature/JobTenantIsolationTest.php` | Invariants | 3 | 2.3 |
| 193 | `tests/Feature/HistoryTypesTest.php` | Core | 3 | 2.3 |
| 194 | `tests/Feature/ConciergeToolsArchTest.php` | Invariants | 4 | 2.2 |
| 195 | `tests/Feature/AiCostCapabilityTest.php` | AiPlatform | 4 | 2.1 |
| 196 | `tests/Feature/BookingScheduleBackfillTest.php` | Activities | 4 | 2.1 |
| 197 | `tests/Feature/TransactionAttributionTest.php` | Finance | 4 | 2.1 |
| 198 | `tests/Feature/GuestConciergeVipTest.php` | Concierge | 4 | 2.0 |
| 199 | `tests/Feature/EntryPointInventoryTest.php` | Invariants | 5 | 1.9 |
| 200 | `tests/Feature/RecommendationStatusMigrationTest.php` | Recommendations | 2 | 1.8 |
| 201 | `tests/Feature/RoomOverlapsCommandTest.php` | Rooms | 2 | 1.8 |
| 202 | `tests/Feature/ConciergeKnowledgeCitationTest.php` | Concierge | 3 | 1.7 |
| 203 | `tests/Feature/TaskCategoryControllerTest.php` | Tasks | 3 | 1.7 |
| 204 | `tests/Feature/HealthCheckTest.php` | Core | 8 | 1.6 |
| 205 | `tests/Feature/AiToolFieldProjectionTest.php` | Invariants | 1 | 1.5 |
| 206 | `tests/Feature/RoomTypeAvailabilityIntegrationTest.php` | Rooms | 3 | 1.5 |
| 207 | `tests/Feature/PlatformAlertTest.php` | Core | 8 | 1.5 |
| 208 | `tests/Feature/ReservationRoomsAiToolsTest.php` | Reservations | 2 | 1.3 |
| 209 | `tests/Feature/QueueLaneTest.php` | Invariants | 6 | 1.1 |
| 210 | `tests/Feature/QueueWaitCheckTest.php` | Core | 5 | 1.0 |
| 211 | `tests/Feature/DatabaseSeederTest.php` | Core | 1 | 1.0 |
| 212 | `tests/Feature/AvailableChannelsTest.php` | Concierge | 4 | 0.9 |
| 213 | `tests/Feature/ReservationGuestsMigrationTest.php` | Reservations | 1 | 0.6 |
| 214 | `tests/Feature/SuspensionRouteInventoryTest.php` | Invariants | 4 | 0.5 |
| 215 | `tests/Feature/JobSuspensionInventoryTest.php` | Invariants | 3 | 0.5 |
| 216 | `tests/Feature/CorsTest.php` | Core | 4 | 0.5 |
| 217 | `tests/Feature/SentryScrubberTest.php` | Core | 4 | 0.5 |
| 218 | `tests/Feature/AgentLimitsTest.php` | Invariants | 1 | 0.1 |
| 219 | `tests/Unit/ExampleTest.php` | Unit | 1 | 0.0 |

### 3.4 Planned folders (12 workers)


| Folder | Files | Tests | Summed (s) |
| --- | --- | --- | --- |
| `Invariants` | 21 | 592 | 540.1 |
| `Reservations` | 34 | 489 | 505.8 |
| `Core` | 32 | 454 | 365.5 |
| `Superadmin` | 13 | 671 | 334.3 |
| `AiPlatform` | 22 | 231 | 262.4 |
| `Recommendations` | 19 | 219 | 200.6 |
| `Concierge` | 21 | 236 | 181.8 |
| `Activities` | 10 | 141 | 124.2 |
| `ImportExport` | 10 | 201 | 124.0 |
| `Housekeeping` | 7 | 62 | 95.7 |
| `Rooms` | 10 | 131 | 81.1 |
| `Knowledge` | 9 | 84 | 60.6 |
| `Tasks` | 5 | 44 | 41.3 |
| `Finance` | 5 | 41 | 22.2 |
| `Unit` | 1 | 1 | 0.0 |
| `UNMAPPED` | 0 | 0 | 0.0 |
| **Total** | 219 | 3,597 | 2,939.5 |

### 3.5 Invariant tests


21 files, 592 tests, **540.1 s** summed (12 workers, median of 3).

The 4-worker runs are the least contended. From them, the invariant tests sum
to **324.5 s**, `Reservations` to 284.3 s, and `CrossTenantRouteTest` alone to
159.7 s.

### 3.6 Checks

| Check | Observed | Answer |
| --- | --- | --- |
| Xdebug loaded | not in `php -m` | **no** |
| `opcache.enable_cli` | Zend OPcache not loaded; `ini_get` returns `false` (`C:\laragon\bin\php\php-8.4.16-Win32-vs17-x64\php.ini`) | **off** |
| Postgres `max_connections` vs 16 workers | 100, with 6 in use by others; 16 workers need about 17–20 | **enough** |

Turning OPcache on for the CLI is a machine setting, not a repository change,
so Stage 0 left it off. Stage 1 can measure it.

## 4. Stage 0 exit check

*   **Lowest worker count within 5% of the fastest: 8.** Its median is 302.4
    s; the fastest is 292.7 s at 10 workers, so the 5% limit is 307.3 s. 12
    workers (298.6 s) and 16 workers (303.0 s) are also inside the limit; 6
    workers (340.5 s) is not.
*   **Files whose summed time exceeds 25% of the 8-worker wall time** (25% ×
    302.4 s = 75.6 s), from the 8-worker runs:
    *   `CrossTenantRouteTest`: 218.0 s;
    *   `PermissionAuthorizationTest`: 79.0 s.

    The next slowest is `AccessLevelSeederTest` at 69.6 s, below the threshold.
*   **G2 estimate** (slowest module plus the invariant tests):
    *   from the 12-worker runs: `Reservations` 505.8 s + invariants 540.1 s
        = **1,045.9 s**;
    *   from the 4-worker runs: 284.3 s + 324.5 s = **608.8 s**.

    Both are upper bounds, because a module run in one process avoids the
    contention between workers. The target is 180 s, and the invariant tests
    alone take about 1.8× that even at 4 workers. **G2 is not reachable as
    the design describes it.** Q2's first step (adding `--parallel
    --processes=4` to the module run) is now needed, and so is a decision on
    `CrossTenantRouteTest`, which takes 160 s on its own.
*   **G1 outlook:** 302.4 s at 8 workers against the 210 s target. Q1 is
    decided in Stage 1. The run cannot finish faster than
    `CrossTenantRouteTest` takes (218 s at 8 workers), so G1 needs that file
    split or made faster.
*   **G3:** 3,597 tests on every run, the same as the reference.
*   **Every figure in §2–§3 is present:** yes.

## 5. Stage 1 — flaky test fixes (2026-11-16)

The three failures Stage 0 found were fixed before any timing change.

| Failure | Cause | Fix |
| --- | --- | --- |
| `AiActivityOutreachTest`, "records how the guest answered a pitch" | `ai_activities.occurred_at` keeps whole seconds. The test wrote the offer and the answer in the same second, so ordering fell back to random UUIDs. | The test moves the clock 1 s forward between the offer and the answer (`$this->travel(1)->seconds()`), as happens with a real guest. |
| `ProactiveTriggersTest`, unique violation on `rooms_hotel_id_room_number_unique` | `rapInHouseStay()` chose a random room number from 1000–9999. The test makes 14 stays in one hotel, so two could draw the same number (about 1% per run). | New helper `rapFreeRoomNumber()` in `tests/Pest.php` draws until the number is free in that hotel. |
| A worker database lost its tables mid-run (219 errors) | Before each test, Laravel checks the worker database (`Schema::hasTable('dummy')` in `Illuminate\Testing\Concerns\TestDatabases`). On any query error, even a connection that hangs and then fails (the failing test spent 154 s there), it drops the database and creates an empty one. The worker still had `RefreshDatabaseState::$migrated = true`, so it never migrated again, and every later test on it failed with "relation users does not exist". | `tests/TestCase.php` registers a `ParallelTesting::setUpTestDatabase` callback that sets `$migrated = false` when a worker database is created, so the next refresh migrates it. |

**Verification of the third fix.** One worker database was force-dropped 45 s
into `php artisan test --parallel --processes=2 --filter=Reservation` (627
tests):

*   without the fix: 304 errors, all "relation users does not exist" or
    "database does not exist";
*   with the fix: 627 passed.

What made the check fail on 2026-11-15 was not traced. The Postgres log does
not name databases. The fix makes a worker recover from it whatever the
cause.

**Found during verification.** In parallel mode, `php artisan test` accepts
only one path argument; serial runs accept several. A module run that covers
several folders therefore runs serially, which is the default for module runs.
