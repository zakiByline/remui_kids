-- Get course count for a specific user
-- Replace '2' with the user ID you want to check

-- Method 1: Simple count
SELECT COUNT(DISTINCT c.id) as total_courses
FROM mdl_course c 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1;

-- Method 2: Detailed breakdown
SELECT 
    u.id as user_id,
    u.firstname,
    u.lastname,
    COUNT(DISTINCT c.id) as total_courses,
    GROUP_CONCAT(DISTINCT c.fullname SEPARATOR ', ') as course_names
FROM mdl_user u
LEFT JOIN mdl_user_enrolments ue ON u.id = ue.userid
LEFT JOIN mdl_enrol e ON ue.enrolid = e.id
LEFT JOIN mdl_course c ON e.courseid = c.id AND c.visible = 1 AND c.id > 1
WHERE u.id = 2 AND u.deleted = 0
GROUP BY u.id, u.firstname, u.lastname;

-- Method 3: All users with their course counts
SELECT 
    u.id,
    u.firstname,
    u.lastname,
    COUNT(DISTINCT c.id) as total_courses
FROM mdl_user u
LEFT JOIN mdl_user_enrolments ue ON u.id = ue.userid
LEFT JOIN mdl_enrol e ON ue.enrolid = e.id
LEFT JOIN mdl_course c ON e.courseid = c.id AND c.visible = 1 AND c.id > 1
WHERE u.deleted = 0 AND u.id > 1
GROUP BY u.id, u.firstname, u.lastname
ORDER BY total_courses DESC;

-- Method 4: Check if user has any enrollments at all
SELECT 
    u.id,
    u.firstname,
    u.lastname,
    COUNT(ue.id) as total_enrollments,
    COUNT(DISTINCT c.id) as total_courses
FROM mdl_user u
LEFT JOIN mdl_user_enrolments ue ON u.id = ue.userid
LEFT JOIN mdl_enrol e ON ue.enrolid = e.id
LEFT JOIN mdl_course c ON e.courseid = c.id
WHERE u.id = 2
GROUP BY u.id, u.firstname, u.lastname;
