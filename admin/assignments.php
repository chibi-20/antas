<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Only the actual-assignment actions use section_id/term_scope/sex_scope — the
    // eligibility-tagging actions below have their own, smaller field set.
    if ($action === 'create' || $action === 'update') {
        $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $termScope = (int) ($_POST['term_scope'] ?? 0);
        $sexScope = $_POST['sex_scope'] ?? 'ALL';
        $err = (!$schoolYearId || !$sectionId || !$subjectId || !$teacherId) ? 'All fields are required.' : null;
        if (!$err && ($termScope < 0 || $termScope > 3)) {
            $err = 'Invalid term.';
        }
        if (!$err && !in_array($sexScope, ['ALL', 'M', 'F'], true)) {
            $err = 'Invalid "Applies To" value.';
        }
    }

    if ($action === 'create') {
        if (!$err) {
            $err = sst_scope_conflict($pdo, $sectionId, $subjectId, $schoolYearId, $termScope, $sexScope);
        }
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO section_subject_teachers (section_id, subject_id, teacher_id, school_year_id, term_scope, sex_scope) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$sectionId, $subjectId, $teacherId, $schoolYearId, $termScope, $sexScope]);
                $sstId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO submission_status (section_subject_teacher_id, term, status) VALUES (?, ?, ?)');
                for ($t = 1; $t <= 3; $t++) {
                    $stmt->execute([$sstId, $t, 'not_started']);
                }
                $pdo->commit();
                flash_set('success', 'Assignment created — submission tracking initialized for all 3 terms.');
            } catch (PDOException $e) {
                $pdo->rollBack();
                flash_set('error', 'Could not create assignment — this section/subject/school year combination may already exist.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if (!$err) {
            $s = $pdo->prepare('SELECT * FROM section_subject_teachers WHERE id = ?');
            $s->execute([$id]);
            $oldRow = $s->fetch();
            if (!$oldRow) {
                $err = 'Assignment not found.';
            } else {
                $err = sst_narrowing_blocked($pdo, $oldRow, $termScope, $sexScope)
                    ?? sst_scope_conflict($pdo, $sectionId, $subjectId, $schoolYearId, $termScope, $sexScope, $id);
            }
        }
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE section_subject_teachers SET section_id=?, subject_id=?, teacher_id=?, school_year_id=?, term_scope=?, sex_scope=? WHERE id=?')
                    ->execute([$sectionId, $subjectId, $teacherId, $schoolYearId, $termScope, $sexScope, $id]);
                flash_set('success', 'Assignment updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update assignment — this section/subject/school year/term/scope combination may already exist.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE section_subject_teachers SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    } elseif ($action === 'eligibility_create') {
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $gradeLevelId = (int) ($_POST['grade_level_id'] ?? 0);
        $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
        $err = (!$teacherId || !$subjectId || !$gradeLevelId || !$schoolYearId) ? 'All fields are required.' : null;
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO claim_eligibility (teacher_id, subject_id, grade_level_id, school_year_id) VALUES (?, ?, ?, ?)')
                    ->execute([$teacherId, $subjectId, $gradeLevelId, $schoolYearId]);
                flash_set('success', 'Teacher tagged as eligible to self-claim — they can now pick their own sections from their dashboard.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not save — this teacher/subject/grade level/school year combination may already exist.');
            }
        }
    } elseif ($action === 'eligibility_toggle_active') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE claim_eligibility SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Status updated.');
    }
    redirect('/admin/assignments.php');
}

$schoolYears = $pdo->query('SELECT * FROM school_years ORDER BY year_label DESC')->fetchAll();
$sections = $pdo->query('SELECT sec.*, gl.name AS grade_level, gl.sort_order, sy.year_label FROM sections sec
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN school_years sy ON sy.id = sec.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name')->fetchAll();
// Compound parent subjects (e.g. MAPEH) are never directly assigned to a teacher — only
// their components (e.g. Music-Arts, PE-Health) are. See admin/subjects.php.
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1
    AND id NOT IN (SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL)
    ORDER BY subject_name")->fetchAll();
$teachers = $pdo->query("SELECT * FROM users WHERE role = 'subject_teacher' AND is_active = 1 ORDER BY full_name")->fetchAll();
$gradeLevels = $pdo->query('SELECT * FROM grade_levels WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$sectionLabels = array_map(fn($s) => ['id' => $s['id'], 'label' => $s['grade_level'] . ' - ' . $s['section_name'] . ' (' . $s['year_label'] . ')'], $sections);

$assignments = $pdo->query('SELECT sst.*, gl.name AS grade_level, sec.section_name, sub.subject_name, u.full_name AS teacher_name, sy.year_label
    FROM section_subject_teachers sst
    JOIN sections sec ON sec.id = sst.section_id
    JOIN grade_levels gl ON gl.id = sec.grade_level_id
    JOIN subjects sub ON sub.id = sst.subject_id
    JOIN users u ON u.id = sst.teacher_id
    JOIN school_years sy ON sy.id = sst.school_year_id
    ORDER BY sy.year_label DESC, gl.sort_order, sec.section_name, sub.subject_name')->fetchAll();

$eligibility = $pdo->query('SELECT ce.*, u.full_name AS teacher_name, sub.subject_name, gl.name AS grade_level, sy.year_label
    FROM claim_eligibility ce
    JOIN users u ON u.id = ce.teacher_id
    JOIN subjects sub ON sub.id = ce.subject_id
    JOIN grade_levels gl ON gl.id = ce.grade_level_id
    JOIN school_years sy ON sy.id = ce.school_year_id
    ORDER BY sy.year_label DESC, u.full_name, gl.sort_order, sub.subject_name')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM section_subject_teachers WHERE id = ?');
    $s->execute([(int) $_GET['edit']]);
    $editing = $s->fetch() ?: null;
}

// Card view: one card per teacher (avatar, subject/section counts) instead of one long flat
// table — clicking a card drills into just that teacher's assignments, which is where the
// full Year/Section/Subject/Status/actions table (unchanged) actually renders.
$teacherSummaries = [];
foreach ($assignments as $a) {
    $tid = (int) $a['teacher_id'];
    if (!isset($teacherSummaries[$tid])) {
        $teacherSummaries[$tid] = [
            'teacher_id' => $tid,
            'teacher_name' => $a['teacher_name'],
            'total' => 0,
            'active' => 0,
            'subjects' => [],
            'sections' => [],
        ];
    }
    $teacherSummaries[$tid]['total']++;
    if ($a['is_active']) {
        $teacherSummaries[$tid]['active']++;
    }
    $teacherSummaries[$tid]['subjects'][$a['subject_name']] = true;
    $teacherSummaries[$tid]['sections'][$a['grade_level'] . ' - ' . $a['section_name']] = true;
}
// A teacher who's been tagged eligible but hasn't self-claimed anything yet has zero rows
// in $assignments — without this, they'd never get a card and admin could never drill in
// to see/manage their eligibility tags.
foreach ($eligibility as $e) {
    $tid = (int) $e['teacher_id'];
    if (!isset($teacherSummaries[$tid])) {
        $teacherSummaries[$tid] = [
            'teacher_id' => $tid,
            'teacher_name' => $e['teacher_name'],
            'total' => 0,
            'active' => 0,
            'subjects' => [],
            'sections' => [],
        ];
    }
}
usort($teacherSummaries, fn($a, $b) => strcmp($a['teacher_name'], $b['teacher_name']));

$viewTeacherId = (int) ($_GET['teacher_id'] ?? 0);
$viewTeacherAssignments = $viewTeacherId ? array_values(array_filter($assignments, fn($a) => (int) $a['teacher_id'] === $viewTeacherId)) : [];
$viewTeacherEligibility = $viewTeacherId ? array_values(array_filter($eligibility, fn($e) => (int) $e['teacher_id'] === $viewTeacherId)) : [];
$viewTeacherName = null;
if ($viewTeacherId) {
    // Not sourced from $viewTeacherAssignments[0] — that's undefined for an eligibility-only
    // teacher with zero assignments, and $teacherSummaries' keys aren't reliable here since
    // usort() above reindexed them.
    $tn = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $tn->execute([$viewTeacherId]);
    $viewTeacherName = $tn->fetchColumn() ?: null;
}

render_header('Subject Assignments');
?>
<a href="<?= h(url('/admin/bulk_assign.php')) ?>" class="flex items-center justify-between gap-4 bg-accent-600 hover:bg-accent-700 text-white rounded-xl shadow-sm p-6 mb-6 transition-colors">
  <div>
    <div class="font-semibold text-lg mb-1">Bulk Assign</div>
    <div class="text-sm text-accent-100">Assign one teacher to many sections at once — the fastest way to set up assignments. Recommended over adding them one at a time.</div>
  </div>
  <span class="flex-shrink-0"><?= icon_svg('arrow-right', 'w-6 h-6') ?></span>
</a>

<details class="mb-6" <?= $editing ? 'open' : '' ?>>
  <summary class="cursor-pointer select-none text-sm text-slate-500 hover:text-slate-700 mb-2"><?= $editing ? 'Edit Assignment' : 'Or add a single assignment manually' ?></summary>
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-lg mt-2">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
      <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($schoolYears, 'id', 'year_label', $editing['school_year_id'] ?? (active_school_year()['id'] ?? null)) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">Section</label>
      <select name="section_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($sectionLabels, 'id', 'label', $editing['section_id'] ?? null) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">Subject</label>
      <select name="subject_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($subjects, 'id', 'subject_name', $editing['subject_id'] ?? null) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">Teacher</label>
      <select name="teacher_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
        <?= select_options($teachers, 'id', 'full_name', $editing['teacher_id'] ?? null) ?>
      </select>
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Term</label>
          <?php $curTermScope = (int) ($editing['term_scope'] ?? 0); ?>
          <select name="term_scope" class="w-full px-3 py-2 border border-slate-300 rounded-lg">
            <option value="0" <?= $curTermScope === 0 ? 'selected' : '' ?>>All Terms (default)</option>
            <option value="1" <?= $curTermScope === 1 ? 'selected' : '' ?>>Term 1</option>
            <option value="2" <?= $curTermScope === 2 ? 'selected' : '' ?>>Term 2</option>
            <option value="3" <?= $curTermScope === 3 ? 'selected' : '' ?>>Term 3</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Applies To</label>
          <?php $curSexScope = $editing['sex_scope'] ?? 'ALL'; ?>
          <select name="sex_scope" class="w-full px-3 py-2 border border-slate-300 rounded-lg">
            <option value="ALL" <?= $curSexScope === 'ALL' ? 'selected' : '' ?>>All Students</option>
            <option value="M" <?= $curSexScope === 'M' ? 'selected' : '' ?>>Male Only</option>
            <option value="F" <?= $curSexScope === 'F' ? 'selected' : '' ?>>Female Only</option>
          </select>
        </div>
      </div>
      <p class="text-xs text-slate-400 mb-4">Only needed when a subject changes teachers mid-year or is split by sex (e.g. TLE) — leave both at their defaults otherwise.</p>
      <div class="flex gap-2">
        <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
        <?php if ($editing): ?><a href="<?= h(url('/admin/assignments.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</details>

<details class="mb-6">
  <summary class="cursor-pointer select-none text-sm text-slate-500 hover:text-slate-700 mb-2">Or mark a teacher as eligible to self-claim classes</summary>
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 max-w-lg mt-2">
    <p class="text-xs text-slate-400 mb-4">Lets a teacher pick their own sections/terms for this subject and grade level from their own dashboard, instead of you creating each assignment row yourself — useful for a subject like TLE that has many teachers split by term or by sex. This does not create an assignment by itself.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="eligibility_create">
      <label class="block text-sm font-medium text-slate-600 mb-1">Teacher</label>
      <select name="teacher_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg js-searchable" data-placeholder="Search teachers…">
        <?= select_options($teachers, 'id', 'full_name', null) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">Subject</label>
      <select name="subject_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($subjects, 'id', 'subject_name', null) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">Grade Level</label>
      <select name="grade_level_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($gradeLevels, 'id', 'name', null) ?>
      </select>
      <label class="block text-sm font-medium text-slate-600 mb-1">School year</label>
      <select name="school_year_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
        <?= select_options($schoolYears, 'id', 'year_label', active_school_year()['id'] ?? null) ?>
      </select>
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Add Eligibility</button>
    </form>
  </div>
</details>

<?php if ($viewTeacherId && ($viewTeacherAssignments || $viewTeacherEligibility)): ?>
<a href="<?= h(url('/admin/assignments.php')) ?>" class="inline-block mb-4 text-sm text-accent-600 hover:underline">&larr; Back to Teachers</a>
<h2 class="text-sm font-semibold text-slate-600 mb-3"><?= h($viewTeacherName ?? '') ?>'s Assignments</h2>

<?php if ($viewTeacherEligibility): ?>
<div class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($viewTeacherEligibility as $e): ?>
  <span class="inline-flex items-center gap-2 bg-slate-100 border border-slate-200 rounded-full pl-3 pr-1 py-1 text-xs">
    <span class="<?= $e['is_active'] ? 'text-slate-700' : 'text-slate-400 line-through' ?>">Eligible: <?= h($e['subject_name'] . ' · ' . $e['grade_level'] . ' · ' . $e['year_label']) ?></span>
    <form method="post" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="eligibility_toggle_active">
      <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
      <button type="submit" class="text-slate-500 hover:text-slate-700 px-1.5 py-0.5 rounded-full hover:bg-slate-200 text-[10px] uppercase font-medium"><?= $e['is_active'] ? 'Deactivate' : 'Activate' ?></button>
    </form>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($viewTeacherAssignments): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Year</th><th class="text-left px-4 py-3">Section</th><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Term</th><th class="text-left px-4 py-3">Applies To</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($viewTeacherAssignments as $a): ?>
      <tr>
        <td class="px-4 py-3 text-slate-500"><?= h($a['year_label']) ?></td>
        <td class="px-4 py-3 text-slate-600"><?= h($a['grade_level'] . ' - ' . $a['section_name']) ?></td>
        <td class="px-4 py-3 font-medium"><?= h($a['subject_name']) ?></td>
        <td class="px-4 py-3 text-slate-500"><?= (int) $a['term_scope'] === 0 ? 'All Terms' : 'Term ' . (int) $a['term_scope'] ?></td>
        <td class="px-4 py-3 text-slate-500"><?= $a['sex_scope'] === 'ALL' ? 'All Students' : ($a['sex_scope'] === 'M' ? 'Male Only' : 'Female Only') ?></td>
        <td class="px-4 py-3"><?= $a['is_active'] ? '<span class="text-emerald-600">Active</span>' : '<span class="text-slate-400">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/assignments.php?edit=' . $a['id'])) ?>" class="text-accent-600 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="text-slate-500 hover:underline"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-8 text-center text-slate-400 text-sm">No assignments yet — only eligibility tags.</div>
<?php endif; ?>
<?php else: ?>
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($teacherSummaries as $t): ?>
  <a href="<?= h(url('/admin/assignments.php?teacher_id=' . $t['teacher_id'])) ?>"
     class="block bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:border-accent-300 hover:shadow-md transition-shadow">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-11 h-11 rounded-full <?= avatar_color($t['teacher_id']) ?> text-white flex items-center justify-center text-sm font-semibold flex-shrink-0"><?= h(avatar_initials($t['teacher_name'])) ?></div>
      <div class="min-w-0">
        <div class="font-semibold text-slate-800 truncate"><?= h($t['teacher_name']) ?></div>
        <div class="text-xs text-slate-500"><?= count($t['subjects']) ?> subject<?= count($t['subjects']) === 1 ? '' : 's' ?> · <?= count($t['sections']) ?> section<?= count($t['sections']) === 1 ? '' : 's' ?></div>
      </div>
    </div>
    <div class="flex items-center justify-between">
      <div class="text-xs">
        <span class="font-semibold text-slate-700"><?= $t['active'] ?></span> <span class="text-slate-500">active</span>
        <?php if ($t['total'] > $t['active']): ?><span class="text-slate-400"> · <?= $t['total'] - $t['active'] ?> inactive</span><?php endif; ?>
      </div>
      <span class="text-accent-600"><?= icon_svg('arrow-right', 'w-4 h-4') ?></span>
    </div>
  </a>
  <?php endforeach; ?>
  <?php if (!$teacherSummaries): ?>
  <div class="text-slate-400 text-sm">No assignments yet.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
