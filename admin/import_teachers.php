<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role(['admin']);
$pdo = db();

const IMPORT_TEACHER_DEFAULT_PASSWORD = 'ilovejacobo';

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $namesRaw = (string) ($_POST['full_names'] ?? '');
    $names = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $namesRaw))));

    $created = [];
    $skipped = [];
    $usedInBatch = [];
    // Hashed once — it's a literal constant, not worth re-hashing per row.
    $defaultHash = password_hash(IMPORT_TEACHER_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, must_change_password) VALUES (?, ?, ?, 'subject_teacher', 1)");

    foreach ($names as $name) {
        if ($name === '') {
            continue;
        }
        $username = resolve_unique_username($pdo, generate_username($name), $usedInBatch);
        try {
            $insert->execute([$name, $username, $defaultHash]);
            $created[] = ['full_name' => $name, 'username' => $username];
        } catch (PDOException $e) {
            $skipped[] = "\"$name\" — could not create (unexpected database error).";
        }
    }

    $results = ['created' => $created, 'skipped' => $skipped];
    flash_set('success', count($created) . ' teacher account(s) created.' . ($skipped ? ' ' . count($skipped) . ' skipped — see details below.' : ''));
}

render_header('Import Teachers');
?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-xl">
  <h2 class="text-sm font-semibold text-slate-600 mb-2">Bulk-create teacher accounts</h2>
  <p class="text-xs text-slate-400 mb-4">One full name per line (e.g. "Jay Mar V. Canturia"). A username is generated automatically for each (first name + surname, lowercased — falls back to just the first given name if the combination is too long). Every account starts with the same default password, <strong>ilovejacobo</strong>, and each teacher will be asked to set their own the first time they log in.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label class="block text-sm font-medium text-slate-600 mb-1">Full names (one per line)</label>
    <textarea name="full_names" required rows="8" placeholder="Jay Mar V. Canturia&#10;Ireesh Joy J. Landicho" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg font-mono text-sm"></textarea>
    <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Create Accounts</button>
    <a href="<?= h(url('/admin/users.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Back to Users</a>
  </form>
</div>

<?php if ($results && $results['created']): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mb-6 max-w-xl">
  <h2 class="text-sm font-semibold text-slate-600 mb-3">Created (<?= count($results['created']) ?>)</h2>
  <p class="text-xs text-slate-400 mb-3">Default password for all of these: <strong>ilovejacobo</strong>. Copy the list below to hand out to teachers.</p>
  <div class="overflow-hidden rounded-lg border border-slate-200 mb-4">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
        <tr><th class="text-left px-4 py-2">Full Name</th><th class="text-left px-4 py-2">Username</th></tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($results['created'] as $c): ?>
        <tr>
          <td class="px-4 py-2"><?= h($c['full_name']) ?></td>
          <td class="px-4 py-2 font-mono text-slate-600"><?= h($c['username']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <label class="block text-xs font-medium text-slate-500 mb-1">Copy for Excel/Sheets (Name, Username)</label>
  <textarea readonly rows="6" class="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono text-xs bg-slate-50" onclick="this.select()"><?= h(implode("\n", array_map(fn($c) => $c['full_name'] . "\t" . $c['username'], $results['created']))) ?></textarea>
</div>
<?php endif; ?>

<?php if ($results && $results['skipped']): ?>
<div class="bg-white border border-amber-200 rounded-xl shadow-sm p-6 max-w-xl">
  <h2 class="text-sm font-semibold text-amber-700 mb-3">Skipped (<?= count($results['skipped']) ?>)</h2>
  <ul class="text-sm text-slate-600 space-y-1 list-disc list-inside">
    <?php foreach ($results['skipped'] as $reason): ?>
      <li><?= h($reason) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php render_footer(); ?>
