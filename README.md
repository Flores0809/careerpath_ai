# CareerPath AI — Barebone MVP

This is the first working slice of CareerPath AI: a student answers a RIASEC
self-assessment, the system scores their profile, and a Scikit-learn cosine
similarity engine matches them against a sample career database — the core
loop described in Chapter III of the capstone paper.

**What's included:**
- `database/schema.sql` — MySQL schema + 18 seed careers with RIASEC vectors, plus `pending_careers` (crawler staging), `users` (administrators + counselors), and `students`/`student_profiles`/`recommendations` (student accounts + assessment history)
- `matching-service/` — Python/Flask microservice running the Hybrid Recommendation Engine (Scikit-learn cosine similarity)
- `php/` — PHP front end: a front landing page (`index.php`) that routes to either path; student sign up/login (`student_register.php`/`student_login.php`), a student dashboard (`student_dashboard.php`), the intake form (`assessment.php`), results page (`submit.php`), assessment history (`student_history.php`), and a standing career profile page (`career_profile.php`) linked from any recommended career title; staff login (`login.php`), a staff dashboard (`dashboard.php`), career review queue (`careers.php`), live career editing (`careers_manage.php`), student search (`students_lookup.php`), and administrator-only account management (`users.php`)
- `crawler/` — four data-collection scripts staging entries into the same review queue: `crawler.py` scrapes real PH job postings from **PhilJobNet** (philjobnet.gov.ph); `onet_client.py`, `adzuna_client.py`, and `remoteok_client.py` add **international** coverage (official occupation standards + live postings across several countries) — added after panel feedback that the system needed non-PH requirements too. See section 4 below.
- **AI enrichment** — the matching-service's `/enrich` endpoint calls the Gemini API (Gemini 2.5 Flash-Lite) to turn a raw scraped posting into a polished description, daily-task list, educational pathway, and a suggested RIASEC vector, wired into `careers.php`'s "✨ Enrich with AI" button
- **Staff accounts + roles** — two roles, **administrator** (creates/manages counselor and administrator accounts, and can view/moderate student accounts, via `users.php`) and **counselor** (reviews, edits, approves, or rejects careers via `careers.php`). See section 5 below.
- **Student accounts + history** — students self-register (`student_register.php`) and log in (`student_login.php`) to take the RIASEC assessment; every submission and its ranked recommendations are saved and viewable later on `student_history.php`. This matches the STUDENT / STUDENT_PROFILE / RECOMMENDATION entities in the paper's ERD (Chapter III, Figure 11). See section 6 below.
- **Career profile page** — every recommended career title (on `submit.php`, `student_history.php`, and `student_dashboard.php`) is now a clickable link to `career_profile.php`, a standing, non-AI reference page showing that career's full description, typical tasks, educational pathway, RIASEC profile, and complete required-skills list with a match indicator against the student's own skills — available even outside the context of a specific AI recommendation call.
- **Dashboards** — logging in (either side) lands on a real dashboard, not straight into a form. `student_dashboard.php` shows assessment count, latest RIASEC snapshot, top match, and recent activity. `dashboard.php` shows the career review pipeline (pending/approved/rejected counts) and, for administrators, account counts and a recent review activity feed. See section 7 below.
- **Student Lookup** — counselors/administrators can search any student by ID, name, or email, view their full assessment history (including a per-career skills-match breakdown), and record their own consultation notes/outcomes, all logged to `counselor_log` (the ERD's COUNSELOR_LOG entity). See section 8 below.
- **Manage Careers** — counselors/administrators can edit any career already live in the database (or deactivate/reactivate it without deleting it), refresh its RIASEC scores with one click via AI, and manage its list of required skills. See section 9 below.
- **Skills verification** — students can optionally list their own skills and academic average during the assessment; recommendations then show what percentage of a career's required skills the student already meets, and which ones to develop — the mechanism named in the paper's Specific Objective 2 / Research Gap #4. See sections 6 and 9 below.
- **Traits to strengthen + recommended subjects (migration 15)** — every career recommendation now also shows which RIASEC traits the career leans on more than the student's current profile (a gap analysis, distinct from the "Why this match?" overlap breakdown), and a curated list of JHS/SHS subjects (`key_subjects`, editable on `careers.php`/`careers_manage.php`) worth focusing on for that specific career — so a recommendation comes with something concrete to act on, not just a percentage.
- `docs/CareerPath_AI_Evaluation_Questionnaire.docx` — a ready-to-use 20-item, 4-point Likert questionnaire (Functionality, Usability, Efficiency, Accuracy of Recommendations, User Satisfaction) matching the paper's ISO/IEC 25010 evaluation plan, for reproducing in Google Forms or printing for face-to-face sessions.
- **Profile Management** — students can edit their name/email/grade level and change their password on `student_profile.php`. Pairs with the Student Dashboard per the Gantt chart's own wording ("Develop the Student Dashboard and Profile Management module").
- **Dashboard analytics** — `dashboard.php` now shows the average RIASEC profile across all students and the most-recommended careers, alongside the existing review-pipeline stats — the "analytics" half of "Develop the Guidance Counselor Dashboard (... recommendation results, consultation history, and analytics)".
- **Consultation Request & Appointment Scheduling** — students request a consultation (with an optional reason and preferred date/time) on `request_consultation.php`; counselors/administrators pick it up, schedule it, and leave a note on `consultations.php`. Backed by the new `consultations` table.
- **Notification Module** — in-app notifications (no email/SMS) for assessment completion and consultation status changes, with an unread-count badge in both nav bars and dedicated pages (`student_notifications.php`, `staff_notifications.php`). Backed by the new `notifications` table.
- **Administrator Module additions** — `settings.php` (editable system settings, e.g. how many careers the matching engine recommends), `backup.php` (one-click export of every application table as a downloadable `.sql` file), and `audit_log.php` (a cross-student, cross-counselor view of `counselor_log` — the ERD's COUNSELOR_LOG entity — with filters by staff/student/action).
- **Downloadable/printable assessment reports** — `assessment_report.php?profile_id=...` is a print-friendly report (RIASEC profile + full recommendation details) reachable from `student_history.php` (the student's own) and `students_lookup.php` (any student, for staff). Uses the browser's own "Print to PDF" rather than a server-side PDF library.

**⚠️ Paper-alignment note (2026-07 Gantt chart pass):** the group's Gantt chart lists several Software Development items that were built in this pass but are **not** named anywhere in the capstone paper's ERD (Chapter III) or Use Case Diagram (Figure 8): **Consultation Request & Appointment Scheduling**, the **Notification Module**, and the Administrator Module's **System Settings** and **Database Backup** pieces. They were built anyway per direct instruction ("still do it for now"), but if the panel asks where they're documented in the paper, they currently aren't — either add them to the paper's scope (Use Case Diagram, ERD, Chapter III narrative) before defense, or be ready to explain them as implementation additions beyond the original design. The **Audit Log** viewer and **Profile Management**/**Dashboard analytics** are on firmer ground: `counselor_log` is already a named ERD entity, and Profile Management / analytics are literally in the paper's own task wording for the Student Dashboard and Guidance Counselor Dashboard.

---

## 1. Set up the database

Open phpMyAdmin (http://localhost/phpmyadmin) or use the MySQL command line
that ships with XAMPP:

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
```

This creates the `careerpath_ai` database, seeds it with 18 sample careers,
and creates the empty `pending_careers` table used by the crawler below.

If you'd rather use phpMyAdmin's UI: go to http://localhost/phpmyadmin →
Import tab → choose `database\schema.sql` → Go. Either way works, it's the
same file.

**Already have a database from before?** Don't re-import `schema.sql` — it
will drop and reset your existing `careers`/`pending_careers` data. Instead,
run these migrations in order (phpMyAdmin → SQL tab → paste → Go):

1. `database/migration_2_ai_enrichment.sql` — adds the AI-enrichment columns.
2. `database/migration_3_users_auth.sql` — adds the `users` table and a
   `reviewed_by` column on `pending_careers`.
3. `database/migration_4_student_accounts.sql` — adds `students`,
   `student_profiles`, and `recommendations` tables.
4. `database/migration_5_counselor_log.sql` — adds the `counselor_log` table.
5. `database/migration_6_career_status.sql` — widens `careers.status` to add `'inactive'`.
6. `database/migration_7_international_sources.sql` — adds `data_source`/`country` columns to `pending_careers`.
7. `database/migration_8_skills_verification.sql` — adds `proficiency_level` to `skill_requirements`, and `skills`/`academic_average` columns to `student_profiles`.
8. `database/migration_9_counselor_outcomes.sql` — adds a `notes` column to `counselor_log`.
9. `database/migration_10_consultations_notifications_settings.sql` — adds the `consultations`, `notifications`, and `system_settings` tables (see the paper-alignment note above — these back Gantt-chart items not named in the paper's ERD/Use Case Diagram).

After running migration 3 (or a fresh `schema.sql` import), continue to
section 5 below to create your first administrator account — nothing in
`careers.php` or `users.php` will work until that account exists. Migrations
4 and 5 don't need any manual bootstrap step — students just sign up at
`student_register.php` whenever they're ready (see section 6), and
`counselor_log` fills in automatically as staff use Student Lookup
(see section 8). Migration 6 just needs to run once before using Manage
Careers (see section 9) to deactivate a career. Migration 7 needs to run
once before running any of the international crawler scripts (see section 4).
Migration 8 needs to run once before the skills-verification fields show up
in the assessment form and Manage Careers (see section 9). Migration 9 needs
to run once before counselors can save notes on Student Lookup (see section 8).

## 2. Run the matching microservice (Python)

Open a terminal in `C:\xampp\htdocs\careerpath-ai-mvp\matching-service`:

```bash
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt

REM Set DB credentials if not using XAMPP defaults (root / no password / localhost)
set DB_USER=root
set DB_PASSWORD=

REM Optional — only needed for AI enrichment (step 5). Without this, /match
REM still works fine; /enrich will just report itself unavailable.
set GEMINI_API_KEY=...

python app.py
```

This starts the microservice at `http://localhost:5000`. Check it's alive by
visiting `http://localhost:5000/health` in your browser — you should see
`{"status": "ok", ..., "ai_enrichment_configured": true}` (or `false` if you
skipped the `GEMINI_API_KEY` line above).

**Leave this terminal window running** while you use the system — it's a
separate process from Apache/XAMPP.

## 3. Run the PHP front end

Since this is already inside `htdocs`, just make sure Apache is running in
the XAMPP Control Panel, then visit the front landing page:

```
http://localhost/careerpath-ai-mvp/php/index.php
```

This is the public entry point — it shows a **Students** card (log in /
sign up) and a **Staff** card (log in), since those are two separate login
systems. Create a student account (see section 6 below), and you'll land on
your dashboard (`student_dashboard.php` — see section 7), from which you can
take the assessment (`assessment.php`). Answer the 24 questions and submit —
`submit.php` scores your answers, calls the Flask microservice (step 2) for
your ranked recommendations, and saves the submission to your history.

## 4. Run the web crawler (optional — populates more careers)

The crawler pulls real, currently-posted job vacancies from
[PhilJobNet](https://philjobnet.gov.ph) — this is the actual DOLE/PESO portal
referenced in the paper (PESO's Employment Information System runs on the
same DOLE/Bureau of Local Employment infrastructure). It searches a fixed
list of occupation keywords (nurse, teacher, accountant, software developer,
electrician, welder, civil engineer, graphic designer, sales, chef) and
stages whatever it finds in `pending_careers` — nothing reaches students
until a counselor or administrator approves it.

```bash
cd C:\xampp\htdocs\careerpath-ai-mvp\crawler
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt

set DB_USER=root
set DB_PASSWORD=

python crawler.py
```

It prints progress as it goes (`[+] Staged: ...`) and rate-limits itself
(2-second delay between requests, max 5 postings per keyword per run) to be
a polite, respectful crawler against a real government site.

Then log in (see section 5 below for creating your first account) and open
the career review queue:

```
http://localhost/careerpath-ai-mvp/php/careers.php
```

Each scraped posting shows up as an editable card — pre-filled with the
scraped title/description/qualifications and a rule-based RIASEC score
suggestion based on which keyword found it. Edit anything that looks off,
then click **Approve into career database** (it becomes a real row in
`careers`, immediately usable by the matching engine) or **Reject**. Both
counselors and administrators can do this; `careers.php` records who
reviewed each entry (`reviewed_by`) for later auditing.

> **Heads up on parsing:** the crawler's page-parsing logic was built and
> tested against the real, live structure of philjobnet.gov.ph (verified via
> direct inspection), but government sites occasionally tweak their markup.
> If a scraped card comes through with a field blank (e.g. missing location),
> that's fine — just fill it in manually on the admin page before approving.
> Tell me what came through wrong and I can adjust the parser's selectors.

### International sources (O*NET, Adzuna, RemoteOK)

The panel flagged that the system only reflected PH job requirements — these
three scripts add international coverage, all staging into the same
`pending_careers` queue as the PH crawler above, tagged by `data_source` and
`country` so reviewers can tell them apart on `careers.php` (which has a
**Filter by source** dropdown for exactly this).

**Why not scrape Indeed/LinkedIn/Glassdoor for this instead?** Those sites
actively fight scraping with bot-detection and CAPTCHAs, and their Terms of
Service prohibit it — not something this project will do regardless of the
reason. All three sources below are free, public, and explicitly designed
for this kind of integration instead.

**`onet_client.py`** — official occupation standards from the US Department
of Labor's O*NET (the same RIASEC/Holland Code standard this whole system's
assessment is built on, just applied internationally). Needs a free
developer account:

```bash
# 1. Sign up: https://services.onetcenter.org/developer/signup
# 2. You'll get a username/password (HTTP Basic Auth, not an API key)

cd C:\xampp\htdocs\careerpath-ai-mvp\crawler
venv\Scripts\activate

set ONET_USERNAME=your_username
set ONET_PASSWORD=your_password
set DB_USER=root
set DB_PASSWORD=

python onet_client.py
```

Entries land tagged `country = "International (O*NET)"`. Since O*NET reports
occupation *standards* rather than live job ads, these entries won't have an
employer/location/salary — that's expected, not a bug.

**`adzuna_client.py`** — real, live job postings across five countries (US,
UK, Australia, Canada, and Singapore — the last one deliberately included as
a common OFW destination for Filipino workers) via Adzuna's free public
Jobs API:

```bash
# 1. Register: https://developer.adzuna.com/signup
# 2. You'll get an app_id and app_key

set ADZUNA_APP_ID=your_app_id
set ADZUNA_APP_KEY=your_app_key
set DB_USER=root
set DB_PASSWORD=

python adzuna_client.py
```

**`remoteok_client.py`** — real, live remote job postings from RemoteOK's
public API. No signup or API key needed at all:

```bash
python remoteok_client.py
```

RemoteOK's own feed skews tech/design/marketing-heavy, so keywords like
"welder" or "electrician" may simply find nothing there — that's expected,
not a failure.

All three follow the same review flow as the PH crawler: nothing reaches
students until a counselor or administrator approves it on `careers.php`,
and each still gets a rule-based RIASEC *suggestion* to be reviewed/adjusted
on approval (`onet_client.py`'s suggestion is grounded a bit further, since
it also pulls each occupation's official top RIASEC interest from O*NET's
own data where available).

## 5. Staff accounts + roles (administrators & counselors)

Two roles share the staff side of the system:

| | **Administrator** | **Counselor** |
|---|---|---|
| Create/disable/edit staff accounts (`users.php`) | ✅ | ❌ |
| Review, edit, approve, or reject careers (`careers.php`) | ✅ | ✅ |
| Run "✨ Enrich with AI" | ✅ | ✅ |

**First-time setup — create the first administrator:**

```
http://localhost/careerpath-ai-mvp/php/setup_admin.php
```

This page only works once — as soon as one administrator account exists, it
locks itself (visiting it again just shows "setup already complete"), so it
can't be used later to plant a rogue admin. Fill in a name, email, and
password (min. 8 characters); you'll be logged in automatically and dropped
onto the staff dashboard (`dashboard.php` — see section 7).

**From then on:**
- Everyone logs in at `http://localhost/careerpath-ai-mvp/php/login.php`.
- The logged-in administrator creates counselor (or additional
  administrator) accounts from `users.php` — set a name, email, temporary
  password, and role. Accounts can be edited, disabled, or have their
  password reset from the same page.
- An administrator can't disable or demote *themselves* if they're the last
  active administrator — this prevents accidentally locking everyone out of
  account management.
- Passwords are stored using PHP's `password_hash()` (bcrypt), never in
  plain text.

**Note on the student intake form:** `assessment.php`/`submit.php` require a
*student* login (a separate account system from staff — see section 6),
not a staff login. A counselor/administrator account cannot log into the
student intake form and vice versa. Both paths start from the same landing
page, `index.php`.

## 6. Student accounts + assessment history

Students have their own, separate login system from staff — signing up
doesn't require an administrator to create anything for them:

```
http://localhost/careerpath-ai-mvp/php/student_register.php
```

Fill in name, email, grade level (optional), and a password (min. 8
characters). You're logged in immediately and taken to your dashboard
(`student_dashboard.php` — see section 7). From then on, log in at
`student_login.php` — or just start from the landing page (`index.php`),
which shows a **Student Log In** button.

**Assessment history:** every time a student submits the RIASEC form, the
submission (their R/I/A/S/E/C scores) and the ranked careers they were shown
are saved. Students can revisit this anytime at:

```
http://localhost/careerpath-ai-mvp/php/student_history.php
```

This is the STUDENT_PROFILE (one row per submission) and RECOMMENDATION
(one row per career shown, linking a submission to a career with its match
score) entities from the paper's ERD — a student can retake the assessment
as many times as they like, and each attempt is kept separately rather than
overwriting the last one.

**Skills verification + academic average (migration 8):** the assessment
form also has two optional fields — a free-text list of skills the student
already has, and their academic average. Both are stored per-submission
alongside the RIASEC scores (STUDENT_PROFILE.skills /
STUDENT_PROFILE.academic_average in the ERD). If skills are provided, each
recommended career on the results page (and later on `student_history.php`
and `students_lookup.php`) shows what percentage of that career's required
skills (set in Manage Careers, section 9) the student already has, plus
which ones are still missing — the "skills verification mechanism" named in
the paper's Specific Objective 2 and Research Gap #4. Matching is a simple
case-insensitive text comparison, consistent with the project's rule-based
approach (no custom-trained ML). A career with no required skills defined
yet simply won't show a skills-match percentage.

**Administrator visibility:** administrators can see the list of student
accounts (name, email, grade level, number of assessments taken) on
`users.php`, and can disable/re-enable an account (e.g. for misuse) — but
cannot edit a student's details or reset their password, since students
manage their own accounts.

**Data handling note (per the paper's ethics section, Chapter III):** the
paper's Data Privacy discussion calls for de-identifying data before it's
fed into the AI/matching layer, not for anonymous accounts — students still
log in and their submissions are tied to their account for the history
feature to work. In practice, this system already keeps that separation:
only the RIASEC score vector (no name/email) is ever sent to the Flask
matching service or the Gemini enrichment endpoint.

## 7. Dashboards

Logging in — either as a student or as staff — now lands on a real
dashboard instead of dropping you straight into a form:

**Student dashboard** (`student_dashboard.php`):
- Assessments taken (count), latest top match career + %, member since date
- Latest RIASEC snapshot as a bar chart (R/I/A/S/E/C, from the most recent submission)
- Recent activity: last 5 submissions with the top career shown for each
- Quick actions: Take the Assessment, View Full History

**Staff dashboard** (`dashboard.php`):
- Pending / approved / rejected career counts, plus total active careers
- For administrators only: student / active-counselor / active-administrator counts
- Recent review activity feed — who approved or rejected what and when, using the `reviewed_by`/`reviewed_at` columns on `pending_careers`
- Quick actions: Career Review (with a pending-count badge), and Manage Accounts for administrators

Both dashboards pull live counts from the database on every page load —
there's no caching, so the numbers are always current.

## 8. Student Lookup (counselors connected to student accounts)

Counselors and administrators can search for any student and see their full
assessment history — not just their own history like `student_history.php`,
but any student's:

```
http://localhost/careerpath-ai-mvp/php/students_lookup.php
```

Search by student ID (e.g. `7`), full or partial name, or email. Each result
row shows the student's grade level, how many assessments they've taken, and
their account status, with a **View →** link. Opening a student's profile
shows every submission they've made, each with its RIASEC breakdown and the
careers recommended for it — the same information `student_history.php`
shows the student themselves, just from the staff side.

`users.php`'s Students section also has a **View History** shortcut per
student that jumps straight into this page.

**Audit trail:** every time a counselor or administrator opens a student's
profile here, a row is written to `counselor_log` (migration 5) — the
COUNSELOR_LOG entity from the paper's ERD, which "documents explicit system
interactions indexed by specific counselors."

**Counselor notes & final-outcome recording (migration 9):** a student's
profile page now also has a collapsible **📝 Counselor Notes & Outcomes**
button (shown expanded automatically right after saving a note) — kept
visually separate from the Assessment History section above it. This is the
"Counselor Reviews, Records & Submits Final Outcome" step from the paper's
System Flowchart (Figure 10) — something more than the automatic
`viewed_profile` audit rows above. A counselor can type a free-text note
(optionally tied to one of the student's specific recommendations, e.g.
"Discussed results; leaning toward Civil Engineering; follow-up scheduled
next week") and save it. Notes are stored in `counselor_log.notes` with
`action = 'recorded_outcome'`, and are shown in the same chronological list
as the view-audit entries, but visually distinguished — and labeled
"visible to student," because they are: unlike the automatic view-audit
trail, these notes are **shown to the student themselves** on
`student_history.php` (under a "Notes from Your Counselor" panel), and a
count badge appears on `student_dashboard.php` linking straight there when
there's anything new.

**Skills verification (migration 8):** if a student listed skills during
their assessment (see section 6), each recommendation shown here also
displays a skills-match percentage — which required skills for that career
the student already has, and which ones they still need. This is computed
live from `skill_requirements` (set per career in Manage Careers, section 9),
not stored, so it always reflects the current skill requirements.

## 9. Manage Careers (editing careers already live in the database)

`careers.php` only covers careers still sitting in `pending_careers`,
awaiting approval. Once a career is approved (or was seeded from
`schema.sql`), fixing a typo or updating a stale description needed a direct
database edit — until now:

```
http://localhost/careerpath-ai-mvp/php/careers_manage.php
```

Search by title, then expand any career to edit its title, description,
daily tasks, educational pathway, or RIASEC scores, and click **Save
Changes**. Both counselors and administrators can do this.

**Deactivate instead of delete:** there's no delete button here on purpose.
`recommendations.career_id` references `careers` with `ON DELETE CASCADE`,
so deleting a career would silently wipe every student's saved
recommendation history that included it. **Deactivate** instead sets
`status = 'inactive'`, which the matching engine (`app.py`, `WHERE status =
'active'`) simply stops matching against — the row and all history
referencing it stay intact, and **Reactivate** brings it back.

**One-click AI RIASEC score refresh (migration 8 not required for this part):**
each career's edit form has a **🔄 Refresh RIASEC with AI** button alongside
**Save Changes**. It calls the same Gemini `/enrich` endpoint the crawler
review queue uses, but only takes the suggested RIASEC vector — nothing is
written to the database yet. The suggested R/I/A/S/E/C values are shown
pre-filled in the form (the row auto-expands, with a purple "AI-suggested"
note) so the counselor can review and adjust them, then click **Save
Changes** to actually commit — matching the paper's "for administrator
review and confirmation" design. If the AI service is unavailable, the
existing scores are left untouched and an error is shown instead, same
fallback behavior as `careers.php`'s "Enrich with AI."

**Required skills (migration 8):** below the deactivate/reactivate button,
each career has a "Required skills" section listing anything already added,
each removable with one click, plus a small form to add a new skill
(name, proficiency — basic/intermediate/advanced — and whether it's
required or just nice-to-have). This is what the skills-verification
percentage on `submit.php`, `student_history.php`, and `students_lookup.php`
is computed against — a career with no skills listed here just won't show a
skills-match percentage yet.

## 10. AI enrichment (optional — improves scraped career descriptions)

Raw job postings from PhilJobNet are written for adult jobseekers, not
JHS/SHS students — often terse, jargon-heavy, or missing fields entirely.
The `/enrich` endpoint on the matching-service asks **Gemini 2.5
Flash-Lite** (via Google's `google-genai` SDK) to turn a career title +
whatever raw text the crawler found into: a 2-3 sentence student-friendly
description, a short list of daily tasks, a suggested Philippine
educational pathway, and a suggested RIASEC vector — returned as
schema-validated structured output (Pydantic), not free-form text.

**To get an API key:**
1. Go to https://aistudio.google.com/apikey (sign in with a Google account; Gemini API has a free tier, no billing required to start).
2. Click "Create API key," copy it.
3. Set it as `GEMINI_API_KEY` before running `app.py`, as shown in step 2 above.

**To use it:** on `http://localhost/careerpath-ai-mvp/php/careers.php`, click
**✨ Enrich with AI** on any pending card. The description, daily tasks,
educational pathway, and RIASEC fields on that card will refresh with the
AI's suggestions — a purple "AI-enriched" badge appears once it's done. You
can still edit anything before clicking Approve; nothing is saved to the
live `careers` table until you do.

**Cost note:** every click is one API call, billed per token by Google —
Gemini 2.5 Flash-Lite is their cheapest/fastest 2.5-series model, and the
free tier covers a generous number of requests per day for a project this
size. Check current pricing and free-tier limits at
https://ai.google.dev/gemini-api/docs/pricing before doing this at scale.
Override the model by setting `GEMINI_MODEL=...` alongside `GEMINI_API_KEY`
if you want to try a different one.

**Fallback behavior:** if `GEMINI_API_KEY` isn't set, or the Gemini call
fails for any reason (network issue, rate limit, invalid key), clicking
"Enrich with AI" shows a red message explaining why, and the raw scraped
fields are left completely untouched — you can still edit and approve them
manually. This matches the paper's described fallback design: AI enrichment
is a nice-to-have layered on top of the crawler, never a requirement for the
system to keep functioning.

---

## How the matching actually works

1. The intake form (`assessment.php`) has 4 statements per RIASEC type (24 total), each on a 1–4 scale.
2. `submit.php` sums each type's answers (max 16) and normalizes to a 0–1 score — this is the student's RIASEC vector.
3. That vector is POSTed as JSON to the Flask `/match` endpoint.
4. `app.py` pulls all careers from MySQL, normalizes their stored RIASEC scores the same way, **mean-centers both the student vector and every career vector**, and computes cosine similarity between them using Scikit-learn (`profile_similarity()`). The centered-cosine result is mathematically the Pearson correlation between the two profiles' *shapes*, rescaled from [-1, 1] to [0, 100] so it always reads as a normal percentage.
5. The top N (default 5) careers are returned ranked by match percentage and rendered on the results page.

**Why mean-center first:** plain cosine similarity on the raw 0-1 vectors was tried first, but every RIASEC vector (student or career) lives in the same all-positive octant of 6D space — there's no "opposite direction" a mismatched profile can point in. In practice this meant almost every career scored 90%+ for almost every student, and mismatched careers (e.g. Architect for a strongly hands-on Realistic student) could outrank genuinely closer fits (Welder, Electrician) purely from baseline overlap on the other 5 dimensions. Mean-centering each profile before comparing removes that shared baseline and scores relative shape instead (which traits are high/low *versus that profile's own average*), which is what actually separates a strong fit from a mediocre one — verified by re-running the matcher against the full 46-career catalog with simulated realistic answer patterns (see git history around the fix for the before/after numbers).

This still matches the "Hybrid Recommendation Engine" described in the
paper's Technology Stack section — cosine similarity via Scikit-learn is
still exactly what's computed, just on centered vectors — a rule-based
algorithmic approach that runs independently of any external AI service, so
it keeps working even if an AI enrichment layer (e.g. the Gemini API) is
added later or goes down.

## Troubleshooting

- **"Could not reach the matching engine"** on the results page — the Flask
  service (step 2) isn't running, or is running on a different port. Make
  sure `python app.py` is still active in its terminal.
- **MySQL connection errors from `app.py`** — check `DB_USER`/`DB_PASSWORD`
  env vars match your XAMPP MySQL setup (default is usually `root` with no
  password).
- **Blank page from `index.php`, `assessment.php`, `dashboard.php`,
  `student_dashboard.php`, or `careers.php`** — check Apache's error log
  (`C:\xampp\apache\logs\error.log`) for a PHP syntax or path issue.
- **Dashboard shows 0s for everything / a database error** — make sure
  you've run `migration_4_student_accounts.sql` (for `student_dashboard.php`,
  which reads `student_profiles`/`recommendations`) and that at least one
  career/account exists for the counts to reflect.
- **Redirected to `student_login.php` when visiting `assessment.php`
  directly** — that's expected; the intake form requires a student
  account. Sign up at `student_register.php` first, or start from the
  landing page (`index.php`).
- **A staff (administrator/counselor) login doesn't work on the intake
  form, or vice versa** — students and staff are two entirely separate
  account systems (`students` vs `users` tables, separate session keys).
  Use `student_login.php` for the intake form and `login.php` for
  `careers.php`/`users.php`. The landing page (`index.php`) shows the
  right login button for each.
- **`students_lookup.php` search returns nothing for a student you know
  exists** — search matches student ID (exact), or name/email (partial,
  case-insensitive substring). Try just part of the name or email.
- **"That student account could not be found"** on `students_lookup.php`
  — the `view` ID in the URL doesn't match any row in `students`; go back
  and search again rather than editing the URL by hand.
- **Crawler prints `[!] Could not fetch listing`** — check your internet
  connection, or PhilJobNet may be temporarily down; just re-run later.
- **Crawler finds 0 job links for a keyword** — the site's markup may have
  changed slightly; let me know which keyword and I'll adjust the selectors
  in `crawler/crawler.py`.
- **`onet_client.py` raises "Set ONET_USERNAME and ONET_PASSWORD"** — sign
  up free at https://services.onetcenter.org/developer/signup and set both
  env vars before running it.
- **`adzuna_client.py` raises "Set ADZUNA_APP_ID and ADZUNA_APP_KEY"** —
  register free at https://developer.adzuna.com/signup and set both env
  vars before running it.
- **`onet_client.py`/`adzuna_client.py`/`remoteok_client.py` insert nothing
  new** — make sure `migration_7_international_sources.sql` has been run;
  without the `data_source`/`country` columns, their INSERTs will fail with
  a column-count/unknown-column error.
- **`remoteok_client.py` finds 0 matches for most keywords** — expected;
  RemoteOK's feed skews tech/design/marketing-heavy, so occupations like
  "welder" or "chef" often just aren't represented there.
- **`careers.php` shows a PDO connection error** — check `DB_USER`/`DB_PASS`
  in `php/config.php` match your XAMPP MySQL setup.
- **"403 — Access denied"** — you're logged in but your role doesn't have
  permission for that page (e.g. a counselor visiting `users.php`, which is
  administrator-only). Log in as an administrator instead.
- **Can't get past `setup_admin.php`** — it only works when zero
  administrator accounts exist. If one already exists and you've lost the
  password, an existing administrator needs to reset it from `users.php`, or
  you can manually delete the row from the `users` table in phpMyAdmin to
  re-open the bootstrap page (only do this if you're sure no real accounts
  depend on it).
- **`admin.php` gives a redirect instead of the review page** — that's
  expected; it now just forwards to `careers.php` for anyone with an old
  bookmark.
- **Edited a career on `careers_manage.php` but students still see the old
  version** — the matching engine (`app.py`) caches nothing, so this
  shouldn't happen; if it does, confirm `python app.py` is actually running
  the current code (restart it) rather than a stale process.
- **A deactivated career still shows up in recommendations** — make sure
  you ran `migration_6_career_status.sql`; without it, the `status` column
  doesn't accept `'inactive'` and the toggle silently fails to change it
  (check for a MySQL data-truncation warning).
- **"AI enrichment unavailable (GEMINI_API_KEY is not set)"** — you clicked
  "Enrich with AI" without setting the key when you started `app.py`. Stop
  it (Ctrl+C), `set GEMINI_API_KEY=...`, and run `python app.py` again.
- **"AI enrichment unavailable" with some other error** — usually an invalid
  key, a rate limit (free tier has daily/per-minute caps), or a model name
  typo in `GEMINI_MODEL`. The error message from Google is shown as-is so
  you can look it up; the raw scraped data is never lost when this happens.
- **"🔄 Refresh RIASEC with AI" says unavailable** — same cause/fix as the
  "AI enrichment unavailable" errors above (it's the same Gemini `/enrich`
  endpoint); the career's existing RIASEC scores are left unchanged.
- **No skills-match percentage shows up for a career** — either the student
  didn't list any skills during their assessment, or that career has no
  required skills defined yet in Manage Careers (section 9). Both are
  optional, so this is expected until skills are added on both sides.
- **`Unknown column 'skills'/'academic_average'/'proficiency_level'` errors**
  — run `migration_8_skills_verification.sql` (see section 1).
- **`Unknown column 'notes' in field list`** on `students_lookup.php` —
  run `migration_9_counselor_outcomes.sql` (see section 1).

## Suggested next milestones

1. Expand the crawler's keyword list and/or handle PhilJobNet's paginated results (currently only page 1 per keyword, since deeper pages use ASP.NET postback navigation rather than a plain URL).
2. ~~A "✨ Enrich with AI" button on `careers_manage.php`~~ — done: see "One-click AI RIASEC score refresh" in section 9. A natural follow-up would be a **bulk** "refresh all active careers" run (today it's one career at a time, reviewed individually before saving), since the paper's Use Case Diagram describes refreshing "all career records."
3. An audit log for `careers_manage.php` text/description edits specifically (who changed what, when) — today `reviewed_by` covers the pending-career approve/reject flow and skill/RIASEC-refresh actions are visible in the flash message, but a full changed-field history isn't kept.
4. ~~A viewer UI for `counselor_log`~~ — done: see "Counselor notes & final-outcome recording" in section 8.
5. Password reset via email (currently, a forgotten student password has no self-service recovery — only a staff-driven account disable/re-enable).
6. A trend chart on the student dashboard (RIASEC scores over multiple submissions), once students have retaken the assessment enough times for a trend to be meaningful.
7. Pagination on `students_lookup.php`/`careers_manage.php` search results (currently capped at 25/200 matches) once the lists grow large.
8. Full 6-dimension RIASEC scores per O*NET occupation (today `onet_client.py` only pulls the single official top interest and boosts that letter over the keyword-based baseline) — would need iterating O*NET's per-interest browse endpoints to build a proper lookup.
9. More Adzuna countries/keywords per run (currently 5 countries × 10 keywords, capped at 2 results each, to keep runs quick and considerate of the free API tier).
10. Smarter skills matching — `skills_helper.php` currently does simple case-insensitive substring matching between a student's free-text skills and a career's required skills (e.g. "excel" won't match "Microsoft Excel" unless one contains the other as written). A synonym list or light NLP normalization would catch more real matches.
11. Distribute `docs/CareerPath_AI_Evaluation_Questionnaire.docx` — reproduce its 20 items in Google Forms (per the paper's Data Collection Method) once you're ready to run the ISO/IEC 25010 evaluation with actual JHS/SHS students, teachers, and guidance counselors at MEII.
