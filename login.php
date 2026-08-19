<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('/index.php');
}

$redirectTo = $_GET['redirect'] ?? $_POST['redirect'] ?? '/index.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (attempt_login($username, $password)) {
        redirect($redirectTo !== '' ? $redirectTo : '/index.php');
    } else {
        $error = 'Invalid username or password.';
    }
}

render_header('Log in');
?>
<div class="min-h-screen grid lg:grid-cols-2">
  <div class="hidden lg:flex flex-col justify-between bg-gradient-to-br from-accent-50 via-white to-accent-100 px-14 py-12 relative overflow-hidden">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-accent-600 text-white flex items-center justify-center shadow-sm"><?= icon_svg('graduation-cap', 'w-6 h-6') ?></div>
      <div>
        <div class="font-semibold text-accent-700 text-lg leading-tight">TAPAT</div>
        <div class="text-xs text-slate-500">Grade Consolidation System</div>
      </div>
    </div>

    <div class="max-w-md">
      <div class="text-5xl font-bold text-accent-700 tracking-tight mb-3">TAPAT</div>
      <div class="text-2xl font-semibold text-slate-700 mb-4">Grade Consolidation System</div>
      <p class="text-slate-500">Streamline grade consolidation, ensure accuracy, and support student success.</p>
    </div>

    <div class="flex items-center gap-3 bg-white/80 backdrop-blur border border-white rounded-xl shadow-sm px-4 py-3 max-w-md">
      <div class="w-9 h-9 rounded-lg bg-accent-100 text-accent-700 flex items-center justify-center flex-shrink-0"><?= icon_svg('shield', 'w-4 h-4') ?></div>
      <div>
        <div class="text-sm font-medium text-slate-700">Secure access to your school data</div>
        <div class="text-xs text-slate-500">Your information is protected with role-based access control.</div>
      </div>
    </div>

    <div class="absolute -bottom-24 -right-24 w-72 h-72 rounded-full bg-accent-100/60"></div>
    <div class="absolute top-1/3 -left-16 w-40 h-40 rounded-full bg-accent-100/40"></div>
  </div>

  <div class="flex items-center justify-center px-6 py-16 bg-slate-50">
    <div class="w-full max-w-sm">
      <div class="flex flex-col items-center text-center mb-6">
        <div class="w-14 h-14 rounded-full bg-accent-600 text-white flex items-center justify-center mb-4 shadow-sm"><?= icon_svg('graduation-cap', 'w-7 h-7') ?></div>
        <h1 class="text-2xl font-semibold text-slate-800">Welcome back</h1>
        <p class="text-sm text-slate-500 mt-1">Please sign in to continue to TAPAT</p>
      </div>

      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-8">
        <?php if ($error): ?>
          <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-rose-50 text-rose-700 border border-rose-200"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo) ?>">

          <label class="block text-sm font-medium text-slate-600 mb-1">Username</label>
          <div class="relative mb-4">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon_svg('students', 'w-4 h-4') ?></span>
            <input type="text" name="username" required autofocus placeholder="Enter your username" class="w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-500">
          </div>

          <label class="block text-sm font-medium text-slate-600 mb-1">Password</label>
          <div class="relative mb-6">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon_svg('lock', 'w-4 h-4') ?></span>
            <input type="password" name="password" id="login-password" required placeholder="Enter your password" class="w-full pl-10 pr-10 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent-500">
            <button type="button" id="toggle-password" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label="Show password"><?= icon_svg('eye', 'w-4 h-4') ?></button>
          </div>

          <button type="submit" class="w-full bg-accent-600 hover:bg-accent-700 text-white font-medium py-2.5 rounded-lg flex items-center justify-center gap-2">
            <?= icon_svg('arrow-right', 'w-4 h-4') ?> Log in
          </button>
        </form>
      </div>

      <p class="text-center text-xs text-slate-400 mt-6">Need help? Contact your system administrator for assistance.</p>
    </div>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('toggle-password');
  var input = document.getElementById('login-password');
  if (!btn || !input) return;
  btn.addEventListener('click', function () {
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
  });
})();
</script>
<?php render_footer(); ?>
