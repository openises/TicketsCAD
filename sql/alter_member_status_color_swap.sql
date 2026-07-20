-- Member-status color semantic alignment.
--
-- Same drift as member_types had (see alter_member_types_color_swap.sql for
-- the full rationale). The config UI's saveMemberStatus writes:
--   color column     = TEXT/foreground color (input.color)
--   background col   = BG color (input.background)
-- The legacy seed + the member-list API read:
--   color column     = badge BG (e.g. Active: color=#198754=green)
--   text_color col   = text/foreground color
--
-- Result: member-list STATUS badges don't respect what an operator
-- configures via the modern UI. a beta tester flagged this 2026-06-26
-- as a follow-up to the member_types fix from earlier the same day.
--
-- This migration brings legacy seed rows into the new convention. For
-- any row that looks legacy (background is null/default white AND color
-- is set to something non-default), promote color → background, and set
-- color to white (#FFFFFF) so the text is readable on the (usually dark)
-- promoted background. After this migration, every row uses the same
-- convention as the config UI Preview.
--
-- Companion code change: api/members.php reads
--   mt.background AS type_color, mt.color AS type_text_color
-- and similarly for status:
--   ms.background AS status_color, ms.color AS status_text_color
--
-- Idempotent: WHERE clause excludes rows where the swap was already
-- applied (background non-null and non-default).

UPDATE `member_status`
SET
    `background` = `color`,
    `color`      = '#FFFFFF'
WHERE
    `color` IS NOT NULL
    AND `color` <> ''
    AND `color` <> '#000000'
    AND (`background` IS NULL OR `background` = '' OR `background` = '#FFFFFF');
