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
    if ($termScope === 0 && $sexScope !== 'MIX') {
        // Whole-year non-Mix claims are always all-students — ignore any posted sex_scope
        // rather than trust a hidden field that shouldn't exist for this action in the first
        // place. (A whole-year Mix claim is legitimate — term_scope=0 doesn't imply ALL.)
        $sexScope = 'ALL';
    }
    $studentIds = array_values(array_unique(array_map('intval', $_POST['student_ids'] ?? [])));

    $err = (!$sectionId || !$subjectId) ? 'Missing section or subject.' : null;
    if (!$err && ($termScope < 0 || $termScope > 3)) {
        $err = 'Invalid term.';
    }
    if (!$err && !in_array($sexScope, ['ALL', 'M', 'F', 'MIX'], true)) {
        $err = 'Invalid "Applies To" value.';
    }
    if (!$err && $sexScope === 'MIX' && !$studentIds) {
        $err = 'Pick at least one student for a mixed selection.';
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

        if ($sexScope === 'MIX' && $studentIds) {
            // A disabled checkbox is omitted by a real browser, but a crafted request isn't —
            // every posted id must genuinely belong to this section and be active. Rejecting
            // a foreign/inactive id here is a separate check from sst_scope_conflict() below
            // (which only catches an id that's already covered by someone else); neither
            // subsumes the other.
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $validStmt = $pdo->prepare("SELECT id FROM students WHERE section_id = ? AND is_active = 1 AND id IN ($placeholders)");
            $validStmt->execute(array_merge([$sectionId], $studentIds));
            $validIds = $validStmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($validIds) !== count($studentIds)) {
                $err = 'One or more selected students are no longer valid — please try again.';
            }
        }
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
            $conflict = sst_scope_conflict($pdo, $sectionId, $subjectId, (int) $year['id'], $termScope, $sexScope, null, $studentIds);
            if ($conflict) {
                throw new RuntimeException($conflict);
            }
            $pdo->prepare('INSERT INTO section_subject_teachers (section_id, subject_id, teacher_id, school_year_id, term_scope, sex_scope) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$sectionId, $subjectId, $user['id'], $year['id'], $termScope, $sexScope]);
            $sstId = (int) $pdo->lastInsertId();
            if ($sexScope === 'MIX') {
                $claimStmt = $pdo->prepare('INSERT INTO sst_student_claims (section_subject_teacher_id, student_id) VALUES (?, ?)');
                foreach ($studentIds as $sid) {
                    $claimStmt->execute([$sstId, $sid]);
                }
            }
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

/**
 * Renders one term-mode panel (whole-year, or one specific term) for a section card: the
 * quick All/Male/Female one-click buttons (gated by $bucket['quick_open']) plus a "Pick
 * Specific Students…" button opening this panel's pre-rendered modal when anyone is still
 * uncovered. $termScope is 0 for the whole-year panel.
 */
function render_claim_panel(array $bucket, int $sectionId, int $subjectId, int $termScope, array $roster, string $domId): string
{
    ob_start();
    ?>
    <div class="flex flex-wrap items-center gap-2">
      <?php foreach (['ALL' => 'All Students', 'M' => 'Male Only', 'F' => 'Female Only'] as $val => $label): ?>
        <?php if (in_array($val, $bucket['quick_open'], true)): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="section_id" value="<?= $sectionId ?>">
          <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
          <input type="hidden" name="term_scope" value="<?= $termScope ?>">
          <input type="hidden" name="sex_scope" value="<?= $val ?>">
          <button type="submit" class="text-xs bg-white border border-accent-200 text-accent-700 hover:bg-accent-50 rounded px-2 py-1"><?= h($label) ?></button>
        </form>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($bucket['mix_available']): ?>
      <button type="button" data-mix-modal-target="<?= h($domId) ?>" class="text-xs bg-slate-800 hover:bg-slate-900 text-white rounded px-2 py-1">Pick Specific Students…</button>
      <?php endif; ?>
      <?php if (!$bucket['quick_open'] && !$bucket['mix_available']): ?>
      <span class="text-[11px] text-slate-400">Fully covered</span>
      <?php endif; ?>
    </div>
    <?php if ($bucket['covered_by']): ?>
    <div class="text-[11px] text-slate-400 mt-1.5">
      <?php $names = array_unique(array_values($bucket['covered_by'])); ?>
      Already covered by <?= h(implode(', ', $names)) ?>
    </div>
    <?php endif; ?>

    <?php if ($bucket['mix_available']): ?>
    <div id="<?= h($domId) ?>" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/50 p-4" data-mix-modal>
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full max-h-[85vh] overflow-y-auto">
        <form method="post" class="p-5">
          <?= csrf_field() ?>
          <input type="hidden" name="section_id" value="<?= $sectionId ?>">
          <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
          <input type="hidden" name="term_scope" value="<?= $termScope ?>">
          <input type="hidden" name="sex_scope" value="MIX">
          <h3 class="text-sm font-semibold text-slate-700 mb-3">Pick your students</h3>
          <div class="border border-slate-200 rounded-lg p-2">
            <?= render_student_picker($roster, $bucket['covered_by'], [], 'student_ids') ?>
          </div>
          <div class="flex gap-2 mt-4">
            <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white text-sm font-medium px-4 py-2 rounded-lg">Claim Selected</button>
            <button type="button" data-mix-modal-close class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

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
    <?php
        $rosterStmt = $pdo->prepare("SELECT id, sex, full_name FROM students WHERE section_id = ? AND is_active = 1 ORDER BY FIELD(sex, 'M', 'F'), full_name");
        $rosterStmt->execute([$sec['section_id']]);
        $roster = $rosterStmt->fetchAll();
        $modalPrefix = 'mix-' . $sec['section_id'] . '-' . $group['subject_id'];
    ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
      <div class="font-medium text-slate-800 mb-3"><?= h($sec['section_name']) ?></div>

      <?php if ($avail['mode'] === 'whole_year'): ?>
        <?= render_claim_panel($avail['whole_year'], $sec['section_id'], $group['subject_id'], 0, $roster, $modalPrefix . '-year') ?>

      <?php elseif ($avail['mode'] === 'fresh'): ?>
        <div class="flex gap-1 mb-3" data-claim-mode-toggle="<?= h($modalPrefix) ?>">
          <button type="button" data-claim-mode-btn="<?= h($modalPrefix) ?>-year" class="px-3 py-1.5 rounded-lg text-xs bg-accent-600 text-white">Whole School Year</button>
          <button type="button" data-claim-mode-btn="<?= h($modalPrefix) ?>-term" class="px-3 py-1.5 rounded-lg text-xs bg-white border border-slate-200 text-slate-600">By Term</button>
        </div>
        <div data-claim-mode-panel="<?= h($modalPrefix) ?>-year">
          <?= render_claim_panel($avail['whole_year'], $sec['section_id'], $group['subject_id'], 0, $roster, $modalPrefix . '-year') ?>
        </div>
        <div data-claim-mode-panel="<?= h($modalPrefix) ?>-term" class="hidden grid grid-cols-1 gap-2">
          <?php for ($t = 1; $t <= 3; $t++): ?>
          <div class="bg-slate-50 rounded-lg p-2.5">
            <div class="text-xs font-medium text-slate-500 mb-1.5">Term <?= $t ?></div>
            <?= render_claim_panel($avail['terms'][$t], $sec['section_id'], $group['subject_id'], $t, $roster, $modalPrefix . '-t' . $t) ?>
          </div>
          <?php endfor; ?>
        </div>

      <?php else: /* per_term */ ?>
        <div class="grid grid-cols-1 gap-2">
          <?php for ($t = 1; $t <= 3; $t++): $bucket = $avail['terms'][$t]; ?>
            <?php if (!$bucket['mix_available'] && !$bucket['quick_open']): ?>
            <div class="bg-slate-50 rounded-lg p-2.5">
              <div class="text-xs font-medium text-slate-500 mb-1">Term <?= $t ?></div>
              <span class="text-[11px] text-slate-400">Fully covered<?php if ($bucket['covered_by']): ?> by <?= h(implode(', ', array_unique(array_values($bucket['covered_by'])))) ?><?php endif; ?></span>
            </div>
            <?php else: ?>
            <div class="bg-slate-50 rounded-lg p-2.5">
              <div class="text-xs font-medium text-slate-500 mb-1.5">Term <?= $t ?></div>
              <?= render_claim_panel($bucket, $sec['section_id'], $group['subject_id'], $t, $roster, $modalPrefix . '-t' . $t) ?>
            </div>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  initMixPicker();
  initClaimModeToggle();
});
</script>
<?php render_footer(); ?>
