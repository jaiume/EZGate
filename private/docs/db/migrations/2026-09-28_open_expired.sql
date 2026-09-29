-- Allow open commands to expire without looking like a successful pulse.
ALTER TABLE open_commands
    MODIFY COLUMN status enum('pending','completed','expired') NOT NULL DEFAULT 'pending';
