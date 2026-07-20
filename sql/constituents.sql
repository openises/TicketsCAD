-- NewUI v4.0 - Constituents Table
-- Contacts/callers database for phone-based lookup during incident creation.
-- Ported from legacy tickets system.

CREATE TABLE IF NOT EXISTS `constituents` (
  `id` bigint(7) NOT NULL AUTO_INCREMENT,
  `contact` varchar(96) NOT NULL COMMENT 'Person name',
  `street` varchar(48) DEFAULT NULL,
  `apartment` varchar(48) DEFAULT NULL,
  `community` varchar(48) DEFAULT NULL COMMENT 'Neighborhood/community',
  `city` varchar(48) DEFAULT NULL,
  `post_code` varchar(48) DEFAULT NULL,
  `state` varchar(48) DEFAULT NULL,
  `miscellaneous` varchar(255) DEFAULT NULL COMMENT 'Notes, warnings, special info',
  `phone` varchar(32) NOT NULL COMMENT 'Primary phone',
  `phone_type` varchar(24) DEFAULT NULL COMMENT 'e.g. Mobile, Home, Work, Day, Night',
  `phone_2` varchar(32) DEFAULT NULL,
  `phone_2_type` varchar(24) DEFAULT NULL,
  `phone_3` varchar(32) DEFAULT NULL,
  `phone_3_type` varchar(24) DEFAULT NULL,
  `phone_4` varchar(32) DEFAULT NULL,
  `phone_4_type` varchar(24) DEFAULT NULL,
  `email` varchar(48) DEFAULT NULL,
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `reference` varchar(48) DEFAULT NULL COMMENT 'External reference ID',
  `updated` datetime DEFAULT NULL,
  `_by` int(7) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `phone` (`phone`),
  KEY `phone_2` (`phone_2`),
  KEY `phone_3` (`phone_3`),
  KEY `phone_4` (`phone_4`),
  KEY `contact` (`contact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample data
INSERT INTO `constituents` (`contact`, `street`, `city`, `state`, `phone`, `miscellaneous`, `updated`) VALUES
('John Smith', '123 Main St', 'Springfield', 'IL', '555-0101', 'Elderly resident, hard of hearing. Dog in backyard.', NOW()),
('Maria Garcia', '456 Oak Ave', 'Springfield', 'IL', '555-0102', 'Spanish speaking household. Two small children.', NOW()),
('Robert Johnson', '789 Elm Dr', 'Shelbyville', 'IL', '555-0103', 'Known medical condition - diabetic. Insulin in fridge.', NOW()),
('Sarah Williams', '321 Pine Rd', 'Springfield', 'IL', '555-0104', NULL, NOW()),
('David Brown', '654 Maple Ln', 'Capital City', 'IL', '555-0105', 'Guard dog on premises. Use side entrance.', NOW());
