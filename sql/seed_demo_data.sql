-- =============================================================================
-- NewUI v4.0 — Demo / Seed Data
--
-- 50 incident types across 5 organization templates, each with protocol text.
-- Also includes sample responders and facilities for testing.
--
-- Usage:  Run against a fresh or dev database.
--         Existing in_types rows are NOT deleted — these INSERT with new IDs.
--
-- NOTE:  This script uses no table prefix. If your installation uses a prefix,
--        find-replace `in_types` / `responder` / `facilities` accordingly.
-- =============================================================================

SET @prevAutocommit = @@autocommit;
SET autocommit = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- Widen protocol column to TEXT so it can hold multi-step protocols
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `in_types` MODIFY COLUMN `protocol` TEXT DEFAULT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- Clear existing demo data (safe: only deletes rows with group IN our 5 groups)
-- ─────────────────────────────────────────────────────────────────────────────
DELETE FROM `in_types` WHERE `group` IN ('RACES', 'CERT', 'Med Team', 'Vol Fire', 'Campus PD');

-- ─────────────────────────────────────────────────────────────────────────────
-- INCIDENT TYPES — 50 types across 5 org templates
-- Columns: type, description, protocol, set_severity, watch, group, sort,
--          radius, color, opacity, notify_mailgroup, notify_email, notify_when
-- ─────────────────────────────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════════════════
-- GROUP 1: RACES / Amateur Radio Emergency Communications (10 types)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO `in_types` (`type`, `description`, `protocol`, `set_severity`, `watch`, `group`, `sort`, `radius`, `color`, `opacity`) VALUES
('EmergNet', 'Emergency Net Activation',
 '1. Confirm activation authority and net frequency.\n2. Alert all registered operators via call-down list.\n3. Establish net control station and begin check-ins.\n4. Log all stations reporting and assign tactical roles.\n5. Notify served agency liaison of net status.',
 2, 0, 'RACES', 1, 5000, 'ff0000', 80),

('MsgRelay', 'Message Relay Request',
 '1. Record originator call sign, message precedence, and destination.\n2. Assign message number and log in traffic register.\n3. Relay via best available path (voice, digital, or packet).\n4. Obtain delivery confirmation and note time.',
 0, 0, 'RACES', 2, 0, NULL, 0),

('CommFail', 'Communications Failure',
 '1. Document which link or repeater has failed.\n2. Attempt alternate frequency or backup repeater.\n3. Deploy portable repeater if available.\n4. Notify net control and served agency of degraded comms.\n5. Log restoration time when resolved.',
 1, 0, 'RACES', 3, 0, 'ffaa00', 60),

('ShelterCom', 'Shelter Comms Support',
 '1. Deploy operator to shelter with go-kit and antenna.\n2. Establish voice and/or digital link to EOC.\n3. Provide shelter status reports every 30 minutes.\n4. Track shelter population count and resource needs.',
 0, 0, 'RACES', 4, 2000, '0088ff', 50),

('DmgAssess', 'Damage Assessment Reporting',
 '1. Receive field damage report from spotter or survey team.\n2. Record location, structure type, and damage category.\n3. Forward to EOC damage assessment coordinator.\n4. Request photo documentation if safe to do so.',
 0, 0, 'RACES', 5, 3000, 'ff8800', 60),

('WxSpotter', 'Weather Spotter Report',
 '1. Record spotter call sign and exact location.\n2. Document observation: wind speed, hail size, funnel, rotation.\n3. Relay immediately to NWS via SKYWARN net.\n4. Log time of observation and conditions.',
 1, 1, 'RACES', 6, 8000, '8800ff', 70),

('EventComm', 'Public Event Comms Support',
 '1. Brief all operators on event layout, frequencies, and contacts.\n2. Deploy operators to assigned positions.\n3. Conduct radio check with all positions.\n4. Monitor for medical, safety, or logistical requests.',
 0, 0, 'RACES', 7, 0, '00aa00', 40),

('EquipDep', 'Equipment Deployment',
 '1. Identify equipment needed and deployment location.\n2. Assign transport team and confirm vehicle availability.\n3. Document serial numbers and condition before deployment.\n4. Confirm setup and operational status on site.',
 0, 0, 'RACES', 8, 0, NULL, 0),

('InterLink', 'Interagency Comms Link',
 '1. Identify agencies requiring cross-patch or gateway.\n2. Configure cross-band repeat or digital gateway.\n3. Test link with both agencies and confirm audio quality.\n4. Monitor link continuously for signal degradation.',
 1, 0, 'RACES', 9, 0, '00cccc', 50),

('TrainEx', 'Training / Exercise Net',
 '1. Announce exercise start, scenario, and frequencies.\n2. Conduct check-ins and assign simulated roles.\n3. Run scenario traffic for designated duration.\n4. Debrief participants and log lessons learned.',
 0, 0, 'RACES', 10, 0, '888888', 30);


-- ═══════════════════════════════════════════════════════════════════════════
-- GROUP 2: CERT — Community Emergency Response Team (10 types)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO `in_types` (`type`, `description`, `protocol`, `set_severity`, `watch`, `group`, `sort`, `radius`, `color`, `opacity`) VALUES
('DisasterAct', 'Disaster Response Activation',
 '1. Confirm activation from local emergency management.\n2. Alert CERT members via phone tree and text.\n3. Establish staging area and check in all members.\n4. Brief teams on situation, assignments, and safety.\n5. Deploy teams in buddy pairs minimum.',
 2, 0, 'CERT', 11, 5000, 'ff0000', 80),

('LightSAR', 'Light Search & Rescue',
 '1. Conduct hasty search of assigned area using grid pattern.\n2. Mark searched structures with FEMA X-code system.\n3. Report any victims found — do NOT enter collapsed structures.\n4. Call for heavy rescue if entrapment detected.\n5. Document all findings on field form.',
 2, 0, 'CERT', 12, 3000, 'ff4400', 70),

('MedAssist', 'Medical Assistance (CERT)',
 '1. Conduct triage using START method.\n2. Treat life-threatening bleeding with direct pressure.\n3. Open airway and position for recovery if unconscious.\n4. Tag patient with triage category.\n5. Request professional EMS transport for Immediate patients.',
 1, 0, 'CERT', 13, 1000, 'ff8800', 60),

('DmgAssessCT', 'Damage Assessment (CERT)',
 '1. Survey assigned area block by block.\n2. Classify each structure: Affected, Minor, Major, Destroyed.\n3. Note utility hazards: gas leaks, downed power lines, water main breaks.\n4. Report findings to base via radio or runner.\n5. Do NOT enter damaged structures.',
 0, 0, 'CERT', 14, 3000, 'ffcc00', 50),

('WelfareChk', 'Welfare Check',
 '1. Attempt contact at door — knock, announce CERT.\n2. Check for signs of occupancy or distress.\n3. If no response and concern exists, note for professional follow-up.\n4. Document status: OK, No Contact, Needs Help.\n5. Report back to base immediately if assistance needed.',
 0, 0, 'CERT', 15, 500, '0088ff', 40),

('CrowdCtrl', 'Traffic / Crowd Control Support',
 '1. Set up cones or barriers at assigned intersection.\n2. Direct pedestrians away from hazard area.\n3. Allow only emergency vehicles through perimeter.\n4. Wear high-visibility vest at all times.\n5. Do NOT physically restrain anyone — request law enforcement if needed.',
 0, 0, 'CERT', 16, 1000, '00aa00', 40),

('SmallFire', 'Fire Suppression (Small Fire)',
 '1. Assess fire size — CERT handles trash can / small outdoor fires ONLY.\n2. Ensure escape route behind you before approaching.\n3. Use fire extinguisher: Pull, Aim, Squeeze, Sweep.\n4. If fire grows or smoke is heavy, withdraw and call 911.\n5. Report fire location and status to base.',
 1, 0, 'CERT', 17, 500, 'ff2200', 70),

('EvacAssist', 'Evacuation Assistance',
 '1. Identify evacuation zone boundaries and routes.\n2. Go door-to-door alerting residents to evacuate.\n3. Assist mobility-impaired residents to transport.\n4. Direct evacuees to designated shelter or rally point.\n5. Report completion of assigned area to base.',
 1, 0, 'CERT', 18, 2000, 'cc00ff', 60),

('ShelterSup', 'Shelter Support',
 '1. Register all arrivals with name and head count.\n2. Assign sleeping areas and distribute supplies.\n3. Identify medical needs and accessibility requirements.\n4. Maintain sign-in/sign-out log for accountability.\n5. Report shelter population to EOC every 2 hours.',
 0, 0, 'CERT', 19, 2000, '0066cc', 50),

('TrainDep', 'Training / Exercise Deployment',
 '1. Confirm exercise scenario and safety officer.\n2. Issue exercise vests and role cards to participants.\n3. Conduct safety briefing — all injuries are real, exercise stops.\n4. Run exercise per timeline.\n5. Collect all equipment and conduct hot wash debrief.',
 0, 0, 'CERT', 20, 0, '888888', 30);


-- ═══════════════════════════════════════════════════════════════════════════
-- GROUP 3: Volunteer Medical Team — Marathon / Race Events (10 types)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO `in_types` (`type`, `description`, `protocol`, `set_severity`, `watch`, `group`, `sort`, `radius`, `color`, `opacity`) VALUES
('Collapse', 'Runner Collapse',
 '1. Scene safety — stop nearby runners if on course.\n2. Assess consciousness: tap shoulders, call name.\n3. If unresponsive, check pulse. Begin CPR if no pulse.\n4. Apply AED immediately — do NOT delay for EMS.\n5. Request ALS transport. Continue CPR until takeover.',
 2, 0, 'Med Team', 21, 500, 'ff0000', 80),

('HeatIll', 'Heat Illness',
 '1. Move patient to shade or cooling tent.\n2. Remove excess clothing and equipment.\n3. Apply ice packs to neck, armpits, and groin.\n4. If conscious, provide cold oral fluids slowly.\n5. Monitor rectal temp if available. Transport if temp above 104F or altered mental status.',
 1, 0, 'Med Team', 22, 500, 'ff6600', 70),

('Dehydration', 'Dehydration',
 '1. Assess level: mild (thirst, dry mouth) vs severe (confusion, rapid pulse).\n2. Mild: oral rehydration with electrolyte solution, rest in shade.\n3. Severe: start IV normal saline if within scope. Request ALS.\n4. Monitor vital signs every 15 minutes.\n5. Release only when tolerating oral fluids and vitals stable.',
 0, 0, 'Med Team', 23, 0, 'ffaa00', 50),

('MinorInj', 'Minor Injury',
 '1. Clean wound with saline or clean water.\n2. Apply appropriate dressing or bandage.\n3. Ice and elevate sprains/strains.\n4. Assess if runner can continue or needs withdrawal.\n5. Document treatment on patient care form.',
 0, 0, 'Med Team', 24, 0, '00aa00', 40),

('MSKInj', 'Musculoskeletal Injury',
 '1. Assess mechanism of injury and pain location.\n2. Check distal pulses, sensation, and movement.\n3. Splint suspected fractures in position found.\n4. Apply ice, elevate, and provide pain management per protocol.\n5. Transport to medical tent or hospital based on severity.',
 1, 0, 'Med Team', 25, 0, 'ff8800', 60),

('CardiacEm', 'Cardiac Emergency',
 '1. Activate EMS immediately — call race medical director.\n2. Begin high-quality CPR if no pulse.\n3. Apply AED. Follow prompts without delay.\n4. Establish IV access if trained and authorized.\n5. Clear area for incoming ALS ambulance.\n6. Continue resuscitation until ALS takeover.',
 2, 0, 'Med Team', 26, 500, 'ff0000', 90),

('AltMental', 'Altered Mental Status',
 '1. Check blood glucose if meter available.\n2. If hypoglycemia and conscious: oral glucose gel or juice.\n3. If hypoglycemia and unconscious: request ALS for IV dextrose.\n4. Rule out head injury, heat stroke, hyponatremia.\n5. Monitor airway closely. Transport to medical tent.',
 1, 0, 'Med Team', 27, 500, 'cc00ff', 70),

('Transport', 'Transport to Hospital',
 '1. Confirm receiving hospital and transport unit.\n2. Complete patient handoff form with vitals, treatment, and timeline.\n3. Provide verbal report to transport crew: age, chief complaint, interventions.\n4. Confirm patient belongings and bib number documented.\n5. Notify race medical director of hospital transport.',
 1, 0, 'Med Team', 28, 0, '0066cc', 60),

('SpectMed', 'Spectator Medical Issue',
 '1. Approach and assess — treat as any patient regardless of bib status.\n2. Common issues: syncope, dehydration, allergic reaction, chest pain.\n3. Provide BLS care within scope.\n4. Request EMS for conditions beyond first aid.\n5. Document on spectator patient care form.',
 0, 0, 'Med Team', 29, 0, '0088ff', 50),

('SupplyReq', 'Medical Supply Request',
 '1. Document item needed, quantity, and requesting station.\n2. Check supply cache inventory.\n3. Dispatch runner or vehicle to deliver.\n4. Confirm receipt at requesting station.\n5. Update inventory log.',
 0, 0, 'Med Team', 30, 0, '888888', 30);


-- ═══════════════════════════════════════════════════════════════════════════
-- GROUP 4: Rural Volunteer Fire Department (10 types)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO `in_types` (`type`, `description`, `protocol`, `set_severity`, `watch`, `group`, `sort`, `radius`, `color`, `opacity`) VALUES
('StrucFire', 'Structure Fire',
 '1. Dispatch minimum 2 engines and 1 tanker.\n2. First-due establishes command and does 360 size-up.\n3. Confirm occupancy status — search and rescue is priority.\n4. Establish water supply from hydrant or tanker shuttle.\n5. Attack fire using appropriate strategy (offensive/defensive).\n6. Request mutual aid early if needed.',
 2, 1, 'Vol Fire', 31, 3000, 'ff0000', 80),

('WildFire', 'Wildland / Grass Fire',
 '1. Size up: estimated acres, fuel type, wind direction, rate of spread.\n2. Establish lookouts, communications, escape routes, safety zones.\n3. Protect structures if threatened — set up defensible space.\n4. Request DNR or forestry if fire exceeds local capacity.\n5. Monitor for spot fires downwind.',
 1, 1, 'Vol Fire', 32, 8000, 'ff6600', 70),

('MVA', 'Motor Vehicle Accident',
 '1. Establish scene safety — position apparatus to block traffic.\n2. Assess number of vehicles and patients.\n3. Stabilize vehicles before patient contact.\n4. Request EMS and extrication equipment if entrapment.\n5. Manage fuel spills and fire hazards.\n6. Set up traffic control with cones and flares.',
 1, 0, 'Vol Fire', 33, 1000, 'ffcc00', 60),

('MedEmerg', 'Medical Emergency',
 '1. Respond with first responder medical kit and AED.\n2. Assess scene safety and patient condition.\n3. Provide BLS care: airway, breathing, CPR, bleeding control.\n4. AED for cardiac arrest — begin immediately.\n5. Provide patient handoff report to arriving EMS.',
 1, 0, 'Vol Fire', 34, 500, 'ff8800', 60),

('VehFire', 'Vehicle Fire',
 '1. Approach from upwind and uphill.\n2. Establish minimum 100-foot perimeter.\n3. Identify fuel type: gas, diesel, electric/hybrid (battery risk).\n4. For EV fires: use copious water, maintain distance, watch for re-ignition.\n5. Extinguish and monitor for 30 minutes after knockdown.',
 1, 0, 'Vol Fire', 35, 1000, 'ff4400', 70),

('HazMat', 'Hazardous Materials Incident',
 '1. Isolate the area and deny entry — stay upwind and uphill.\n2. Identify materials using placards, SDS, or ERG guide.\n3. Establish hot, warm, and cold zones.\n4. Do NOT attempt cleanup without proper PPE and training.\n5. Request HazMat team through county dispatch.\n6. Begin evacuation if public is at risk.',
 2, 1, 'Vol Fire', 36, 5000, 'ff00ff', 80),

('Rescue', 'Rescue / Extrication',
 '1. Stabilize vehicle or structure before approaching patient.\n2. Assess patient and provide medical care during extrication.\n3. Use hydraulic tools per training — cribbing under all lift points.\n4. Protect patient with hard protection during cutting.\n5. Coordinate with EMS for immediate transport after extrication.',
 2, 0, 'Vol Fire', 37, 500, 'cc0000', 70),

('FalseAlm', 'False Alarm',
 '1. Investigate alarm source — check panel for zone and device.\n2. Walk through indicated zone to verify no fire or smoke.\n3. Check for common causes: cooking, dust, system malfunction.\n4. Reset alarm system and confirm with monitoring company.\n5. Document cause and advise occupant on prevention.',
 0, 0, 'Vol Fire', 38, 0, '888888', 30),

('PubService', 'Public Service Call',
 '1. Assess request type: lockout, animal rescue, water problem, lift assist.\n2. Determine if fire department response is appropriate.\n3. Use appropriate tools and techniques for the task.\n4. Do NOT exceed training or equipment capabilities.\n5. Refer to appropriate agency if outside our scope.',
 0, 0, 'Vol Fire', 39, 0, '00aa00', 40),

('StormResp', 'Severe Weather / Storm Response',
 '1. Monitor weather alerts and pre-position apparatus.\n2. Prioritize life safety calls: rescues, medical, structure collapses.\n3. Clear roadways of debris as resources allow.\n4. Establish shelters if requested by emergency management.\n5. Document damage for county damage assessment report.',
 1, 1, 'Vol Fire', 40, 5000, '8800ff', 60);


-- ═══════════════════════════════════════════════════════════════════════════
-- GROUP 5: University Security / Campus Police (10 types)
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO `in_types` (`type`, `description`, `protocol`, `set_severity`, `watch`, `group`, `sort`, `radius`, `color`, `opacity`) VALUES
('SuspAct', 'Suspicious Person / Activity',
 '1. Obtain detailed description: clothing, direction, behavior.\n2. Dispatch nearest officer for area check.\n3. Check access control logs for building entry.\n4. If person located, conduct consensual contact.\n5. Run ID check if reasonable suspicion exists.\n6. Document and close or escalate as appropriate.',
 0, 0, 'Campus PD', 41, 1000, 'ffcc00', 50),

('Theft', 'Theft / Property Crime',
 '1. Obtain location, item description, and estimated value.\n2. Dispatch officer to take report.\n3. Check security camera footage for time of loss.\n4. If in progress, request immediate response and perimeter.\n5. Issue campus-wide alert if pattern detected.\n6. Submit report to records for case number.',
 0, 0, 'Campus PD', 42, 500, 'ff8800', 40),

('Disturb', 'Disturbance / Disorderly Conduct',
 '1. Determine nature: verbal argument, party noise, physical altercation.\n2. Dispatch officer(s) — minimum 2 for physical altercations.\n3. Separate involved parties upon arrival.\n4. Determine if criminal conduct occurred.\n5. Contact Residence Life / Dean of Students if students involved.\n6. Issue trespass warning to non-affiliates if appropriate.',
 1, 0, 'Campus PD', 43, 500, 'ff4400', 60),

('MedEmergCP', 'Medical Emergency (Campus)',
 '1. Dispatch campus officer with AED and first aid kit.\n2. Call 911 for EMS simultaneously.\n3. Provide pre-arrival instructions: CPR, bleeding control.\n4. Direct EMS to correct building entrance upon arrival.\n5. Notify Student Health if student is involved.\n6. Document for Clery Act reporting.',
 2, 0, 'Campus PD', 44, 500, 'ff0000', 80),

('AlarmAct', 'Alarm Activation',
 '1. Identify alarm type: fire, intrusion, panic, hold-up.\n2. Dispatch officer to location immediately.\n3. For fire alarms: confirm with fire department and begin building check.\n4. For intrusion: approach with caution, check all entry points.\n5. Determine cause and reset or request service.\n6. Notify building coordinator.',
 0, 0, 'Campus PD', 45, 500, 'ffaa00', 50),

('AccessCtrl', 'Access Control Issue',
 '1. Identify nature: card reader failure, propped door, unauthorized access.\n2. Dispatch officer or facilities as appropriate.\n3. For card reader failure: issue temporary access and create work order.\n4. For unauthorized access: investigate and determine if security breach.\n5. Update access control system as needed.',
 0, 0, 'Campus PD', 46, 0, '0088ff', 40),

('EscortReq', 'Escort Request',
 '1. Obtain requester name, current location, and destination.\n2. Dispatch available officer or safety escort.\n3. Estimated wait time should not exceed 15 minutes.\n4. Walk or drive requester to destination.\n5. Log completion time.',
 0, 0, 'Campus PD', 47, 0, '00aa00', 30),

('MHCrisis', 'Mental Health Crisis',
 '1. Dispatch trained crisis intervention officer if available.\n2. Approach calmly — do NOT rush or corner the individual.\n3. Listen actively, validate feelings, assess risk level.\n4. If imminent danger to self or others, request EMS and consider protective custody.\n5. Contact campus counseling center for follow-up.\n6. Document per Title IX and Clery requirements.',
 2, 0, 'Campus PD', 48, 500, 'cc00ff', 70),

('TrafficPrk', 'Traffic / Parking Incident',
 '1. Determine type: accident, blocked vehicle, violation, road hazard.\n2. For accidents: dispatch officer and check for injuries.\n3. For blocked vehicles: attempt to identify owner via permit records.\n4. For hazards: place cones or barricades and request facilities.\n5. Issue citations as warranted.',
 0, 0, 'Campus PD', 49, 0, '888888', 40),

('LostFound', 'Lost / Found Property',
 '1. Document item description, location found, and finder info.\n2. Check lost property reports for matching claims.\n3. Secure valuables in evidence locker.\n4. If ID or device: attempt to contact owner.\n5. Post to campus lost-and-found system.\n6. Dispose per policy after 90-day holding period.',
 0, 0, 'Campus PD', 50, 0, '888888', 30);


-- ─────────────────────────────────────────────────────────────────────────────
-- SAMPLE RESPONDERS (10 units for testing)
-- ─────────────────────────────────────────────────────────────────────────────

-- Only insert if responder table is empty (don't clobber real data)
-- type is tinyint: 0=default, 1=vehicle, 2=person, etc. description is required NOT NULL text.
INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT * FROM (SELECT 'Engine 1' AS name, 'E1' AS handle, 1 AS type, 'Pumper engine, 1000 GPM' AS description) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `responder` LIMIT 1);

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Engine 2', 'E2', 1, 'Pumper engine, 750 GPM' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Engine 2');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Tanker 1', 'T1', 1, 'Water tanker, 3000 gal' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Tanker 1');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Rescue 1', 'R1', 1, 'Heavy rescue with extrication tools' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Rescue 1');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Medic 1', 'M1', 1, 'ALS ambulance' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Medic 1');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Medic 2', 'M2', 1, 'BLS ambulance' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Medic 2');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Patrol 1', 'P1', 2, 'Campus patrol officer' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Patrol 1');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Patrol 2', 'P2', 2, 'Campus patrol officer' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Patrol 2');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'Net Control', 'NC', 2, 'RACES net control operator' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'Net Control');

INSERT INTO `responder` (`name`, `handle`, `type`, `description`)
SELECT 'CERT Team A', 'CTA', 0, 'CERT volunteer team alpha' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `responder` WHERE `name` = 'CERT Team A');


-- ─────────────────────────────────────────────────────────────────────────────
-- SAMPLE FACILITIES (5 for testing)
-- ─────────────────────────────────────────────────────────────────────────────

-- type is smallint, description is required NOT NULL text.
INSERT INTO `facilities` (`name`, `type`, `description`, `lat`, `lng`)
SELECT 'Community Center', 1, 'Emergency shelter, capacity 200', 44.9537, -93.2900 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `facilities` WHERE `name` = 'Community Center');

INSERT INTO `facilities` (`name`, `type`, `description`, `lat`, `lng`)
SELECT 'General Hospital', 2, 'Level II trauma center', 44.9720, -93.2610 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `facilities` WHERE `name` = 'General Hospital');

INSERT INTO `facilities` (`name`, `type`, `description`, `lat`, `lng`)
SELECT 'Fire Station #1', 3, 'Volunteer fire station, headquarters', 44.9780, -93.2650 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `facilities` WHERE `name` = 'Fire Station #1');

INSERT INTO `facilities` (`name`, `type`, `description`, `lat`, `lng`)
SELECT 'University Student Union', 4, 'Campus gathering hall, meeting rooms', 44.9740, -93.2320 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `facilities` WHERE `name` = 'University Student Union');

INSERT INTO `facilities` (`name`, `type`, `description`, `lat`, `lng`)
SELECT 'Marathon Finish Line Medical', 5, 'Race medical tent with AED and supplies', 44.9800, -93.2710 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `facilities` WHERE `name` = 'Marathon Finish Line Medical');


COMMIT;
SET autocommit = @prevAutocommit;

-- Done! You should now have 50 incident types with protocols ready to test.
