# Feature Specification: Knowledge Documents and RAG

**Feature Branch**: `008-knowledge-rag`

**Created**: 2026-10-07

**Status**: Draft

**Input**: Phase 8 — Knowledge + RAG, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's three backlog
specs as one feature: SPEC-060 Knowledge Documents & Extraction, SPEC-064 Retrieval
Precedence & Citations, and SPEC-065 Global Knowledge Management.

## Overview

The AI assistants answer hotel questions from a knowledge base. Today that knowledge can
only be typed in by hand, as knowledge base articles and hotel policies. Hotels already
have this information in files: house rules as a PDF, a spa menu as a Word file, a
shuttle timetable as a spreadsheet, a pool sign as a photo. None of these can be added. A
place to store uploaded documents exists, but nothing reads it.

Retrieval mixes the hotel's own knowledge with the shared platform knowledge, and neither
wins when they disagree. A platform default ("checkout is at 12:00") can beat the hotel's
own rule ("checkout is at 11:00"). Answers do not say where they came from, so staff
cannot check an AI answer and guests cannot tell a hotel rule from a guess. Global
knowledge has no management area of its own. It is created through the hotel-facing
endpoints by a super admin acting with no hotel.

This feature makes documents a first-class knowledge source and makes retrieval
trustworthy:

- **Knowledge documents.** Hotel staff with permission upload files. The system reads
  them, splits them into searchable passages and shows each document's indexing status.
- **Extraction pipeline.** PDF, Word, plain text, Markdown, CSV and Excel files, plus
  images, become searchable text. This runs in the background, can be repeated safely and
  can be rebuilt. A failure never changes or loses the uploaded file.
- **Retrieval precedence.** Searches combine the hotel's knowledge with global knowledge,
  and the hotel's knowledge wins when the two conflict. Another hotel's knowledge never
  appears.
- **Citations.** Every search result says which source it came from, and the Concierge and
  the Admin Advisor name that source when they answer from knowledge.
- **Global knowledge management.** The super admin manages platform-wide knowledge
  (documents and articles) in the platform admin area, separate from any hotel.

**Terms**:

- *Knowledge source*: a document, a knowledge base article or a hotel policy. All three are
  searched the same way.
- *Knowledge document*: an uploaded file plus its title, category, scope, status and
  extracted text.
- *Scope*: *hotel* (owned by one hotel and visible only to it) or *global* (owned by the
  platform and visible to every hotel's assistants).
- *Indexing*: turning a source's text into searchable passages. A source is *indexed*
  when its passages can be found by search.
- *Passage*: one searchable piece of a source's text.
- *Citation*: the facts that identify the source of a passage: title, kind of source,
  scope, location inside it (page, sheet or section) where known, and when it was last
  updated.
- *Active*: a source that may be returned by search. An inactive source is kept but never
  returned.
- *Conflict*: a hotel source and a global source that give different answers to the same
  question.

**In scope**: uploading, listing, viewing, downloading, editing, replacing, activating,
deactivating and deleting hotel knowledge documents; background extraction for the
listed formats, with status and failure reasons; re-indexing one document and rebuilding
the whole index; hotel-over-global precedence in search; source citations in search
results and in the Concierge's and Admin Advisor's answers; super-admin management of
global documents and global articles in the platform admin area; permissions, audit, usage
metering, tenant isolation and documentation.

**Out of scope (later specs)**: Insights showing citations to admins (SPEC-090, Phase 12).
This feature only makes citation data available to every agent that searches. Admin AI
tools that create or change knowledge (Phase 9). Conversation search (post-MVP, D15).
Guest memories (Phase 11). Using knowledge for live data such as availability, prices on a
date, occupancy or booking status: those always come from PMS tools (constitution I). The
frontend screens are a slice delivered in `ecosystem-frontend`, not specified here.
Fetching sources from websites or connected drives: files are uploaded directly. Virus or
malware scanning of uploads.

## Clarifications

### Session 2026-10-07

- Q: How is text read from images and scanned PDF pages? → A: By AI vision, using the
  AI provider already in use. It reads the visible text and briefly describes what the
  image shows. Each image or scanned page counts as metered AI usage for the hotel's
  account (global sources: the platform). No separate text-recognition tool is installed.
- Q: How does the system recognise that a hotel source and a global source conflict? →
  A: By ranking and instructions. Hotel results always come before global ones and are
  labelled *hotel* or *general*. The assistants are told to follow the hotel source when
  the two disagree. The system does not detect conflicts or hide global sources. Explicit
  overrides (a hotel source replacing a named global source) may come later.
- Q: How many scanned pages or images may AI vision read in one document? → A: Up to 50
  per document by default, configurable by the platform. A document with more fails
  with the reason "too many scanned pages" before any AI vision is used.
- Q: Can staff correct a document's extracted text by hand? → A: Yes. Edited text is
  indexed again and kept through re-indexing and rebuilds until the file is replaced or
  staff discard the edits.
- Q: What happens to the file and record when a document is deleted? → A: The document is
  hidden right away and can be restored for 30 days. After that, the file and passages
  are removed for good and only the audit entries remain.
- Q: When the Concierge answers a guest from global knowledge, does it name the source?
  → A: No. Hotel sources are named by title. Global sources are described only as
  "general information", without a title. Staff still see full citations for both.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A hotel uploads a document and the Concierge answers from it (Priority: P1)

The front-office manager uploads the hotel's "House Rules 2026" PDF. The list shows it as
*uploaded*, then *extracting*, then *indexed*. A guest then asks on WhatsApp "until what
time is the pool open?". The Concierge answers from the PDF.

**Why this priority**: This is the core gap. Hotels hold most of their knowledge in files,
and today none of it can reach the assistants.

**Independent Test**: Upload one file of each supported format to a hotel, wait for each to
reach *indexed*, then search for a phrase from each file and check that the matching
passage is returned for that hotel.

**Acceptance Scenarios**:

1. **Given** a staff member with permission to upload hotel documents, **When** they upload
   a supported file with a title and an optional category, **Then** the document is saved
   for their hotel with status *uploaded*, and the upload returns without waiting for
   extraction.
2. **Given** an uploaded document, **When** background processing starts and finishes,
   **Then** its status moves to *extracting* and then to *indexed*, and its passages can
   be found by that hotel's searches.
3. **Given** an indexed document, **When** a guest of that hotel asks a question the
   document answers, **Then** the Concierge's answer is based on the document's passage.
4. **Given** a file in an unsupported format, over the size limit, or whose real type does
   not match its extension, **When** it is uploaded, **Then** it is rejected with a clear
   reason and nothing is stored.
5. **Given** a document in an Arabic file, **When** it is indexed, **Then** an Arabic
   question finds its passages, and so does an English question about the same content.
6. **Given** a spreadsheet or CSV with a header row, **When** it is indexed, **Then** each
   passage keeps the column headings with its values, so a row such as "Airport shuttle,
   07:00, Gate B" can be found and understood out of context.
7. **Given** the same file was already uploaded to the same hotel, **When** it is uploaded
   again, **Then** the upload is rejected as a duplicate and points to the existing
   document.

---

### User Story 2 - Answers say where they came from (Priority: P1)

A guest asks about the cancellation policy for spa treatments. The Concierge answers and
names the source ("according to the hotel's Spa Guide"). A manager asks the Admin Advisor
the same thing and gets the answer with the document title, the page and when it was last
updated, so they can check it.

**Why this priority**: Without citations nobody can tell a hotel rule from an invented one.
That is the trust problem RAG must solve before it is extended to guests and to Insights.

**Independent Test**: Index sources with known content. Ask the Concierge and the Admin
Advisor questions they answer, and check that each answer names a source that was actually
returned by the search and that every search result carries citation details.

**Acceptance Scenarios**:

1. **Given** any knowledge search, **When** results are returned, **Then** each result
   carries its citation: title, kind of source (document, article or policy), scope
   (hotel or global), location (page, sheet or section) where known, and last-updated
   date.
2. **Given** the Concierge answers a guest from knowledge, **When** it replies, **Then** it
   names a hotel source by its title in the guest's language, or calls a global source
   "general information" with no title. It shows no file names, internal identifiers or
   storage details.
3. **Given** the Admin Advisor answers a staff member from knowledge, **When** it replies,
   **Then** it names each source with its title and location, so the staff member can open
   it.
4. **Given** a search returns nothing relevant, **When** the Concierge answers, **Then** it
   says it does not have that information and offers to pass the question to staff. It
   does not invent an answer or a citation.
5. **Given** an answer draws on two sources, **When** it is given, **Then** both are named.

---

### User Story 3 - The hotel's own rule wins over the platform default (Priority: P1)

Global knowledge says "standard checkout time is 12:00". Hotel A's house rules say
"checkout is at 11:00". A Hotel A guest asks when checkout is, and is told 11:00, citing
Hotel A's house rules. A Hotel B guest, whose hotel has no rule of its own, is told the
global default. Hotel B's guests never see anything from Hotel A.

**Why this priority**: Precedence and isolation are the rules that make shared global
knowledge safe (constitution III, rule 14). A wrong checkout time given with confidence is
worse than no answer.

**Independent Test**: Index a global source and a conflicting hotel source. Search as that
hotel, as a second hotel with no override, and as a third hotel with its own unrelated
source. Check the order, the citations and that no hotel's passage leaks into another's
results.

**Acceptance Scenarios**:

1. **Given** a hotel source and a global source answer the same question differently,
   **When** that hotel searches, **Then** the hotel's passage is listed before the global
   one, each is labelled with its scope, and the assistant answers from the hotel
   passage.
2. **Given** only a global source answers the question, **When** any hotel searches,
   **Then** the global passage is returned. The Concierge calls it "general information"
   without a title, and the Admin Advisor cites it in full.
3. **Given** Hotel A has indexed sources, **When** Hotel B searches with Hotel A's exact
   wording, **Then** no Hotel A passage is returned.
4. **Given** a hotel source is deactivated or deleted, **When** that hotel searches again,
   **Then** the global source is used again where one exists.
5. **Given** the hotel and global sources agree, **When** the hotel searches, **Then** the
   answer cites the hotel source first.

---

### User Story 4 - Indexing failures are visible and recoverable (Priority: P2)

A scanned contract, a password-protected PDF or a corrupt spreadsheet cannot be read. Staff
see the document as *failed*, with a reason they understand. They can fix the file and
replace it, or try indexing again. The original file is never changed or lost.

**Why this priority**: Silent failures leave staff believing the AI knows something it does
not. This depends on the upload flow in Story 1.

**Independent Test**: Upload files that are known to fail (corrupt, protected, no
readable text). Check the status and reason for each, that the stored file is unchanged,
and that re-indexing after a fix succeeds without duplicate passages.

**Acceptance Scenarios**:

1. **Given** a document that cannot be read, **When** extraction fails, **Then** the status
   is *failed* with a plain-language reason (for example "the file is password-protected"
   or "no readable text was found"), and the stored file is unchanged.
2. **Given** a temporary failure such as the text service being unavailable, **When**
   extraction fails, **Then** it is retried automatically a limited number of times before
   the document is marked *failed*.
3. **Given** a *failed* or *indexed* document, **When** a permitted staff member asks to
   re-index it, **Then** it is processed again, from its corrected text if it has one and
   otherwise from the stored file. Afterwards it has exactly one set of passages, never
   duplicates.
4. **Given** an indexed document, **When** staff replace its file, **Then** the previous
   version stays searchable until the new version is indexed. Once the new version is
   indexed, the previous one is no longer returned. If the new version fails, the previous
   version stays searchable and the failure is shown.
5. **Given** the same document is sent for indexing twice at once, **When** both runs
   finish, **Then** the document has one set of passages.

---

### User Story 5 - Staff keep the knowledge base current (Priority: P2)

A seasonal menu ends. The F&B manager deactivates the "Summer Menu" document so the
Concierge stops quoting it, and reactivates it next year. An outdated price list is
deleted. The title and category of a document can be edited without uploading it again.

**Why this priority**: Out-of-date knowledge is the most common way a correct system gives
wrong answers. Staff need control over what the assistants read.

**Independent Test**: Deactivate, reactivate, edit and delete indexed documents. After each
change, check that search reflects it right away.

**Acceptance Scenarios**:

1. **Given** an indexed document, **When** it is deactivated, **Then** its passages are no
   longer returned by any search, and the document stays in the list marked inactive.
2. **Given** an inactive document, **When** it is reactivated, **Then** its passages are
   returned again without extracting the file again.
3. **Given** a document, **When** its title or category is edited, **Then** citations show
   the new title and the passages are not extracted again.
4. **Given** a document, **When** it is deleted, **Then** its passages are no longer
   returned and the document no longer appears in the hotel's normal list.
5. **Given** a document deleted 10 days ago, **When** a staff member with the delete
   permission restores it, **Then** it is searchable again right away, without extracting
   the file again.
6. **Given** a document deleted more than 30 days ago, **When** anyone looks for it,
   **Then** its file, text and passages are gone for good, and only its audit entries
   remain.
7. **Given** a staff member with view permission, **When** they list documents, **Then**
   they can filter by status, category and active state, search by title, and download the
   original file.
8. **Given** existing knowledge base articles and hotel policies, **When** this feature
   ships, **Then** they keep working and stay searchable, and their results now carry
   citations too.
9. **Given** an indexed document whose extracted text has a misread time, **When** a
   permitted staff member corrects the text, **Then** search returns the corrected text,
   the stored file is unchanged, and the document shows it was corrected, by whom and
   when.
10. **Given** a document with corrected text, **When** it is re-indexed or the index is
   rebuilt, **Then** the correction is kept. **When** its file is replaced or staff discard
   the correction, **Then** the text is extracted again from the file.

---

### User Story 6 - The super admin manages global knowledge (Priority: P2)

The platform team keeps general hospitality guidance, such as standard check-in practice
or general WhatsApp etiquette, that every hotel's assistant can use. The super admin
uploads and edits global documents and articles in the platform admin area. No hotel can
change them.

**Why this priority**: Global knowledge is shared by every tenant. Its management must be
separate and super-admin only, so a hotel cannot change what other hotels' assistants
say.

**Independent Test**: As a super admin, create, edit, deactivate and delete a global
document and a global article in the platform admin area. Check that every hotel's search
sees the change. Try the same actions as a hotel admin and as an employee, and check that
they are refused.

**Acceptance Scenarios**:

1. **Given** a super admin, **When** they upload a document in the platform admin area,
   **Then** it is stored as global and, once indexed, appears in every hotel's searches.
2. **Given** a hotel admin or an employee with any role, **When** they try to create,
   change or delete global knowledge, **Then** they are refused.
3. **Given** a super admin, **When** they list global knowledge, **Then** they see global
   documents and global articles with status, and no hotel's own knowledge appears in that
   list.
4. **Given** global articles created before this feature, **When** this feature ships,
   **Then** they appear in the platform admin area and stay searchable.
5. **Given** a super admin, **When** they create knowledge through the hotel-facing
   endpoints, **Then** they must name a hotel and the knowledge is that hotel's. Global
   knowledge is created only in the platform admin area.

---

### User Story 7 - The platform rebuilds the index (Priority: P3)

After a change to how passages are created or embedded, the super admin rebuilds the
index for all knowledge, or for one hotel. Every source is indexed again from its stored
text or file. Search keeps working with the existing passages until the rebuild replaces
them.

**Why this priority**: The index is derived data and must be rebuildable (constitution).
It is needed rarely, but without it a change to the index would mean data loss.

**Independent Test**: Index a set of sources, run a rebuild, and check that each source
ends with one current set of passages and that the same test questions return the same
sources as before.

**Acceptance Scenarios**:

1. **Given** indexed sources, **When** a rebuild is started for one hotel or for
   everything, **Then** every active source in that scope is indexed again and none is
   left with duplicate or stale passages.
2. **Given** a rebuild is running, **When** an assistant searches, **Then** results still
   come back.
3. **Given** a rebuild where some sources fail, **When** it finishes, **Then** the failed
   sources are listed with reasons and keep their previous passages.

---

### User Story 8 - Pictures and scans become searchable (Priority: P3)

A hotel photographs its printed spa price board, or uploads a scanned PDF of its shuttle
timetable. The text in the image becomes searchable like any other document.

**Why this priority**: Images are common in small properties, but text files cover most
needs. This story depends on the extraction method chosen.

**Independent Test**: Upload a clear photo of a printed notice and a scanned PDF with no
text layer, in English and in Arabic. Check that the visible text can be found by search.

**Acceptance Scenarios**:

1. **Given** a JPEG, PNG or WebP image containing printed text, **When** it is indexed,
   **Then** the AI reads that text (in English or Arabic) together with a short
   description of the image, and both are searchable and cited to the image document.
2. **Given** a PDF page with no text layer, **When** it is indexed, **Then** the page is
   read by AI vision the same way, and its passages cite the page. Pages that do have a
   text layer are extracted directly, not by AI vision.
3. **Given** an image with no readable text, **When** it is indexed, **Then** the document
   is *failed* with the reason "no readable text was found".
4. **Given** an image or scanned document is indexed, **When** usage is reviewed, **Then**
   the AI vision work appears as metered usage for the hotel's account. Hotel admins do
   not see its provider cost.
5. **Given** a PDF with 51 or more pages that have no text layer, **When** it is indexed,
   **Then** it fails with the reason "too many scanned pages" and no AI vision usage is
   recorded.

### Edge Cases

- A document contains live-looking data, such as a price list or a "rooms available"
  table. Assistants still use PMS tools for availability, prices on a date, bookings and
  statuses. Knowledge is used only for descriptive text (constitution I, rule 13).
- A document mixes Arabic and English text. Both are extracted and searchable, and
  right-to-left text keeps its reading order.
- A very long document (hundreds of pages) is indexed in full or fails with a clear reason.
  It never ends up partly indexed and marked *indexed*.
- A spreadsheet has several sheets. Every sheet is indexed and citations name the sheet.
  Empty sheets are skipped.
- A CSV in a non-UTF-8 encoding or with a semicolon separator is read correctly, or fails
  with a clear reason.
- A document is deleted while it is being extracted. The run ends without leaving
  passages behind.
- A document is deactivated while being re-indexed. The new passages are stored but not
  returned until it is reactivated.
- A hotel is deleted. Its documents and passages stop being searchable, and global
  knowledge is not affected.
- The user who uploaded a document leaves the hotel. The document stays. Its uploader is
  shown as removed.
- Two hotel sources conflict with each other. Both may be returned, the newer one first,
  and the assistant names both rather than picking one silently.
- A search request comes from code with no hotel context (a background job). It searches
  only the hotel the job names, plus global knowledge, never every hotel.
- Embedding or extraction usage for a hotel's document counts toward that hotel's account
  usage and spend limit. Usage for global knowledge is attributed to the platform.
- The stored file is missing when re-indexing (lost storage). The document is *failed*
  with that reason, and its existing passages are kept.
- An employee without a staff role tries to upload. They are refused (`403`); employees
  without a role get no knowledge document permissions.

## Requirements *(mandatory)*

### Functional Requirements

**Knowledge documents (SPEC-060)**

- **FR-001**: Staff with the upload permission MUST be able to upload a file to their hotel
  with a title and an optional category. The upload MUST return before extraction
  finishes.
- **FR-002**: The system MUST accept PDF, Word (DOCX), plain text, Markdown, CSV and Excel
  (XLSX) files, and JPEG, PNG and WebP images, up to 20 MB per file. Any other format MUST
  be rejected with a reason.
- **FR-003**: The system MUST check a file's real type from its content, not only from its
  name, and MUST reject a mismatch.
- **FR-004**: A document MUST have one status: *uploaded*, *extracting*, *indexed* or
  *failed*. A *failed* document MUST show a plain-language reason.
- **FR-005**: An upload whose content is identical to an existing document in the same
  scope MUST be rejected as a duplicate and identify the existing document.
- **FR-006**: Permitted staff MUST be able to list (with filters for status, category and
  active state, and title search), view, and download the original file of their hotel's
  documents.
- **FR-007**: Permitted staff MUST be able to edit a document's title and category without
  extracting it again. Citations MUST use the new title from then on.
- **FR-008**: Permitted staff MUST be able to replace a document's file. The previous
  version MUST stay searchable until the new one is indexed, and MUST stay searchable if
  the new one fails.
- **FR-009**: Permitted staff MUST be able to deactivate and reactivate a document.
  Inactive documents MUST NOT be returned by any search, and reactivating MUST NOT require
  extracting the file again.
- **FR-010**: Permitted staff MUST be able to delete a document. A deleted document MUST
  NOT be returned by any search or appear in the hotel's normal list.
- **FR-010a**: For 30 days after deletion, staff with the delete permission MUST be able to
  list deleted documents and restore one. A restored document MUST be searchable again
  with its previous passages and corrections, without extracting the file again.
- **FR-010b**: 30 days after deletion, the system MUST permanently remove the document's
  file, extracted text and passages. Its audit entries MUST remain. A deleted document
  MUST NOT count as a duplicate of a new upload (FR-005).
- **FR-011**: Uploaded files MUST be stored privately. They MUST be readable only through
  an authorized download and MUST NOT be reachable by a public link.

**Extraction pipeline (SPEC-060)**

- **FR-012**: Extraction MUST run in the background and follow these steps: detect type →
  extract text → normalize → split into passages → index.
- **FR-013**: Normalization MUST keep Arabic and English text readable (including
  right-to-left order) and MUST remove layout noise such as repeated page headers and
  footers where they can be detected.
- **FR-014**: Passages from spreadsheets and CSVs MUST keep their column headings with
  their values. Passages from paged and sheeted files MUST record the page or sheet they
  came from.
- **FR-015**: Images, and PDF pages with no text layer, MUST be read by AI vision. It
  extracts the visible text in English or Arabic and adds a short description of the
  image. Pages that have a text layer MUST be extracted directly, without AI vision.
- **FR-015a**: AI vision MUST record only what is visible in the file. Its output is
  stored as the document's extracted text, like the text from any other format.
- **FR-015c**: Staff with permission to edit documents MUST be able to view and correct an
  indexed document's extracted text. A correction MUST be indexed again and MUST replace
  the previous passages. The stored file MUST stay unchanged.
- **FR-015d**: Corrected text MUST be kept through re-indexing and rebuilds: those use the
  corrected text and do not extract the file again. Corrections MUST be dropped only when
  the file is replaced, or when staff choose to discard them, which extracts the stored
  file again. The document MUST show whether its text was corrected, by whom and when.
- **FR-015b**: AI vision MUST read at most 50 scanned pages or images per document (a
  platform setting). A document over the limit MUST fail with the reason "too many scanned
  pages" before any AI vision runs, so no usage is spent on it.
- **FR-016**: Indexing a document MUST be idempotent. Running it again, or twice at once,
  MUST leave exactly one current set of passages for the document.
- **FR-017**: A document MUST be marked *indexed* only when all of its text has been
  indexed. A partial result MUST NOT be searchable as if it were complete.
- **FR-018**: Extraction failures MUST NOT change, move or delete the stored file. A
  temporary failure MUST be retried up to 3 times before the document is marked *failed*.
- **FR-019**: Permitted staff MUST be able to re-index one of their hotel's documents.
- **FR-020**: The super admin MUST be able to rebuild the index for one hotel or for all
  knowledge, from stored files and text, keeping staff corrections (FR-015d). Search MUST
  keep working with the existing passages during a rebuild, and failed sources MUST keep
  their previous passages.
- **FR-021**: Existing knowledge base articles and hotel policies MUST stay searchable
  through the same index, and MUST be covered by re-index and rebuild.

**Retrieval precedence and citations (SPEC-064)**

- **FR-022**: Every knowledge search MUST return only active sources of the searching
  hotel plus active global sources. It MUST NOT return any other hotel's sources,
  including from background work that has no request.
- **FR-023**: Search results MUST list every returned hotel passage before every returned
  global passage. Each result MUST be labelled with its scope (*hotel* or *general*).
- **FR-024**: The Concierge and the Admin Advisor MUST be instructed to base their answer
  on the hotel source when a hotel source and a global source disagree. Whether two
  passages disagree is AI judgement, verified by the conflict test set in SC-003. The
  system guarantees only the ordering and labelling in FR-023, and it does not hide global
  sources.
- **FR-025**: Every search result MUST carry its citation: title, kind of source, scope,
  location where known, and last-updated date.
- **FR-026**: When the Concierge answers a guest from a hotel source, it MUST name the
  source's title in the guest's language. When it answers from a global source, it MUST
  call it "general information" and MUST NOT give the global title. In both cases it MUST
  NOT expose file names, internal identifiers, storage paths or the other details only
  staff see.
- **FR-027**: When the Admin Advisor answers from knowledge, it MUST name each source with
  its title and location.
- **FR-028**: An assistant MUST NOT cite a source that the search did not return. When no
  relevant source is found, it MUST say it does not have the information. The Concierge
  MUST also offer to pass the question to staff.
- **FR-029**: Knowledge MUST NOT be used to answer live operational questions
  (availability, occupancy, bookings, statuses, prices on a date). These MUST come from
  PMS tools.
- **FR-030**: Citation data MUST be available to every agent that searches knowledge,
  including Insights, so that Phase 12 can show it.

**Global knowledge management (SPEC-065)**

- **FR-031**: Only the super admin MUST be able to create, edit, replace, activate,
  deactivate, delete, restore and re-index global documents and global articles. The
  30-day restore and removal rule (FR-010a/b) applies to global documents as well. These actions
  MUST be available only in the platform admin area.
- **FR-032**: The platform admin area MUST list global documents and global articles with
  their status. It MUST NOT list any hotel's own knowledge.
- **FR-033**: Knowledge created through the hotel-facing endpoints MUST belong to a hotel.
  A super admin using them MUST name the hotel. Global knowledge created this way before
  this feature MUST be treated as global and appear in the platform admin area.
- **FR-034**: Hotel staff MUST NOT be able to change, delete or download global sources.

**Authorization**

- **FR-035**: Viewing, uploading, editing (including replace, activate and deactivate),
  deleting and re-indexing hotel documents MUST each be a separate permission. Every
  endpoint MUST check its permission and that the document belongs to the user's hotel.
- **FR-036**: Employees without a staff role MUST NOT get any knowledge document
  permission by default.
- **FR-037**: New permissions MUST be published through the existing permissions listing,
  so the role editor shows them.

**Audit, usage and isolation**

- **FR-038**: Uploading, editing, correcting or discarding corrections to extracted text,
  replacing, activating, deactivating, deleting, restoring and re-indexing a source,
  starting a rebuild, and the permanent removal of a deleted document, MUST be recorded in the audit trail with
  the actor, the source and its scope. Indexing failures MUST be recorded with their
  reason.
- **FR-039**: AI usage from extraction and indexing MUST be metered and attributed to the
  hotel's account for hotel sources and to the platform for global sources. Hotel admins
  MUST NOT see provider cost (existing rule).
- **FR-040**: Knowledge documents and their passages MUST be hotel-scoped. Another hotel's
  staff MUST NOT be able to list, view, download, change or search them.
- **FR-041**: The knowledge document API, the global knowledge admin API and the new
  permissions MUST be documented in the matching API documentation and the permission
  reference.

### Key Entities *(include if feature involves data)*

- **Knowledge document**: an uploaded file with title, category, scope (one hotel or
  global), original file name, type, size, content fingerprint (for duplicate checks),
  status, failure reason, active flag, uploader and timestamps, plus its extracted text
  and, when staff have corrected that text, who corrected it and when. A replaced file
  keeps its previous version until the new one is indexed.
- **Knowledge passage**: a searchable piece of a source's text, with the source it belongs
  to, its scope, its order in the source, its location (page, sheet or section) and
  citation details. It is derived data and can always be rebuilt from the source.
- **Knowledge base article / Hotel policy**: existing hand-written sources. They are
  searched alongside documents and now carry citations.
- **Index rebuild**: a requested re-indexing of one hotel's or all knowledge, with who
  started it, when, its progress and the sources that failed.
- **Audit entry / usage record**: existing. They gain the knowledge actions and indexing
  usage listed above.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 95% of supported documents up to 10 MB are indexed and searchable within 5
  minutes of upload.
- **SC-002**: In an isolation test with at least 3 hotels, 0 searches return another
  hotel's passage, from requests and from background work.
- **SC-003**: In a test set of at least 10 hotel-versus-global conflicts, the assistant's
  answer follows the hotel source in 100% of cases.
- **SC-004**: 100% of Concierge and Admin Advisor answers drawn from knowledge name at
  least one source, and 0 name a source the search did not return.
- **SC-005**: A deactivated or deleted source stops appearing in answers within 1 minute.
- **SC-006**: After 100% of failed indexing runs, the stored file is byte-for-byte
  identical to the upload, and the failure shows a reason staff can act on.
- **SC-007**: After a full rebuild, every source has exactly one set of passages, and a
  fixed set of test questions returns the same sources as before the rebuild.
- **SC-008**: A staff member can upload a document and see it reach *indexed* without
  help, on their first attempt, in at least 90% of usability trials.

## Assumptions

- The existing searchable store, passage splitter and text-embedding provider are reused.
  Embedding size stays configurable, and a change to it is handled by a rebuild (FR-020).
- Search covers Arabic and English, and a question in one language can find a passage in
  the other.
- The 20 MB per-file limit is the default and can be changed by configuration.
- Excel means the XLSX format. Old binary XLS and DOC files are not supported. Staff save
  them in the current format first.
- Password-protected and encrypted files are not supported and fail with that reason.
- Hotel staff may download their own hotel's originals. Guests never receive files. The
  Concierge only quotes passages.
- The new hotel permissions follow the existing naming, for example
  `knowledge_documents.view`, `.create`, `.update`, `.delete` and `.reindex`. The final
  names are set in planning.
- Existing knowledge base article and hotel policy endpoints and permissions are kept.
  This feature does not merge them into documents (preserve and evolve).
- Malware scanning of uploads is out of scope for MVP.
- Insights uses the citation data in Phase 12 (SPEC-090).
- The frontend slice (document upload and list with status, super-admin global knowledge
  page) is delivered in `ecosystem-frontend` against this API.
