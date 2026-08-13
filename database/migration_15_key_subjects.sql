-- CareerPath AI — Migration 15: Recommended school subjects per career
-- --------------------------------------------------------------------
-- The results page already tells a student how well their RIASEC profile
-- fits a career (match %) and, since migration 14/the profile-similarity
-- fix, which traits they'd need to grow into it. What was still missing:
-- something concrete to act on academically — which JHS/SHS subjects to
-- actually put effort into if they're serious about this career. This adds
-- a free-text `key_subjects` field per career (same editable pattern as
-- career_category), curated for the existing 46 careers using Philippine
-- K-12 subject/strand terminology (TVL tracks, ABM/STEM core subjects,
-- etc.), and left NULL for anything added later until a counselor fills it
-- in on careers.php / careers_manage.php.

USE careerpath_ai;

ALTER TABLE careers ADD COLUMN key_subjects VARCHAR(255) NULL AFTER educational_pathway;
ALTER TABLE pending_careers ADD COLUMN key_subjects VARCHAR(255) NULL AFTER career_category;

UPDATE careers SET key_subjects = CASE career_title
    WHEN 'Software Developer' THEN 'Computer Programming, Empowerment Technologies, Mathematics'
    WHEN 'Registered Nurse' THEN 'Biology, Chemistry, General Science, English'
    WHEN 'Accountant' THEN 'Mathematics, Business Math, ABM core subjects, English'
    WHEN 'Graphic Artist' THEN 'Arts, Media and Information Literacy, Computer/ICT electives'
    WHEN 'Mechanical/Automotive Technician' THEN 'TVL - Automotive Servicing, Physics, Mathematics'
    WHEN 'Sales & Marketing Manager' THEN 'Business Math, Principles of Marketing, English, ABM core subjects'
    WHEN 'Electrician' THEN 'TVL - Electrical Installation and Maintenance, Physics, Mathematics'
    WHEN 'Psychologist / Guidance Counselor' THEN 'Personal Development, Psychology electives, English, Social Science'
    WHEN 'Chef / Culinary Professional' THEN 'TVL - Cookery, Home Economics, Food Science/Chemistry'
    WHEN 'Civil Engineer' THEN 'Mathematics, Physics, Earth Science'
    WHEN 'Entrepreneur / Business Owner' THEN 'Entrepreneurship, Business Math, ABM core subjects'
    WHEN 'Web/UI Designer' THEN 'Computer/ICT electives, Media and Information Literacy, Empowerment Technologies'
    WHEN 'Data Analyst' THEN 'Mathematics, Statistics and Probability, Computer/ICT electives'
    WHEN 'Teacher (Secondary Education)' THEN 'English, Communication subjects, chosen specialization subject (Math/Science/etc.)'
    WHEN 'Police Officer / Law Enforcement' THEN 'Physical Education, Values Education, Social Science'
    WHEN 'Human Resources Officer' THEN 'Business Math, Organization and Management, English, Psychology electives'
    WHEN 'Architect' THEN 'Mathematics, Physics, Arts/Design electives'
    WHEN 'Agriculturist / Agricultural Technician' THEN 'TVL - Agri-Fishery Arts, Biology, Earth Science'
    WHEN 'Medical Technologist' THEN 'Biology, Chemistry, General Science'
    WHEN 'Physical Therapist' THEN 'Biology, Physical Education, General Science'
    WHEN 'Pharmacist' THEN 'Chemistry, Biology, Mathematics'
    WHEN 'Midwife' THEN 'Biology, General Science, Health/Home Economics'
    WHEN 'Photographer / Videographer' THEN 'Media and Information Literacy, Arts, Computer/ICT electives'
    WHEN 'Fashion Designer' THEN 'Arts, TVL - Dressmaking/Tailoring, Media and Information Literacy'
    WHEN 'Interior Designer' THEN 'Arts, Mathematics, TVL - Design electives'
    WHEN 'Film/Video Editor' THEN 'Media and Information Literacy, Computer/ICT electives, Arts'
    WHEN 'Hotel & Restaurant Manager' THEN 'TVL - Hotel and Restaurant Services, Business Math, English'
    WHEN 'Baker / Pastry Chef' THEN 'TVL - Bread and Pastry Production, Home Economics, Food Science/Chemistry'
    WHEN 'Tour Guide / Travel Consultant' THEN 'TVL - Tourism, English, Social Science/Geography'
    WHEN 'Flight Attendant' THEN 'English, Physical Education, TVL - Tourism'
    WHEN 'Firefighter' THEN 'Physical Education, Earth/Physical Science, TVL - safety-related electives'
    WHEN 'Criminologist / Forensic Investigator' THEN 'Social Science, Chemistry, Biology, English'
    WHEN 'Correctional Officer' THEN 'Values Education, Physical Education, Social Science'
    WHEN 'Veterinarian' THEN 'Biology, Chemistry, General Science'
    WHEN 'Environmental Scientist' THEN 'Biology, Earth Science, Chemistry'
    WHEN 'Fisheries Technician' THEN 'TVL - Agri-Fishery Arts, Biology, Earth Science'
    WHEN 'Network/Systems Administrator' THEN 'Computer/ICT electives, Mathematics, Empowerment Technologies'
    WHEN 'Cybersecurity Analyst' THEN 'Computer/ICT electives, Mathematics, Empowerment Technologies'
    WHEN 'Bank Teller / Financial Services Officer' THEN 'Mathematics, Business Math, ABM core subjects'
    WHEN 'Real Estate Broker' THEN 'Business Math, English, ABM core subjects'
    WHEN 'Welder' THEN 'TVL - Welding, Physics, Mathematics'
    WHEN 'Plumber' THEN 'TVL - Plumbing, Physics, Mathematics'
    WHEN 'Social Worker' THEN 'Social Science, Personal Development, English'
    WHEN 'Early Childhood Educator' THEN 'Child Development, English, Personal Development'
    WHEN 'Electrical Engineer' THEN 'Mathematics, Physics, Computer/ICT electives'
    WHEN 'Industrial Engineer' THEN 'Mathematics, Physics, Statistics and Probability'
    ELSE key_subjects
END;
