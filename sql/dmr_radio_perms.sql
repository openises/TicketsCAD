-- Phase 84s — DMR radio widget RBAC permissions
-- The `code` column is the rbac_can() lookup key (not `name`).
INSERT IGNORE INTO permissions (code, name, category, description) VALUES
  ('action.dmr_receive',  'DMR: Receive Audio',  'action',
   'Receive live DMR audio from a configured channel'),
  ('action.dmr_transmit', 'DMR: Push-to-talk',   'action',
   'Push-to-talk transmit on a configured DMR channel');

-- Back-fill code for any earlier rows that landed with empty code.
UPDATE permissions SET code='action.dmr_receive'
  WHERE name='action.dmr_receive' AND (code IS NULL OR code='');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
  WHERE p.code IN ('action.dmr_receive','action.dmr_transmit')
    AND r.name IN ('Super Admin','Org Admin','Dispatcher');
