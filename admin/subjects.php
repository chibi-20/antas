<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $name = trim((string) ($_POST['subject_name'] ?? ''));
    $code = trim((string) ($_POST['subject_code'] ?? ''));
    $weightProfileId = (int) ($_POST['weight_profile_id'] ?? 0);
    $parentSubjectId = (int) ($_POST['parent_subject_id'] ?? 0) ?: null;
    $sortOrder = ($_POST['sort_order'] ?? '') !== '' ? (int) $_POST['sort_order'] : 100;
    $classRecordFormat = ($_POST['class_record_format'] ?? 'standard') === 'domains' ? 'domains' : 'standard';
    $err = ($name === '' || $code === '' || $weightProfileId === 0) ? 'All fields are required.' : null;

    if ($action === 'create') {
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('INSERT INTO subjects (subject_name, subject_code, weight_profile_id, parent_subject_id, sort_order, class_record_format) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $code, $weightProfileId, $parentSubjectId, $sortOrder, $classRecordFormat]);
                flash_set('success', "Subject \"$name\" created.");
            } catch (PDOException $e) {
                flash_set('error', 'Could not create subject — subject code may already be in use.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        if ($parentSubjectId === $id) {
            $err = 'A subject cannot be its own parent.';
        }
        if ($err) {
            flash_set('error', $err);
        } else {
            try {
                $pdo->prepare('UPDATE subjects SET subject_name=?, subject_code=?, weight_profile_id=?, parent_subject_id=?, sort_order=?, class_record_format=? WHERE id=?')
                    ->execute([$name, $code, $weightProfileId, $parentSubjectId, $sortOrder, $classRecordFormat, $id]);
                flash_set('success', 'Subject updated.');
            } catch (PDOException $e) {
                flash_set('error', 'Could not update subject — subject code may already be in use.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) $_POST['id'];
        $wasActiveStmt = $pdo->prepare('SELECT is_active FROM subjects WHERE id = ?');
        $wasActiveStmt->execute([$id]);
        $wasActive = (bool) $wasActiveStmt->fetchColumn();
        $pdo->prepare('UPDATE subjects SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        if ($wasActive) {
            // Deactivating a subject only ever changed the catalog entry — any assignment
            // already made against it (section_subject_teachers) stayed active regardless, so
            // it kept showing up everywhere (Class Record, Consolidated Grades, Card Slips) as
            // if nothing had changed. Cascading here is what actually makes "deactivate this
            // subject" mean what it looks like it means. Same retired_dedup_id-on-deactivate
            // convention admin/assignments.php's own toggle_active already uses, so a later
            // reactivation of the subject doesn't collide with the freed-up slot.
            $pdo->prepare("UPDATE section_subject_teachers SET is_active = 0, retired_dedup_id = id WHERE subject_id = ? AND is_active = 1")->execute([$id]);
        }
        flash_set('success', 'Status updated.');
    } elseif ($action === 'convert_terms') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $term = (int) ($_POST['term'] ?? 0);
        $year = active_school_year();
        $isDomainSubject = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE id = ? AND class_record_format = 'domains'");
        $isDomainSubject->execute([$subjectId]);
        if (!$year || $term < 1 || $term > 3 || !(int) $isDomainSubject->fetchColumn()) {
            flash_set('error', 'Pick a subject that uses the new class record, and a term.');
        } else {
            $classes = $pdo->prepare('SELECT id FROM section_subject_teachers WHERE subject_id = ? AND school_year_id = ? AND is_active = 1 AND (term_scope = 0 OR term_scope = ?)');
            $classes->execute([$subjectId, $year['id'], $term]);
            $tally = [];
            foreach ($classes->fetchAll(PDO::FETCH_COLUMN) as $sstId) {
                $result = convert_term_to_domains($pdo, (int) $sstId, $term);
                $tally[$result] = ($tally[$result] ?? 0) + 1;
            }
            $parts = [];
            foreach ($tally as $result => $n) {
                $parts[] = "$n " . ($n === 1 ? 'class' : 'classes') . ': ' . TERM_CONVERSION_LABELS[$result];
            }
            flash_set('success', "Term $term conversion finished.\n" . ($parts ? implode("\n", $parts) : 'No classes found for this subject.'));
        }
        redirect('/admin/subjects.php?convert_subject=' . $subjectId . '&convert_term=' . $term . '#convert-terms');
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        try {
            $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$id]);
            flash_set('success', 'Subject deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Could not delete — this subject has already been used somewhere (an assignment, a grade, a head teacher supervision, or as a MAPEH-style parent/component). Deactivate it instead.');
        }
    }
    redirect('/admin/subjects.php');
}

$weightProfiles = $pdo->query('SELECT * FROM grade_weight_profiles WHERE is_active = 1 ORDER BY profile_name')->fetchAll();
$subjects = $pdo->query('SELECT sub.*, wp.profile_name, parent.subject_name AS parent_name
    FROM subjects sub
    JOIN grade_weight_profiles wp ON wp.id = sub.weight_profile_id
    LEFT JOIN subjects parent ON parent.id = sub.parent_subject_id
    ORDER BY sub.sort_order, sub.subject_name')->fetchAll();
// Which subject ids are themselves a compound parent (have at least one child) — a subject
// that's part of one compound (e.g. Music-Arts) can't also host children of its own.
$parentIds = $pdo->query('SELECT DISTINCT parent_subject_id FROM subjects WHERE parent_subject_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// Eligible "parent subject" options: any other subject that doesn't itself already have a
// parent (no nesting) and isn't the subject being edited.
$parentOptions = array_values(array_filter($subjects, function ($s) use ($editing) {
    if ($s['parent_subject_id'] !== null) {
        return false;
    }
    return !$editing || (int) $s['id'] !== (int) $editing['id'];
}));

// "Switch an in-progress term" preview: every class of the chosen subject this school year and
// what converting that term would do to it, before anything is changed.
$domainSubjects = array_values(array_filter($subjects, fn($s) => $s['class_record_format'] === 'domains' && $s['is_active']));
$convertSubjectId = (int) ($_GET['convert_subject'] ?? ($domainSubjects[0]['id'] ?? 0));
$convertTerm = (int) ($_GET['convert_term'] ?? 0);
$convertPreview = [];
$activeYear = active_school_year();
if ($domainSubjects && $convertTerm >= 1 && $convertTerm <= 3 && $activeYear) {
    $stmt = $pdo->prepare('SELECT sst.id, gl.name AS grade_level, sec.section_name, u.full_name AS teacher_name
        FROM section_subject_teachers sst
        JOIN sections sec ON sec.id = sst.section_id
        JOIN grade_levels gl ON gl.id = sec.grade_level_id
        JOIN users u ON u.id = sst.teacher_id
        WHERE sst.subject_id = ? AND sst.school_year_id = ? AND sst.is_active = 1 AND (sst.term_scope = 0 OR sst.term_scope = ?)
        ORDER BY gl.sort_order, sec.section_name');
    $stmt->execute([$convertSubjectId, $activeYear['id'], $convertTerm]);
    foreach ($stmt->fetchAll() as $row) {
        $row['outcome'] = term_conversion_status($pdo, (int) $row['id'], $convertTerm);
        $convertPreview[] = $row;
    }
}
$willConvert = count(array_filter($convertPreview, fn($r) => $r['outcome'] === 'convertible'));

render_header('Subjects');
?>
<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm p-6 mb-6 max-w-lg">
  <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-4"><?= $editing ? 'Edit Subject' : 'Add Subject' ?></h2>
  <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">Subjects apply across every grade level — which grade a subject is actually taught in is set per section under Assignments. For a MAPEH-style compound subject (graded as separate components that merge into one report-card grade), create the merged subject first (e.g. "MAPEH"), then create each component (e.g. "Music-Arts", "PE-Health") with its Parent subject set to it — components are taught, encoded, and reviewed independently; the parent's grade is the average of its components once all are published, and its own weight profile is unused.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Subject name</label>
    <input type="text" name="subject_name" required value="<?= h($editing['subject_name'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Subject code</label>
    <input type="text" name="subject_code" required value="<?= h($editing['subject_code'] ?? '') ?>" class="w-full mb-4 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Sort order</label>
    <input type="number" name="sort_order" value="<?= h($editing['sort_order'] ?? 100) ?>" class="w-full mb-1 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
    <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">Controls display order on Consolidated Grades and Card Slips — lower numbers appear first. A compound component's own value is unused; it always displays right after its parent.</p>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Weight profile</label>
    <select name="weight_profile_id" required class="w-full mb-4 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
      <option value="">Select a weight profile…</option>
      <?= select_options($weightProfiles, 'id', 'profile_name', $editing['weight_profile_id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Parent subject (optional — for MAPEH-style components)</label>
    <select name="parent_subject_id" class="w-full mb-4 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
      <option value="">None — this is a regular subject</option>
      <?= select_options($parentOptions, 'id', 'subject_name', $editing['parent_subject_id'] ?? null) ?>
    </select>
    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Class record format</label>
    <?php $format = $editing['class_record_format'] ?? 'standard'; ?>
    <select name="class_record_format" class="w-full mb-1 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg">
      <option value="standard" <?= $format === 'standard' ? 'selected' : '' ?>>Standard (Written Work / Performance Task / Examinations)</option>
      <option value="domains" <?= $format === 'domains' ? 'selected' : '' ?>>Values Education (Cognitive / Affective / Behavioral domains)</option>
    </select>
    <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">Values Education format follows the official VE/GMRC e-class record: Written Work = Cognitive (5) + Affective (5); Performance Tasks = Cognitive (3) + Affective (3) + Behavioral (3); Examinations unchanged. Applies only to terms a teacher hasn't started yet — terms already started or published keep their current record and grades.</p>
    <div class="flex gap-2">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm"><?= $editing ? 'Save Changes' : 'Add' ?></button>
      <?php if ($editing): ?><a href="<?= h(url('/admin/subjects.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<?php if ($domainSubjects): ?>
<div id="convert-terms" class="bg-white dark:bg-slate-800 border border-amber-200 dark:border-amber-800 rounded-xl shadow-sm p-6 mb-6">
  <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-1">Switch an in-progress term to the new class record</h2>
  <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">For classes that started a term on the standard record before the switch. Converting starts that term's Written Work and Performance Task over in the new Cognitive / Affective / Behavioral layout — their scores are cleared — while exam scores are kept. Submitted and published terms (including any ever reopened for an edit) are never touched. Teachers can also do this for their own class from its class record.</p>
  <form method="get" class="flex flex-wrap items-end gap-3 mb-4">
    <div>
      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Subject</label>
      <select name="convert_subject" class="px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">
        <?= select_options($domainSubjects, 'id', 'subject_name', $convertSubjectId) ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Term</label>
      <select name="convert_term" class="px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">
        <?php for ($t = 1; $t <= 3; $t++): ?><option value="<?= $t ?>" <?= $t === $convertTerm ? 'selected' : '' ?>>Term <?= $t ?></option><?php endfor; ?>
      </select>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg text-sm bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700">Preview</button>
  </form>

  <?php if ($convertTerm): ?>
    <?php if (!$convertPreview): ?>
    <p class="text-sm text-slate-400 dark:text-slate-500">No classes for this subject in the active school year.</p>
    <?php else: ?>
    <div class="overflow-x-auto border border-slate-100 dark:border-slate-700 rounded-lg mb-4">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
          <tr><th class="text-left px-4 py-2">Class</th><th class="text-left px-4 py-2">Teacher</th><th class="text-left px-4 py-2">Term <?= $convertTerm ?> — what will happen</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
          <?php foreach ($convertPreview as $row): ?>
          <tr>
            <td class="px-4 py-2 font-medium whitespace-nowrap"><?= h($row['grade_level'] . ' - ' . $row['section_name']) ?></td>
            <td class="px-4 py-2 text-slate-600 dark:text-slate-300"><?= h($row['teacher_name']) ?></td>
            <td class="px-4 py-2 <?= $row['outcome'] === 'convertible' ? 'text-amber-700 dark:text-amber-300 font-medium' : 'text-slate-500 dark:text-slate-400' ?>"><?= h(TERM_CONVERSION_LABELS[$row['outcome']]) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($willConvert): ?>
    <form method="post" data-confirm="Switch Term <?= $convertTerm ?> to the new class record for <?= $willConvert ?> class<?= $willConvert === 1 ? '' : 'es' ?>?&#10;&#10;Their Written Work and Performance Task columns and scores for this term will be removed and replaced with the new layout. Exam scores are kept. This cannot be undone.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="convert_terms">
      <input type="hidden" name="subject_id" value="<?= $convertSubjectId ?>">
      <input type="hidden" name="term" value="<?= $convertTerm ?>">
      <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Switch <?= $willConvert ?> class<?= $willConvert === 1 ? '' : 'es' ?> to the new class record</button>
    </form>
    <?php else: ?>
    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing to switch for this term.</p>
    <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 text-xs uppercase">
      <tr><th class="text-left px-4 py-3">Subject</th><th class="text-left px-4 py-3">Code</th><th class="text-left px-4 py-3">Order</th><th class="text-left px-4 py-3">Weight Profile</th><th class="text-left px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
      <?php foreach ($subjects as $s): ?>
      <tr>
        <td class="px-4 py-3 font-medium">
          <?= h($s['subject_name']) ?>
          <?php if (in_array($s['id'], $parentIds)): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 ml-1">Compound</span>
          <?php elseif ($s['parent_name']): ?>
            <span class="text-xs text-slate-400 dark:text-slate-500 block">Part of <?= h($s['parent_name']) ?></span>
          <?php endif; ?>
          <?php if ($s['class_record_format'] === 'domains'): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 ml-1">Domain class record</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= h($s['subject_code']) ?></td>
        <td class="px-4 py-3 text-slate-500 dark:text-slate-400"><?= $s['parent_subject_id'] ? '—' : (int) $s['sort_order'] ?></td>
        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= h($s['profile_name']) ?></td>
        <td class="px-4 py-3"><?= $s['is_active'] ? '<span class="text-emerald-600 dark:text-emerald-400">Active</span>' : '<span class="text-slate-400 dark:text-slate-500">Inactive</span>' ?></td>
        <td class="px-4 py-3 text-right space-x-2">
          <a href="<?= h(url('/admin/subjects.php?edit=' . $s['id'])) ?>" class="text-accent-600 dark:text-accent-400 hover:underline">Edit</a>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="text-slate-500 dark:text-slate-400 hover:underline"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <form method="post" class="inline" data-confirm="Delete this subject permanently? Only works if it's never been assigned, graded, or supervised — otherwise deactivate it instead.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="text-rose-500 dark:text-rose-400 hover:underline">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
