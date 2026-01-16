/**
 * Auto-Translation System for theme_remui_kids
 * 
 * This script automatically translates hardcoded text on ALL pages
 * based on the user's selected language, WITHOUT modifying individual templates.
 * 
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 */

(function() {
    'use strict';

    // Get current language from multiple sources
    // 1. HTML lang attribute
    // 2. Body data-lang attribute  
    // 3. Moodle's M.cfg.language
    // 4. Cookie 'MoodleSession' won't help, but there might be a lang cookie
    var currentLang = document.documentElement.lang ||
        document.body.getAttribute('data-lang') ||
        (typeof M !== 'undefined' && M.cfg && M.cfg.language ? M.cfg.language : null) ||
        'en';

    // Normalize language code (handle cases like 'ar_sa' -> 'ar')
    if (currentLang && currentLang.indexOf('_') !== -1) {
        currentLang = currentLang.split('_')[0];
    }
    if (currentLang && currentLang.indexOf('-') !== -1) {
        currentLang = currentLang.split('-')[0];
    }

    // Log for debugging
    console.log('[RemUI Kids Auto-Translate] Detected language:', currentLang);

    // Translation dictionary - English to other languages
    var translations = {
        'ar': {
            // Navigation
            'Dashboard': 'لوحة التحكم',
            'My Courses': 'دوراتي',
            'Lessons': 'الدروس',
            'Activities': 'الأنشطة',
            'Achievements': 'الإنجازات',
            'Competencies': 'الكفاءات',
            'Grades': 'الدرجات',
            'Badges': 'الشارات',
            'Schedule': 'الجدول',
            'Settings': 'الإعدادات',
            'Calendar': 'التقويم',
            'Messages': 'الرسائل',
            'Communities': 'المجتمعات',
            'My Reports': 'تقاريري',
            'Assignments': 'الواجبات',
            'Profile': 'الملف الشخصي',
            'Profile Settings': 'إعدادات الملف الشخصي',
            'E-books': 'الكتب الإلكترونية',
            'Need Help?': 'تحتاج مساعدة؟',
            'Help': 'المساعدة',

            // Section Headers
            'DASHBOARD': 'لوحة التحكم',
            'TOOLS & RESOURCES': 'الأدوات والموارد',
            'QUICK ACTIONS': 'إجراءات سريعة',
            'OVERVIEW': 'نظرة عامة',
            'COURSES & PROGRAMS': 'الدورات والبرامج',
            'INSIGHTS': 'رؤى',

            // Dashboard
            'Welcome': 'مرحباً',
            'Welcome back': 'مرحباً بعودتك',
            'Progress': 'التقدم',
            'Your Progress': 'تقدمك',
            'Courses': 'الدورات',
            'Total Courses': 'إجمالي الدورات',
            'Enrolled Courses': 'الدورات المسجلة',
            'Active Courses': 'الدورات النشطة',
            'Completed Courses': 'الدورات المكتملة',
            'Activities Done': 'الأنشطة المنجزة',
            'Activities Completed': 'الأنشطة المكتملة',
            'Total Activities': 'إجمالي الأنشطة',
            'View all': 'عرض الكل',
            'View All': 'عرض الكل',
            'View Details': 'عرض التفاصيل',
            'View Course': 'عرض الدورة',
            'Continue Learning': 'استمر في التعلم',
            'Start Course': 'ابدأ الدورة',
            'Resume Course': 'استئناف الدورة',

            // Statistics
            'Subject Focus': 'تركيز المادة',
            'Course Overview': 'نظرة عامة على الدورة',
            'Completion Rate': 'معدل الإكمال',

            // Admin Dashboard
            'Admin Dashboard': 'لوحة تحكم الإدارة',
            'Management Dashboard': 'لوحة تحكم الإدارة',
            'School Admin Dashboard': 'لوحة تحكم مدير المدرسة',
            'School Manager Dashboard': 'لوحة تحكم مدير المدرسة',
            'Real-time insights and analytics for your educational platform': 'رؤى وتحليلات في الوقت الفعلي لمنصتك التعليمية',
            'Admin Tools': 'أدوات الإدارة',
            'Summary': 'ملخص',
            'Total Schools': 'إجمالي المدارس',
            'TOTAL SCHOOLS': 'إجمالي المدارس',
            'TOTAL COURSES': 'إجمالي الدورات',
            'Total Students': 'إجمالي الطلاب',
            'TOTAL STUDENTS': 'إجمالي الطلاب',
            'AVG COURSE RATING': 'متوسط تقييم الدورة',
            'Avg Course Rating': 'متوسط تقييم الدورة',
            'Active': 'نشط',
            'Available': 'متاح',
            'Enrolled': 'مسجل',
            'Excellent': 'ممتاز',
            'Good': 'جيد',
            'Average': 'متوسط',

            // User Statistics
            'User Statistics': 'إحصائيات المستخدمين',
            'Total Users': 'إجمالي المستخدمين',
            'TOTAL USERS': 'إجمالي المستخدمين',
            'Teachers': 'المعلمون',
            'TEACHERS': 'المعلمون',
            'Students': 'الطلاب',
            'STUDENTS': 'الطلاب',
            'Admins': 'المسؤولون',
            'ADMINS': 'المسؤولون',
            'Active Users': 'المستخدمون النشطون',
            'ACTIVE USERS': 'المستخدمون النشطون',
            'New This Month': 'جديد هذا الشهر',
            'NEW THIS MONTH': 'جديد هذا الشهر',

            // Course Statistics
            'Course Statistics': 'إحصائيات الدورات',
            'Categories': 'الفئات',
            'Course Categories': 'فئات الدورات',
            'enrollments': 'تسجيلات',
            'courses': 'دورات',
            'completion': 'إكمال',
            'No categories found': 'لم يتم العثور على فئات',

            // Recent Activity
            'Recent Activity': 'النشاط الأخير',
            'Type': 'النوع',
            'User': 'المستخدم',
            'Item': 'العنصر',
            'Time': 'الوقت',
            'No recent activity to display.': 'لا يوجد نشاط حديث لعرضه.',

            // Student Enrollments
            'Student Enrollments': 'تسجيلات الطلاب',
            'Recent Student Enrollments': 'تسجيلات الطلاب الأخيرة',
            'Student': 'الطالب',
            'Course': 'الدورة',
            'Enrollment Date': 'تاريخ التسجيل',
            'Status': 'الحالة',
            'Completed': 'مكتمل',
            'Suspended': 'معلق',
            'No enrollments found.': 'لم يتم العثور على تسجيلات.',

            // Calendar
            'School Schedule Calendar': 'تقويم جدول المدرسة',
            'View all upcoming events, quizzes, assignments, and course schedules': 'عرض جميع الأحداث القادمة والاختبارات والواجبات وجداول الدورات',
            'Events': 'الأحداث',
            'Quizzes': 'الاختبارات',
            'Course Starts': 'بدايات الدورات',
            'Upcoming Events': 'الأحداث القادمة',
            'Location': 'الموقع',
            'Date': 'التاريخ',
            'Title': 'العنوان',
            'No upcoming events.': 'لا توجد أحداث قادمة.',

            // Management
            'Management': 'الإدارة',
            'Teacher Management': 'إدارة المعلمين',
            'Manage Teachers': 'إدارة المعلمين',
            'View, add, and manage all teachers in your school': 'عرض وإضافة وإدارة جميع المعلمين في مدرستك',
            'Student Management': 'إدارة الطلاب',
            'Manage Students': 'إدارة الطلاب',
            'Enroll new students and monitor their progress': 'تسجيل الطلاب الجدد ومراقبة تقدمهم',
            'Parent Management': 'إدارة أولياء الأمور',
            'Course Management': 'إدارة الدورات',
            'Manage Courses': 'إدارة الدورات',
            'Organise course catalog, content and assignments': 'تنظيم كتالوج الدورات والمحتوى والواجبات',
            'Enrollments': 'التسجيلات',
            'View Enrollments': 'عرض التسجيلات',
            'Track student enrollments across all courses': 'تتبع تسجيلات الطلاب عبر جميع الدورات',
            'Actions': 'الإجراءات',
            'Action': 'إجراء',
            'Bulk Download': 'تنزيل مجمع',
            'Export course resources and student data in bulk': 'تصدير موارد الدورة وبيانات الطلاب بشكل مجمع',
            'Bulk Upload User Images': 'تحميل مجمع لصور المستخدمين',
            'Upload profile images for multiple users at once': 'تحميل صور الملف الشخصي لعدة مستخدمين دفعة واحدة',

            // Reports
            'Reports': 'التقارير',
            'Course Reports': 'تقارير الدورات',
            'Teacher Report': 'تقرير المعلم',
            'Student Reports': 'تقارير الطلاب',
            'C Reports': 'تقارير C',

            // System
            'System': 'النظام',
            'Activity Log': 'سجل النشاط',

            // Common UI
            'Loading...': 'جارٍ التحميل...',
            'Error': 'خطأ',
            'Success': 'نجاح',
            'Save': 'حفظ',
            'Cancel': 'إلغاء',
            'Close': 'إغلاق',
            'Submit': 'إرسال',
            'Edit': 'تعديل',
            'Delete': 'حذف',
            'Search': 'بحث',
            'Filter': 'تصفية',
            'Refresh': 'تحديث',
            'No data available': 'لا توجد بيانات متاحة',
            'No results found': 'لم يتم العثور على نتائج',

            // Quick Actions
            'Access digital learning materials': 'الوصول إلى مواد التعلم الرقمية',
            'Scratch Editor': 'محرر سكراتش',
            'Create interactive stories and games': 'إنشاء قصص وألعاب تفاعلية',
            'Code Editor': 'محرر الأكواد',
            'Learn programming in 10+ languages': 'تعلم البرمجة بأكثر من 10 لغات',

            // Time
            'Today': 'اليوم',
            'Yesterday': 'أمس',
            'This Week': 'هذا الأسبوع',
            'This Month': 'هذا الشهر',
            'Just now': 'الآن',
            'Last updated': 'آخر تحديث',

            // Navigation Sidebar Categories
            'IOMAD Dashboard': 'لوحة تحكم IOMAD',
            'Site Administration': 'إدارة الموقع'
        }
    };

    /**
     * Translate a single text string
     */
    function translateText(text, lang) {
        if (!translations[lang]) return text;

        var trimmedText = text.trim();
        if (translations[lang][trimmedText]) {
            return text.replace(trimmedText, translations[lang][trimmedText]);
        }
        return text;
    }

    /**
     * Walk through all text nodes and translate them
     */
    function translateTextNodes(element, lang) {
        if (!translations[lang]) return;

        var walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT, {
                acceptNode: function(node) {
                    // Skip script and style elements
                    var parent = node.parentNode;
                    if (parent && (parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE' || parent.tagName === 'NOSCRIPT')) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    // Only accept nodes with actual text content
                    if (node.nodeValue && node.nodeValue.trim().length > 0) {
                        return NodeFilter.FILTER_ACCEPT;
                    }
                    return NodeFilter.FILTER_SKIP;
                }
            },
            false
        );

        var textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        textNodes.forEach(function(node) {
            var originalText = node.nodeValue;
            var translatedText = translateText(originalText, lang);
            if (translatedText !== originalText) {
                node.nodeValue = translatedText;
            }
        });
    }

    /**
     * Translate placeholder attributes
     */
    function translatePlaceholders(lang) {
        if (!translations[lang]) return;

        var elements = document.querySelectorAll('[placeholder]');
        elements.forEach(function(el) {
            var placeholder = el.getAttribute('placeholder');
            if (placeholder && translations[lang][placeholder.trim()]) {
                el.setAttribute('placeholder', translations[lang][placeholder.trim()]);
            }
        });
    }

    /**
     * Translate title attributes
     */
    function translateTitles(lang) {
        if (!translations[lang]) return;

        var elements = document.querySelectorAll('[title]');
        elements.forEach(function(el) {
            var title = el.getAttribute('title');
            if (title && translations[lang][title.trim()]) {
                el.setAttribute('title', translations[lang][title.trim()]);
            }
        });
    }

    /**
     * Translate aria-label attributes
     */
    function translateAriaLabels(lang) {
        if (!translations[lang]) return;

        var elements = document.querySelectorAll('[aria-label]');
        elements.forEach(function(el) {
            var label = el.getAttribute('aria-label');
            if (label && translations[lang][label.trim()]) {
                el.setAttribute('aria-label', translations[lang][label.trim()]);
            }
        });
    }

    /**
     * Set RTL direction for Arabic
     */
    function setDirection(lang) {
        if (lang === 'ar') {
            document.documentElement.setAttribute('dir', 'rtl');
            document.body.classList.add('dir-rtl');
        }
    }

    /**
     * Main translation function
     */
    function translatePage() {
        if (currentLang === 'en') return; // No translation needed for English
        if (!translations[currentLang]) return; // No translations for this language

        // Set direction
        setDirection(currentLang);

        // Translate all text nodes
        translateTextNodes(document.body, currentLang);

        // Translate attributes
        translatePlaceholders(currentLang);
        translateTitles(currentLang);
        translateAriaLabels(currentLang);
    }

    /**
     * Observe DOM changes and translate new content
     */
    function observeDOM() {
        if (currentLang === 'en' || !translations[currentLang]) return;

        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            translateTextNodes(node, currentLang);
                        }
                    });
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Run translation when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            translatePage();
            observeDOM();
        });
    } else {
        translatePage();
        observeDOM();
    }

    // Expose for debugging
    window.remuiKidsTranslate = {
        translate: translatePage,
        getCurrentLang: function() {
            return currentLang;
        },
        getTranslations: function() {
            return translations;
        }
    };

})();