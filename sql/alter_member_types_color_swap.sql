-- Member-types color semantic alignment.
--
-- The member-types config UI saves with the convention:
--   color column     = TEXT/foreground color (input.color)
--   background col   = BG color (input.background)
-- And the config-page Preview cell renders the same way:
--   <span style="color:$color; background:$background">…</span>
--
-- But the LEGACY seed in sql/membership.sql (and the API list query in
-- api/members.php) used the OPPOSITE convention:
--   color column     = badge BG (e.g. Full Member: color=#198754=green)
--   text_color col   = text/foreground color
--
-- Result: any member-type row saved through the config UI under the new
-- convention renders as text-on-text (often invisible) on the member list
-- page. a beta tester reported this 2026-06-26: he created "Dispatcher"
-- as white-bg/black-text in config, but his member list showed a black
-- rectangle in the TYPE column.
--
-- The fix (this migration): bring legacy seed rows into the new convention.
-- For any row that looks legacy (background is null/default white AND color
-- is set to something non-default), promote color → background, and set
-- color to white (#FFFFFF) so the text is readable on the (usually dark)
-- promoted background. After this migration, every row uses the same
-- convention as the config UI Preview.
--
-- The companion code change reads the new convention via:
--   COALESCE(mt.background, mt.color, '#6c757d') AS type_color   -- badge BG
--   COALESCE(mt.text_color, mt.color, '#FFFFFF') AS type_text_color  -- text
-- so even un-migrated rows continue to render with a sensible fallback.
--
-- Idempotent: WHERE clause excludes rows where the swap was already
-- applied (background non-null and non-default).

UPDATE `member_types`
SET
    `background` = `color`,
    `color`      = '#FFFFFF'
WHERE
    `color` IS NOT NULL
    AND `color` <> ''
    AND `color` <> '#000000'
    AND (`background` IS NULL OR `background` = '' OR `background` = '#FFFFFF');
