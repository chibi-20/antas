<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_role(['subject_teacher']);
$pdo = db();
$year = require_active_school_year();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $termScope = (int) ($_POST['term_scope'] ?? 0);
    $sexScope = $_POST['sex_scope'] ?? 'ALL';
    if ($termScope === 0) {
        // Whole-year claims are always all-students — ignore any posted sex_scope rather
        // than trust a hidden field that shouldn't exist for this action in the first place.
        $sexScope = 'ALL';
    }

    $err = (!$sectionId || !$subjectId) ? 'Missing section or subject.' : null;
    if (!$err && ($termScope < 0 || $termScope > 3)) {
        $err = 'Invalid term.';
    }
    if (!$err && !in_array($sexScope, ['ALL', 'M', 'F'], true)) {
        $err = 'Invalid "Applies To" value.';
    }

    if (!$err) {
        // The grade level this claim covers is resolved from the section server-side —
        // never trusted from a posted field, since section_id/subject_id are ordinary POST
        // values a client could tamper with to claim outside their tagged grade level.
        $secStmt = $pdo->prepare('SELECT grade_level_id FROM sections WHERE id = ? AND school_year_id = ? AND is_active = 1');
        $secStmt->execute([$sectionId, $year['id']]);
        $gradeLevelId = $secStmt->fetchColumn();
        if (!$gradeLevelId) {
            forbidden('Section not found.');
        }
        assert_claim_eligible((int) $user['id'], $subjectId, (int) $gradeLevelId, (int) $year['id']);
    }

    if ($err) {
        flash_set('error', $err);
    } else {
        // claim_availability() (used to render this page) is read-then-decide, not atomic
        // with the insert below — two teachers claiming different, non-conflicting-by-key
        // slots on the same still-empty (section, subject, year) at the same instant could
        // otherwise both pass sst_scope_conflict() and both insert, corrupting
        // effective_term_grades. A named lock scoped to that tuple serializes concurrent
        // claims; admin/assignments.php doesn't need this since it's single-admin, sequential
        // entry, but self-claim makes concurrent hits on the same slot normal usage.
        $lockKey = "claim:$sectionId:$subjectId:{$year['id']}";
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$lockKey]);
        try {
            $pdo->beginTransaction();
            $conflict = sst_scope_conflict($pdo, $sectionId, $subjectId, (int) $year['id'], $termScope, $sexScope);
            if ($conflict) {
                throw new RuntimeException($conflict);
            }
            $pdo->prepare('INSERT INTO section_subject_teachers (section_id, subject_id, teacher_id, school_year_id, term_scope, sex_scope) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$sectionId, $subjectId, $user['id'], $year['id'], $termScope, $sexScope]);
            $sstId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO submission_status (section_subject_teacher_id, term, status) VALUES (?, ?, ?)');
            for ($t = 1; $t <= 3; $t++) {
                $stmt->execute([$sstId, $t, 'not_started']);
            }
            $pdo->commit();
            flash_set('success', 'Class claimed — submission tracking initialized for all 3 terms.');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash_set('error', $e->getMessage());
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash_set('error', 'This slot was just claimed by someone else — please refresh and try again.');
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
        }
    }
    redirect('/teacher/claim.php');
}

$groups = get_claimable_sections((int) $user['id'], (int) $year['id']);

render_header('Claim a Class', 'Pick a section, term, and student group to make it yours.');
?>
<?php if (!$groups): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-10 text-center text-slate-400 text-sm">
  You haven't been tagged as eligible to self-claim any classes yet — ask an admin to set this up.
</div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<div class="mb-8">
  <h2 class="text-sm font-semibold text-slate-700 mb-1"><?= h($group['subject_name']) ?></h2>
  <p class="text-xs text-slate-400 mb-3"><?= h($group['grade_level']) ?></p>

  <?php if (!$group['sections']): ?>
  <div class="text-slate-400 text-sm mb-4">No active sections in this grade level yet.</div>
  <?php endif; ?>

  <div class="grid gap-3">
    <?php foreach ($group['sections'] as $sec): $avail = $sec['availability']; ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
      <div class="font-medium text-slate-800 mb-3"><?= h($sec['section_name']) ?></div>

      <?php if (!$avail['whole_year_open'] && !$avail['terms']): ?>
      <div class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500">Taken all year by <?= h($avail['whole_year_taken_by']) ?></div>

      <?php elseif ($avail['whole_year_open']): ?>
      <form method="post" class="flex items-center gap-3 mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="section_id" value="<?= $sec['section_id'] ?>">
        <input type="hidden" name="subject_id" value="<?= $group['subject_id'] ?>">
        <input type="hidden" name="term_scope" value="0">
        <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium px-3 py-1.5 rounded-lg">Claim the whole year</button>
        <span class="text-xs text-slate-400">or claim specific terms only, below</span>
      </form>
      <div class="grid grid-cols-3 gap-2">
        <?php for ($t = 1; $t <= 3; $t++): ?>
        <form method="post" class="flex flex-col gap-1.5 bg-slate-50 rounded-lg p-2.5">
          <?= csrf_field() ?>
          <input type="hidden" name="section_id" value="<?= $sec['section_id'] ?>">
          <input type="hidden" name="subject_id" value="<?= $group['subject_id'] ?>">
          <input type="hidden" name="term_scope" value="<?= $t ?>">
          <span class="text-xs font-medium text-slate-500">Term <?= $t ?></span>
          <select name="sex_scope" class="text-xs border border-slate-300 rounded px-1.5 py-1">
            <option value="ALL">All Students</option>
            <option value="M">Male Only</option>
            <option value="F">Female Only</option>
          </select>
          <button type="submit" class="text-xs bg-white border border-accent-200 text-accent-700 hover:bg-accent-50 rounded px-2 py-1">Claim</button>
        </form>
        <?php endfor; ?>
      </div>

      <?php else: ?>
      <div class="grid grid-cols-3 gap-2">
        <?php for ($t = 1; $t <= 3; $t++): $termAvail = $avail['terms'][$t]; ?>
        <div class="bg-slate-50 rounded-lg p-2.5">
          <div class="text-xs font-medium text-slate-500 mb-1.5">Term <?= $t ?></div>
          <?php if (!$termAvail['open']): ?>
            <div class="text-[11px] text-slate-400">
              <?php if (isset($termAvail['taken']['ALL'])): ?>
                Taken by <?= h($termAvail['taken']['ALL']) ?>
              <?php else: ?>
                <?php foreach ($termAvail['taken'] as $sex => $name): ?>
                  <?= $sex === 'M' ? 'Male' : 'Female' ?>: <?= h($name) ?><br>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php elseif (count($termAvail['open']) === 3): ?>
            <form method="post" class="flex flex-col gap-1.5">
              <?= csrf_field() ?>
              <input type="hidden" name="section_id" value="<?= $sec['section_id'] ?>">
              <input type="hidden" name="subject_id" value="<?= $group['subject_id'] ?>">
              <input type="hidden" name="term_scope" value="<?= $t ?>">
              <select name="sex_scope" class="text-xs border border-slate-300 rounded px-1.5 py-1">
                <option value="ALL">All Students</option>
                <option value="M">Male Only</option>
                <option value="F">Female Only</option>
              </select>
              <button type="submit" class="text-xs bg-white border border-accent-200 text-accent-700 hover:bg-accent-50 rounded px-2 py-1">Claim</button>
            </form>
          <?php else: $remaining = $termAvail['open'][0]; $takenSex = $remaining === 'M' ? 'F' : 'M'; ?>
            <div class="text-[11px] text-slate-400 mb-1.5"><?= $takenSex === 'M' ? 'Male' : 'Female' ?> half taken by <?= h($termAvail['taken'][$takenSex]) ?></div>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="section_id" value="<?= $sec['section_id'] ?>">
              <input type="hidden" name="subject_id" value="<?= $group['subject_id'] ?>">
              <input type="hidden" name="term_scope" value="<?= $t ?>">
              <input type="hidden" name="sex_scope" value="<?= $remaining ?>">
              <button type="submit" class="text-xs bg-white border border-accent-200 text-accent-700 hover:bg-accent-50 rounded px-2 py-1 w-full">Claim <?= $remaining === 'M' ? 'Male' : 'Female' ?> half</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
