-- Bulk "Import Teachers" (admin/import_teachers.php) creates every account with the same
-- publicly-known default password. This flag forces that specific teacher to set their own
-- password before touching anything else, enforced centrally in includes/auth.php's
-- require_login(). Every other account-creation path (admin/users.php,
-- admin/head_teachers.php) is untouched — this stays 0/not forced there, deliberately
-- scoped to bulk-import only, not a site-wide password policy change.
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
