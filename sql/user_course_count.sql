-- Get course count for a specific user (replace 2 with actual user ID)
-- This query counts courses that the user is enrolled in

SELECT COUNT(DISTINCT c.id) as total_courses
FROM mdl_course c 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1;

-- Alternative: Get course count for all users
SELECT 
    u.id as user_id,
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

-- Get detailed course information for a user
SELECT 
    c.id,
    c.fullname,
    c.shortname,
    c.summary,
    c.visible,
    ue.timecreated as enrolled_date
FROM mdl_course c 
JOIN mdl_enrol e ON c.id = e.courseid 
JOIN mdl_user_enrolments ue ON e.id = ue.enrolid 
WHERE ue.userid = 2 AND c.visible = 1 AND c.id > 1
ORDER BY c.fullname;
