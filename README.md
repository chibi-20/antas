# TAPAT — Teacher Assessment, Performance Aggregation and Tracking System

Plain PHP + MySQL/MariaDB app. No Node, no npm, no build step, no framework. Runs directly under XAMPP.

## Setup (local, XAMPP)

1. Start Apache and MySQL from the XAMPP control panel.
2. Create a database (default expected name: `antas_grades`) — via phpMyAdmin or:
   ```bash
   php -r "\$c = require 'config/config.php'; \$p = new PDO('mysql:host='.\$c['db']['host'].';port='.\$c['db']['port'], \$c['db']['user'], \$c['db']['pass']); \$p->exec('CREATE DATABASE IF NOT EXISTS '.\$c['db']['name'].' CHARACTER SET utf8mb4');"
   ```
3. Copy `config/config.example.php` to `config/config.php` and fill in real values (a `config.php` with XAMPP defaults already exists for local dev). Generate a fresh `session_secret` per environment:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
4. Run migrations, then seed baseline data:
   ```bash
   php db/migrate.php
   php db/seed.php
   ```
5. Visit `http://localhost/antas/`.

The seed script creates one admin account — check the console output it prints for the generated username/password, and **change the password immediately** after first login.

## Deploying to a shared/friend's server

No Docker, no Node runtime needed. Upload the project files (FTP or git), create a MySQL/MariaDB database, set up `config/config.php` with the real credentials (never commit this file), then run `php db/migrate.php` and `php db/seed.php` once via SSH/CLI (or a temporary browser-accessible script you delete afterward). Point the web root at this folder.

## Grading rules: DepEd Order No. 15 s. 2026

Grade computation follows DepEd Order No. 15 s. 2026:

- **Components** are Written Work (WW), Performance Task (PT), and Examinations (EX) — EX replaces the older "Quarterly Assessment (QA)" terminology throughout the codebase (`assessment_items.component_type`, `term_grades.ex_pct`, `grade_weight_profiles.examination_pct`).
- The school now runs **3 terms, not 4 quarters** — `assessment_items.term`, `term_grades.term` (renamed from `quarterly_grades.quarter`), and `submission_status.term` all range 1-3 (see `db/migrations/0005_terms_not_quarters.sql`).
- **Weight profiles** (Key Stage 2 & 3, Grades 4-10), seeded in `db/seed.php`:
  - English, Filipino, Math, Science, AP, GMRC/Values Education: WW 20% / PT 50% / EX 30%
  - EPP/TLE, MAPEH: WW 20% / PT 60% / EX 20%
  - Other grade bands (Kindergarten, Grades 1-3, SHS) aren't covered yet — add their weight profiles the same way once you have those numbers.
- **Transmutation** (Initial Grade → Transmuted Grade) uses the real SY 2026-2027 Adjusted Transmutation Table (zero-based grading transition: Initial Grade 70.00 → passing Transmuted Grade of 75), stored as 41 precise boundary rows in `transmutation_table` and looked up directly in `includes/gradeCalc.php::compute_transmuted_grade()` — the raw (unrounded) initial grade is matched against the table, never rounded to a whole number first, since the boundaries are 2-decimal-precise.
- Each class record is expected to have **5 Written Work, 3 Performance Task, and 3 Examination items** (2 summative + 1 term exam) per term — teachers add these individually via "Add Assessment Item" on the class record page; there's no auto-templating of that item count yet.

## Roles

- **Subject Teacher** — encodes WW/PT/EX scores for their own subject+section, submits a term for review.
- **Head Teacher / Coordinator** — reviews submitted grades for subjects they supervise, publishes or returns for revision with a comment.
- **Adviser** — views consolidated grades (published subjects only) for their advisory section, general average + rank, exports CSV for the external Proficiency Level System (pls.jzgmnhsportal.com), prints 4-up card slips.
- **Admin** — manages school years, sections, subjects, weight profiles, teacher/head-teacher assignments, students, and user accounts.

## CSV export column order

`adviser/export_csv.php` exports LRN, Full Name, one column per subject's final/transmuted grade, then General Average. This hasn't been checked against PLS's actual import screen yet (PLS is login-gated) — adjust the column order in that file once verified.
