# 📊 Super Admin Reporting Dashboard

## Overview

The Super Admin Reporting Dashboard is a comprehensive analytics and reporting system designed for site administrators to gain deep insights into the entire learning management system. It provides real-time data visualization, detailed reports, and AI-powered insights across all schools, teachers, students, courses, and activities.

## 🎯 Features

### 1. **Global Filters**
- **School Selector**: Filter data by specific school/company or view all
- **Date Range**: Choose from preset ranges (Week, Month, Quarter, Year) or custom dates
- **Refresh**: Real-time data refresh capability
- **Export**: Export reports in CSV, Excel, or PDF formats

### 2. **Dashboard Tabs**

#### 🏫 Overview Tab
- **Key Metrics Cards**:
  - Total Schools
  - Total Teachers
  - Total Students
  - Average Course Completion Rate
  - Total Courses
  - Active Users
  
- **Interactive Charts**:
  - System Activity Trend (Line chart showing logins and actions over time)
  - Course Completion by School (Bar chart)
  - Active Users by Role (Pie chart)
  
- **Recent Activity Feed**: Real-time feed of recent system activities
- **AI Summary Card**: Intelligent insights about overall system health

#### 👨‍🏫 Teacher Report Tab
- **Statistics**:
  - Total Teachers
  - Average Courses per Teacher
  - Average Student Grade across all teachers
  - Average Activities Created
  
- **Detailed Table**:
  - Teacher name and email
  - Number of courses teaching
  - Average student grades
  - Activities created in selected period
  - Last login information

#### 🎓 Student Progress Tab
- **Statistics**:
  - Total Students
  - Average Enrolled Courses
  - Average Grade
  - Average Completion Rate
  - Active Student Count
  
- **Detailed Table**:
  - Student name and email
  - Enrolled courses count
  - Average grade
  - Completion rate with progress bar
  - Status (Active/Inactive)
  - Last access time

#### 📚 Course & Activity Report Tab
- **Statistics**:
  - Total Courses
  - Average Enrollment per Course
  - Average Completion Rate
  - Average Grade
  
- **Detailed Table**:
  - Course name and short name
  - Enrolled students
  - Completion percentage
  - Average grade
  - Last update date

#### 🧩 Competency & Skill Report Tab
- Placeholder for competency tracking
- Ready for integration with Moodle's competency framework

#### 📝 Grade Distribution Report Tab
- **Interactive Chart**: Bar chart showing grade distribution across all courses
- Visual representation of grade ranges (0-10%, 10-20%, etc.)
- Helps identify overall academic performance patterns

#### 💬 Activity & Engagement Tab
- Detailed feed of recent system activities
- Shows user actions, targets, and timestamps
- Helps track system usage patterns

#### 📅 Attendance & Logins Tab
- Placeholder for attendance tracking
- Ready for custom attendance module integration

#### ⚙️ Audit & System Logs Tab
- Comprehensive system audit logs
- Shows user actions, events, and timestamps
- Essential for compliance and security monitoring

#### 🤖 AI Insights Tab
- AI-powered analysis of system data
- Automatic insights generation based on current metrics
- Actionable recommendations for system improvement
- Key statistics summary

### 3. **Export Functionality**

The dashboard supports exporting data in multiple formats:

- **CSV**: Comma-separated values for data analysis
- **Excel**: Spreadsheet format (.xls)
- **PDF/HTML**: Formatted report for presentation

Each tab's data can be exported independently with current filter settings applied.

## 📁 File Structure

```
iomad/theme/remui_kids/admin/superreports/
├── index.php           # Main dashboard page
├── lib.php             # Data aggregation functions
├── ajax_data.php       # AJAX endpoint for dynamic data loading
├── export.php          # Export functionality
├── script.js           # Interactive JavaScript (Chart.js integration)
├── style.css           # Comprehensive styling
└── README.md          # This documentation
```

## 🚀 Installation & Usage

### Prerequisites
- Moodle instance with IOMAD (for company/school support)
- Site administrator access
- Modern web browser with JavaScript enabled

### Access
1. Log in as site administrator
2. Navigate to: `/theme/remui_kids/admin/superreports/index.php`
3. The dashboard will automatically load with default filters

### Usage Tips

1. **Filtering Data**:
   - Use the school dropdown to focus on specific institutions
   - Select date ranges to analyze trends over time
   - Use "Custom Range" for specific time periods

2. **Navigating Tabs**:
   - Click any tab to view specific reports
   - Data loads via AJAX for fast performance
   - Previous tab data is cached for quick switching

3. **Exporting Reports**:
   - Click the "Export" button
   - Choose your preferred format
   - File will download with current filters applied

4. **Refreshing Data**:
   - Click the "Refresh" button to reload current tab
   - Useful for real-time monitoring

## 🎨 Design Features

- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Modern UI**: Beautiful gradient cards and smooth animations
- **Interactive Charts**: Powered by Chart.js for dynamic visualizations
- **Color-Coded Status**: Visual indicators for active/inactive states
- **Progress Bars**: Visual representation of completion rates

## 🔧 Technical Details

### Technologies Used
- **Backend**: PHP (Moodle API)
- **Frontend**: Vanilla JavaScript
- **Charts**: Chart.js v4.4.0
- **Styling**: Custom CSS with flexbox and grid layouts
- **Icons**: Font Awesome

### Performance Optimizations
- AJAX-based tab loading (no full page reloads)
- Data caching for previously loaded tabs
- Efficient database queries with proper indexing
- Chart instance management to prevent memory leaks

### Security
- Requires site admin authentication
- Session key validation on all AJAX requests
- SQL injection prevention using Moodle's database API
- XSS protection with proper output escaping

## 📊 Data Sources

The dashboard aggregates data from multiple Moodle tables:
- `{company}` - School/company information
- `{user}` - User accounts
- `{role_assignments}` - User roles
- `{course}` - Course information
- `{course_completions}` - Completion tracking
- `{grade_grades}` & `{grade_items}` - Grading data
- `{logstore_standard_log}` - Activity logs
- `{enrol}` & `{user_enrolments}` - Enrollment data

## 🔮 Future Enhancements

Potential areas for expansion:
1. Real-time notifications for critical events
2. Advanced competency tracking integration
3. Predictive analytics using machine learning
4. Custom report builder
5. Scheduled report emails
6. More detailed attendance tracking
7. Integration with external BI tools
8. Mobile app companion

## 🐛 Troubleshooting

### Common Issues

1. **Charts not displaying**:
   - Check browser console for JavaScript errors
   - Ensure Chart.js CDN is accessible
   - Verify internet connection

2. **No data showing**:
   - Confirm you have data in your Moodle instance
   - Check date range filters
   - Verify school filter selection

3. **Export not working**:
   - Check PHP session is active
   - Verify write permissions
   - Check browser popup blocker settings

4. **Slow loading**:
   - Consider narrowing date range
   - Filter by specific school
   - Check database query performance

## 📝 Customization

### Adding Custom Metrics

To add custom metrics to any tab:

1. Add data aggregation function in `lib.php`
2. Update `ajax_data.php` to return new data
3. Modify `script.js` to render the new data
4. Update `style.css` if needed for styling

### Styling Changes

All styles are contained in `style.css`. Key CSS classes:
- `.stat-card` - Metric cards
- `.chart-card` - Chart containers
- `.data-table` - Data tables
- `.filter-group` - Filter controls

## 📞 Support

For issues or questions:
1. Check Moodle logs for errors
2. Review browser console for JavaScript errors
3. Verify database connectivity
4. Contact system administrator

## 📄 License

This dashboard is part of theme_remui_kids and follows the same licensing:
- GNU GPL v3 or later
- Copyright 2025 Kodeit

## 🎉 Credits

- **Charts**: Chart.js (https://www.chartjs.org/)
- **Icons**: Font Awesome (https://fontawesome.com/)
- **Framework**: Moodle LMS (https://moodle.org/)

---

**Version**: 1.0.0  
**Last Updated**: October 2025  
**Developed by**: Kodeit

