-- ============================================================
-- Organizations + Member-Organization Junction
-- NewUI v4.0 — Multi-org support
-- ============================================================

-- Organizations (agencies, clubs, departments)
CREATE TABLE IF NOT EXISTS organizations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    short_name      VARCHAR(32)  DEFAULT NULL COMMENT 'Navbar badge, compact displays',
    org_type        VARCHAR(64)  DEFAULT NULL COMMENT 'RACES, CERT, Fire, EMS, Campus PD, Radio Club',
    description     TEXT         DEFAULT NULL,
    contact_name    VARCHAR(128) DEFAULT NULL,
    contact_email   VARCHAR(128) DEFAULT NULL,
    contact_phone   VARCHAR(24)  DEFAULT NULL,
    address         VARCHAR(255) DEFAULT NULL,
    city            VARCHAR(64)  DEFAULT NULL,
    state           VARCHAR(4)   DEFAULT NULL,
    zip             VARCHAR(16)  DEFAULT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     DEFAULT NULL,
    updated_at      DATETIME     DEFAULT NULL,
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member ↔ Organization many-to-many
CREATE TABLE IF NOT EXISTS member_organizations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    member_id       INT          NOT NULL,
    org_id          INT          NOT NULL,
    member_type_id  INT          DEFAULT NULL COMMENT 'Rank/level within this org (FK to member_types)',
    role_id         INT          DEFAULT NULL COMMENT 'Future RBAC role reference',
    join_date       DATE         DEFAULT NULL,
    status          ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    notes           VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME     DEFAULT NULL,
    UNIQUE KEY uq_member_org (member_id, org_id),
    KEY idx_org_id (org_id),
    KEY idx_member_id (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Default "System Owner" organization
INSERT IGNORE INTO organizations (id, name, short_name, org_type, description, active, sort_order, created_at)
VALUES (1, 'System Owner', 'Main', 'General',
        'Default organization. Rename to match your agency or department.',
        1, 0, NOW());

-- Migrate existing members into System Owner org
-- Uses member_type_id if NewUI schema, field3 if legacy schema, or just id
-- This is safe to re-run (INSERT IGNORE + UNIQUE KEY)
INSERT IGNORE INTO member_organizations (member_id, org_id, status, created_at)
SELECT id, 1, 'active', NOW()
FROM member
WHERE id IS NOT NULL;
