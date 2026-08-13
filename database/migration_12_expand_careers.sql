-- CareerPath AI — Migration 12: Expand the career catalog
-- --------------------------------------------------------------------
-- Migration 11 grouped careers into industry/job clusters for the
-- assessment's dream-career picker, but several clusters only had one
-- career in them (e.g. Agriculture & Environmental Science had just
-- "Agriculturist"). Picking a field and seeing exactly one option isn't a
-- real choice — this migration adds 28 more careers so every cluster has
-- several real options to explore, which is the actual point of grouping
-- them in the first place.
--
-- Safe to re-run individually isn't guaranteed on older MySQL — if you get
-- a duplicate-row-ish issue on re-run, that's expected; this migration is
-- meant to run once.

USE careerpath_ai;

INSERT INTO careers (career_title, career_category, description, daily_task, educational_pathway, r_score, i_score, a_score, s_score, e_score, c_score) VALUES
-- Healthcare & Medical
('Medical Technologist', 'Healthcare & Medical', 'Performs laboratory tests on patient samples to help diagnose and monitor diseases.', 'Running lab tests, analyzing specimens, maintaining lab equipment, recording results.', 'BS Medical Technology / Medical Laboratory Science + Licensure Exam', 20, 85, 15, 40, 20, 60),
('Physical Therapist', 'Healthcare & Medical', 'Helps patients recover movement and manage pain through therapeutic exercise and treatment.', 'Assessing patients, designing therapy plans, guiding exercises, tracking recovery progress.', 'BS Physical Therapy + Licensure Exam', 55, 50, 15, 85, 25, 35),
('Pharmacist', 'Healthcare & Medical', 'Dispenses medications and advises patients and healthcare providers on safe, effective drug use.', 'Filling prescriptions, checking drug interactions, counseling patients, managing inventory.', 'BS Pharmacy + Licensure Exam', 15, 75, 15, 45, 25, 70),
('Midwife', 'Healthcare & Medical', 'Provides care to women during pregnancy, childbirth, and postpartum recovery.', 'Prenatal checkups, assisting deliveries, postpartum care, health education.', 'BS Midwifery + Licensure Exam', 35, 50, 15, 90, 25, 35),

-- Arts, Design & Media
('Photographer / Videographer', 'Arts, Design & Media', 'Captures and edits photos or videos for events, media, and creative projects.', 'Shooting photos/video, editing footage, managing equipment, client consultations.', 'BA Multimedia Arts / Fine Arts, or portfolio-based TVET training', 30, 20, 90, 30, 40, 25),
('Fashion Designer', 'Arts, Design & Media', 'Designs clothing and accessories, from concept sketches to finished garments.', 'Sketching designs, selecting fabrics, pattern-making, overseeing production.', 'BS Fashion Design / Multimedia Arts', 25, 20, 95, 25, 45, 25),
('Interior Designer', 'Arts, Design & Media', 'Plans and designs functional, aesthetically pleasing interior spaces.', 'Space planning, material selection, client presentations, coordinating contractors.', 'BS Interior Design', 30, 35, 85, 35, 40, 40),
('Film/Video Editor', 'Arts, Design & Media', 'Assembles and edits raw footage into a polished final video product.', 'Cutting footage, adding effects/sound, color grading, collaborating with directors.', 'BA Multimedia Arts / Film', 20, 35, 85, 25, 25, 40),

-- Hospitality & Culinary Arts
('Hotel & Restaurant Manager', 'Hospitality & Culinary Arts', 'Oversees daily operations of a hotel or restaurant, ensuring quality service and profitability.', 'Staff supervision, budgeting, customer service, coordinating operations.', 'BS Hotel & Restaurant Management / Tourism Management', 25, 25, 30, 55, 80, 50),
('Baker / Pastry Chef', 'Hospitality & Culinary Arts', 'Prepares baked goods and desserts for bakeries, restaurants, or hotels.', 'Mixing and baking recipes, decorating pastries, managing inventory, food safety.', 'BS Culinary Arts / TVET Baking & Pastry Certificate', 60, 25, 65, 30, 30, 35),
('Tour Guide / Travel Consultant', 'Hospitality & Culinary Arts', 'Plans itineraries and guides travelers, sharing knowledge of destinations and culture.', 'Leading tours, booking arrangements, answering traveler questions, handling logistics.', 'BS Tourism Management', 30, 40, 35, 70, 60, 30),
('Flight Attendant', 'Hospitality & Culinary Arts', 'Ensures passenger safety and comfort aboard commercial flights.', 'Safety briefings, assisting passengers, serving meals, handling emergencies.', 'BS Tourism Management / Hospitality Management + airline training', 30, 25, 25, 80, 45, 40),

-- Public Safety & Law Enforcement
('Firefighter', 'Public Safety & Law Enforcement', 'Responds to fires and emergencies to protect life and property.', 'Fire suppression, rescue operations, equipment maintenance, safety inspections.', 'BS Fire and Safety Technology / TVET training', 85, 30, 15, 60, 35, 35),
('Criminologist / Forensic Investigator', 'Public Safety & Law Enforcement', 'Analyzes crime scenes and evidence to support criminal investigations.', 'Evidence collection, lab analysis, report writing, courtroom testimony.', 'BS Criminology + Licensure Exam', 45, 75, 20, 40, 30, 55),
('Correctional Officer', 'Public Safety & Law Enforcement', 'Supervises and maintains order among inmates in correctional facilities.', 'Monitoring inmates, enforcing rules, security checks, incident reporting.', 'BS Criminology + Licensure Exam', 70, 25, 10, 55, 35, 45),

-- Agriculture & Environmental Science
('Veterinarian', 'Agriculture & Environmental Science', 'Diagnoses and treats illnesses and injuries in animals.', 'Examining animals, performing surgery, prescribing treatment, client education.', 'Doctor of Veterinary Medicine + Licensure Exam', 55, 80, 15, 65, 25, 40),
('Environmental Scientist', 'Agriculture & Environmental Science', 'Studies environmental conditions and develops solutions to protect natural resources.', 'Field sampling, data analysis, writing reports, recommending conservation measures.', 'BS Environmental Science / Biology', 45, 85, 20, 35, 25, 45),
('Fisheries Technician', 'Agriculture & Environmental Science', 'Manages and monitors fish production and aquatic resources.', 'Monitoring water quality, fish stock management, equipment maintenance, record-keeping.', 'BS Fisheries / Agricultural Technology', 80, 50, 15, 30, 25, 40),

-- Technology & IT
('Network/Systems Administrator', 'Technology & IT', 'Maintains an organization''s computer networks and systems infrastructure.', 'Configuring servers, monitoring network performance, troubleshooting, security updates.', 'BS Information Technology / Computer Science', 45, 70, 15, 30, 25, 65),
('Cybersecurity Analyst', 'Technology & IT', 'Protects computer systems and networks from digital threats and breaches.', 'Monitoring for threats, running security audits, responding to incidents, patching vulnerabilities.', 'BS Information Technology / Computer Science (Cybersecurity track)', 30, 85, 15, 25, 30, 60),

-- Business & Management
('Bank Teller / Financial Services Officer', 'Business & Management', 'Handles customer transactions and financial services at a bank branch.', 'Processing deposits/withdrawals, opening accounts, resolving customer concerns, balancing cash.', 'BS Accountancy / Business Administration / Finance', 15, 30, 15, 60, 45, 80),
('Real Estate Broker', 'Business & Management', 'Facilitates buying, selling, and leasing of properties for clients.', 'Property listings, client meetings, negotiations, closing transactions.', 'BS Real Estate Management + Licensure Exam', 20, 25, 25, 55, 85, 40),

-- Skilled Trades & Technical
('Welder', 'Skilled Trades & Technical', 'Joins metal parts using welding equipment for construction and manufacturing.', 'Reading blueprints, operating welding equipment, inspecting welds, maintaining tools.', 'TVET / Vocational Certificate, Welding NC I/II', 90, 30, 15, 15, 15, 35),
('Plumber', 'Skilled Trades & Technical', 'Installs and repairs piping systems for water, gas, and drainage.', 'Installing pipes, fixing leaks, reading blueprints, inspecting systems.', 'TVET / Vocational Certificate, Plumbing NC I/II', 90, 30, 10, 25, 20, 35),

-- Social Services & Education
('Social Worker', 'Social Services & Education', 'Supports individuals and families facing social, emotional, or economic challenges.', 'Case assessment, counseling, connecting clients to resources, documentation.', 'BS Social Work + Licensure Exam', 20, 40, 25, 95, 35, 40),
('Early Childhood Educator', 'Social Services & Education', 'Teaches and nurtures young children''s early learning and development.', 'Lesson planning, classroom activities, monitoring development, parent communication.', 'BS Early Childhood Education', 20, 35, 55, 90, 30, 40),

-- Engineering, Architecture & Construction
('Electrical Engineer', 'Engineering, Architecture & Construction', 'Designs and oversees electrical systems for buildings, power, and infrastructure.', 'Circuit design, system testing, project oversight, compliance checks.', 'BS Electrical Engineering + Licensure Exam', 65, 80, 20, 25, 35, 55),
('Industrial Engineer', 'Engineering, Architecture & Construction', 'Optimizes processes, systems, and resources for efficient production and operations.', 'Process analysis, workflow design, quality control, efficiency reporting.', 'BS Industrial Engineering + Licensure Exam', 45, 75, 20, 35, 45, 65);
