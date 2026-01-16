# Competency Maps - Admin Page

## Overview
The Competency Maps page provides administrators with a comprehensive view of all competency frameworks, competencies, and student proficiency across the entire platform.

## Location
- **URL**: `/theme/remui_kids/admin/competency_maps.php`
- **Navigation**: Admin Sidebar → Insights → Competency Maps

## Features

### 1. Statistics Dashboard
The page displays four key metrics at the top:
- **Competency Frameworks**: Total number of frameworks in the system
- **Total Competencies**: Total number of competencies across all frameworks
- **Course Links**: Total number of course-competency associations
- **Proficient Students**: Number of students who have achieved proficiency in competencies

### 2. Filter & Search Controls
- **Search Competencies**: Real-time search by competency name or ID
- **Framework Filter**: Filter by specific competency framework
- **Apply/Reset**: Apply or reset filters

### 3. Competency Framework Cards
Each framework is displayed as an expandable card showing:
- Framework name and ID number
- Statistics:
  - Number of competencies in the framework
  - Number of course links
  - Number of proficient students
- Expandable/collapsible view

### 4. Competency Tree View
Within each framework:
- **Hierarchical Display**: Competencies are shown in a tree structure respecting parent-child relationships
- **For Each Competency**:
  - Competency name
  - Number of courses using this competency
  - Number of activities linked to this competency
  - Number of students proficient in this competency
  - Actions:
    - **Edit**: Opens the Moodle competency editor
    - **View**: Opens the detailed competency view

### 5. Interactive Features
- **Expand/Collapse**: Click framework headers to expand/collapse competency trees
- **Nested Navigation**: Click competency rows with children to expand/collapse sub-competencies
- **Real-time Search**: Search updates results as you type
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Database Tables Used

The page queries the following Moodle competency tables:
- `mdl_competency_framework`: Framework definitions
- `mdl_competency`: Individual competencies
- `mdl_competency_coursecomp`: Course-competency associations
- `mdl_competency_modulecomp`: Module-competency associations (if available)
- `mdl_competency_usercompcourse`: Student competency proficiency in courses

## Access Requirements

- **Capability Required**: `moodle/site:config` (Site administrator)
- Users without this capability will be denied access

## UI Design

- **Color Scheme**: Modern gradient design with purple/blue accents
- **Sidebar**: Consistent with other admin pages in the theme
- **Cards**: Hoverable cards with smooth transitions
- **Icons**: Font Awesome icons for visual clarity
- **Typography**: Inter font family for clean, modern look

## Integration

The page is integrated into the admin sidebar navigation across all admin pages:
- Admin Dashboard
- Teachers List
- Courses & Programs
- Schools Management
- User Management
- And all other admin pages

## Technical Details

- **Framework**: Moodle 3.x+
- **Theme**: remui_kids
- **Language**: PHP 7.x+
- **Styling**: Inline CSS (can be moved to SCSS if needed)
- **JavaScript**: Vanilla JS for interactions
- **Dependencies**: Moodle core, Font Awesome

## Future Enhancements

Potential improvements for future versions:
1. Export competency data to CSV/Excel
2. Bulk competency management tools
3. Visual competency map graphs/charts
4. Competency proficiency trends over time
5. Student competency reports by cohort/school
6. Competency gap analysis
7. Integration with learning paths
8. Competency-based certification tracking

## Support

For issues or questions, contact the development team or refer to the theme documentation.

---
**Created**: 2025-10-11
**Version**: 1.0
**Package**: theme_remui_kids
**Copyright**: 2025 Kodeit
**License**: GNU GPL v3 or later








