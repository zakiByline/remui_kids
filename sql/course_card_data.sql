-- Dashboard Course Card Data Queries
-- Run these in phpMyAdmin to get the exact data shown in your dashboard cards

-- 1. GET COURSE COUNT FOR A SPECIFIC USER
-- Replace '2' with the user ID you want to check
SELECT COUNT(DISTINCT c.id) as total_courses
FROM mdl_course c 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1;

-- 2. GET LESSONS COMPLETED FOR A USER
SELECT COUNT(DISTINCT cmc.coursemoduleid) as lessons_completed
FROM mdl_course_modules_completion cmc 
JOIN mdl_course_modules cm ON cmc.coursemoduleid = cm.id 
JOIN mdl_course c ON cm.course = c.id 
WHERE cmc.userid = 2 AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1;

-- 3. GET ACTIVITIES COMPLETED FOR A USER
SELECT COUNT(*) as activities_completed
FROM mdl_course_modules_completion cmc 
JOIN mdl_course_modules cm ON cmc.coursemoduleid = cm.id 
JOIN mdl_course c ON cm.course = c.id 
WHERE cmc.userid = 2 AND cmc.completionstate > 0 AND c.visible = 1 AND c.id > 1;

-- 4. GET OVERALL PROGRESS FOR A USER
SELECT 
    COUNT(*) as total_activities,
    (SELECT COUNT(*) 
     FROM mdl_course_modules_completion cmc2 
     JOIN mdl_course_modules cm2 ON cmc2.coursemoduleid = cm2.id 
     JOIN mdl_course c2 ON cm2.course = c2.id 
     WHERE cmc2.userid = 2 AND cmc2.completionstate > 0 AND c2.visible = 1 AND c2.id > 1) as completed_activities,
    ROUND(
        (SELECT COUNT(*) 
         FROM mdl_course_modules_completion cmc3 
         JOIN mdl_course_modules cm3 ON cmc3.coursemoduleid = cm3.id 
         JOIN mdl_course c3 ON cm3.course = c3.id 
         WHERE cmc3.userid = 2 AND cmc3.completionstate > 0 AND c3.visible = 1 AND c3.id > 1) * 100.0 / 
        COUNT(*), 2
    ) as progress_percentage
FROM mdl_course_modules cm 
JOIN mdl_course c ON cm.course = c.id 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1 AND cm.completion > 0;

-- 5. GET ALL USERS AND THEIR COURSE COUNTS
SELECT 
    u.id,
    u.firstname,
    u.lastname,
    u.username,
    COUNT(DISTINCT c.id) as total_courses
FROM mdl_user u
LEFT JOIN mdl_user_enrolments ue ON u.id = ue.userid
LEFT JOIN mdl_enrol e ON ue.enrolid = e.id
LEFT JOIN mdl_course c ON e.courseid = c.id AND c.visible = 1 AND c.id > 1
WHERE u.deleted = 0 AND u.id > 1
GROUP BY u.id, u.firstname, u.lastname, u.username
ORDER BY total_courses DESC;

-- 6. GET DETAILED COURSE LIST FOR A USER
SELECT 
    c.id,
    c.fullname,
    c.shortname,
    c.summary,
    ue.timecreated as enrolled_date,
    COUNT(DISTINCT cm.id) as total_activities,
    COUNT(DISTINCT CASE WHEN cmc.completionstate > 0 THEN cmc.coursemoduleid END) as completed_activities
FROM mdl_course c 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
LEFT JOIN mdl_course_modules cm ON c.id = cm.course AND cm.completion > 0
LEFT JOIN mdl_course_modules_completion cmc ON cm.id = cmc.coursemoduleid AND cmc.userid = 2
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1
GROUP BY c.id, c.fullname, c.shortname, c.summary, ue.timecreated
ORDER BY c.fullname;

-- INSTRUCTIONS:
-- 1. Replace '2' with the user ID you want to check
-- 2. Run these queries in phpMyAdmin
-- 3. The results will show the exact data that appears in your dashboard cards
-- 4. Query #1 shows the "Courses" card value
-- 5. Query #2 shows the "Lessons Done" card value  
-- 6. Query #3 shows the "Activities Done" card value
-- 7. Query #4 shows the "Overall Progress" card value
