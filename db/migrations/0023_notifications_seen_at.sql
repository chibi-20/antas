-- Admin's bell is a live "awaiting review" backlog with no per-item notification rows, so it
-- had no read state and its badge never cleared. This records when the user last opened the
-- bell; admin's badge now counts only submissions that arrived after that moment.
ALTER TABLE users ADD COLUMN notifications_seen_at TIMESTAMP NULL DEFAULT NULL;
