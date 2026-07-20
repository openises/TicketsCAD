-- =============================================================
-- NewUI v4.0 - SOP Wiki Tables
-- Standard Operating Procedures wiki system
-- =============================================================

CREATE TABLE IF NOT EXISTS `sop_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(128) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` mediumtext NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sop_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_id` int(11) NOT NULL,
  `content` mediumtext NOT NULL,
  `title` varchar(255) NOT NULL,
  `edited_by` int(11) NOT NULL,
  `edited_at` datetime NOT NULL,
  `summary` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `page_id` (`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- Seed Data: Example SOP Pages
-- =============================================================

INSERT INTO `sop_pages` (`slug`, `title`, `content`, `parent_id`, `sort_order`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES
('home', 'SOP Wiki Home', '# Welcome to the SOP Wiki\n\nThis is the Standard Operating Procedures wiki for your organization. Use the page tree on the left to navigate between procedures, or create new pages to document your team''s processes.\n\n## Getting Started\n\n- Click **New Page** in the sidebar to create a new procedure\n- Click **Edit** on any page to update its content\n- Use **Markdown** formatting for headings, lists, tables, and more\n- All changes are saved with revision history so nothing is lost\n\n## Suggested Categories\n\n- **Radio Procedures** - Communications protocols and frequencies\n- **Incident Response** - Step-by-step response procedures by incident type\n- **Equipment** - Inventory, maintenance schedules, and operating guides\n- **Training** - Onboarding checklists and certification requirements\n- **Admin** - Organizational policies and contact lists', NULL, 0, 1, NOW(), NULL, NULL),

('radio-procedures', 'Radio Procedures', '# Radio Procedures\n\nThis section covers standard radio communications protocols.\n\n## General Rules\n\n1. Keep transmissions brief and to the point\n2. Wait for the channel to be clear before transmitting\n3. Identify yourself at the beginning of each transmission\n4. Use plain language; avoid jargon unless universally understood\n5. Confirm receipt of critical messages with a read-back\n\n## Standard Phrases\n\n| Phrase | Meaning |\n|--------|--------|\n| Copy | Message received and understood |\n| Negative | No / Incorrect |\n| Affirmative | Yes / Correct |\n| Stand by | Wait for further instructions |\n| Say again | Repeat your last message |\n| Go ahead | Proceed with your message |\n| Clear | End of communication |\n\n## Channel Assignments\n\nChannel assignments vary by organization. Update this section with your local channel plan.\n\n> **Note:** Always monitor the primary dispatch channel unless directed otherwise.', 1, 0, 1, NOW(), NULL, NULL),

('incident-response', 'Incident Response', '# Incident Response Overview\n\nThis section contains standard operating procedures for various incident types.\n\n## Response Priority Levels\n\n- **Critical (Priority 1)** - Life-threatening emergency, immediate response\n- **Elevated (Priority 2)** - Urgent situation, rapid response required\n- **Normal (Priority 3)** - Non-emergency, routine response\n\n## General Response Steps\n\n1. **Receive** - Acknowledge the dispatch and confirm assignment\n2. **Respond** - Proceed to the scene using appropriate route\n3. **Arrive** - Announce arrival on scene, assess the situation\n4. **Stabilize** - Take immediate actions to secure the scene\n5. **Report** - Provide a situation report to dispatch\n6. **Resolve** - Complete assigned tasks and prepare for clearance\n7. **Clear** - Notify dispatch when available for reassignment\n\n## Child Procedures\n\nSee the sub-pages below for incident-type-specific SOPs.', 1, 1, 1, NOW(), NULL, NULL),

('structure-fire', 'Structure Fire SOP', '# Structure Fire - Standard Operating Procedure\n\n## Dispatch Information Required\n\n- Exact address and cross streets\n- Type of structure (residential, commercial, industrial)\n- Number of stories\n- Persons reported trapped or injured\n- Hazardous materials on site\n\n## Initial Response\n\n### First-Due Engine Company\n\n1. Establish command and give a size-up report\n2. Determine attack mode (offensive vs. defensive)\n3. Establish water supply\n4. Begin fire attack on the seat of the fire\n5. Conduct primary search if safe to do so\n\n### Second-Due Engine Company\n\n1. Establish secondary water supply\n2. Assist with fire attack or exposure protection\n3. Support search and rescue operations\n\n### Truck Company\n\n1. Perform forcible entry as needed\n2. Conduct ventilation\n3. Perform search and rescue\n4. Utility control (gas, electric)\n\n## Safety Considerations\n\n- **Two-in / Two-out** rule must be followed at all times\n- Maintain personnel accountability\n- Monitor radio for emergency evacuation signals\n- Establish a collapse zone for defensive operations\n\n## Incident Command\n\nThe first arriving officer establishes Incident Command until relieved by a senior officer. Use ICS structure for all working fires.\n\n```\nIncident Commander\n  +-- Operations\n  |     +-- Fire Attack\n  |     +-- Search & Rescue\n  |     +-- Ventilation\n  +-- Safety Officer\n  +-- Staging\n  +-- Logistics\n```', 3, 0, 1, NOW(), NULL, NULL);
