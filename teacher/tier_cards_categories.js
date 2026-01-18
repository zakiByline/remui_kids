// Tier Cards Categories Management
// Handles category display in expanded tier cards section

// Function to populate category cards in tier cards expanded section
function populateTierCardCategories() {
    const categoryCardsGrid = document.getElementById('categoryCardsGrid');
    if (!categoryCardsGrid) {
        console.error('categoryCardsGrid element not found');
        return;
    }
    
    categoryCardsGrid.innerHTML = '';
    
    if (window.mainCategoriesData && Array.isArray(window.mainCategoriesData) && window.mainCategoriesData.length > 0) {
        window.mainCategoriesData.forEach(function(category) {
            const categoryCard = document.createElement('div');
            categoryCard.className = 'category-card';
            categoryCard.setAttribute('data-category-id', category.id);
            categoryCard.setAttribute('data-category-name', category.name);
            // Store courses data as JSON string (similar to dashboard)
            if (category.courses && Array.isArray(category.courses)) {
                categoryCard.setAttribute('data-courses', JSON.stringify(category.courses));
            }
            
            // Determine icon and description based on category name
            let iconClass = 'fa-th';
            let description = '';
            const categoryNameLower = category.name.toLowerCase();
            
            if (categoryNameLower.includes('level 1')) {
                iconClass = 'fa-graduation-cap';
                description = 'Foundation skills and early learning concepts';
            } else if (categoryNameLower.includes('level 2')) {
                iconClass = 'fa-graduation-cap';
                description = 'Building on basics with new challenges';
            } else if (categoryNameLower.includes('level 3')) {
                iconClass = 'fa-graduation-cap';
                description = 'Advanced concepts and school readiness';
            }
            
            // Create icon
            const iconDiv = document.createElement('div');
            iconDiv.className = 'category-card-icon';
            const icon = document.createElement('i');
            icon.className = 'fa ' + iconClass;
            iconDiv.appendChild(icon);
            
            // Create content wrapper
            const contentDiv = document.createElement('div');
            contentDiv.className = 'category-card-content';
            
            const nameSpan = document.createElement('span');
            nameSpan.className = 'category-card-name';
            nameSpan.textContent = category.name;
            
            if (description) {
                const descSpan = document.createElement('span');
                descSpan.className = 'category-card-description';
                descSpan.textContent = description;
                contentDiv.appendChild(nameSpan);
                contentDiv.appendChild(descSpan);
            } else {
                contentDiv.appendChild(nameSpan);
            }
            
            // Create label
            const label = document.createElement('label');
            label.className = 'category-card-label';
            label.appendChild(iconDiv);
            label.appendChild(contentDiv);
            
            // Create checkbox
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'category-card-checkbox';
            checkbox.id = 'tier-category-' + category.id;
            checkbox.setAttribute('data-category-id', category.id);
            
            categoryCard.appendChild(checkbox);
            categoryCard.appendChild(label);
            
            // Function to handle checkbox state change
            function handleCheckboxChange() {
                if (checkbox.checked) {
                    categoryCard.classList.add('checked');
                } else {
                    categoryCard.classList.remove('checked');
                }
                
                // Sync with sidebar category filter
                const sidebarCheckbox = document.querySelector('#categoryFilters input[data-category-id="' + category.id + '"]');
                if (sidebarCheckbox) {
                    sidebarCheckbox.checked = checkbox.checked;
                    if (typeof toggleCategoryChildren === 'function') {
                        toggleCategoryChildren(sidebarCheckbox);
                    }
                }
                
                // Trigger category filter update
                if (typeof updateSectionsAndFoldersFilters === 'function') {
                    updateSectionsAndFoldersFilters();
                }
                if (typeof filterResources === 'function') {
                    filterResources();
                }
                
                // Render courses for selected categories
                renderSelectedCourses();
            }
            
            // Add change handler to checkbox
            checkbox.addEventListener('change', handleCheckboxChange);
            
            // Add click handler to card (excluding checkbox)
            categoryCard.addEventListener('click', function(e) {
                // Don't trigger if clicking directly on checkbox
                if (e.target === checkbox || e.target.closest('.category-card-checkbox')) {
                    return;
                }
                e.stopPropagation();
                checkbox.checked = !checkbox.checked;
                handleCheckboxChange();
            });
            
            categoryCardsGrid.appendChild(categoryCard);
        });
        
        // Sync tier card category states with sidebar on load
        setTimeout(function() {
            document.querySelectorAll('#categoryFilters input[type="checkbox"][data-category-id]').forEach(function(sidebarCheckbox) {
                const categoryId = sidebarCheckbox.getAttribute('data-category-id');
                const tierCard = document.querySelector('#categoryCardsGrid .category-card[data-category-id="' + categoryId + '"]');
                if (tierCard && sidebarCheckbox.checked) {
                    tierCard.classList.add('checked');
                    const tierCheckbox = tierCard.querySelector('.category-card-checkbox');
                    if (tierCheckbox) {
                        tierCheckbox.checked = true;
                    }
                }
            });
            // Render courses for any pre-selected categories
            renderSelectedCourses();
        }, 200);
    }
}

// Function to render courses for selected categories (similar to dashboard)
function renderSelectedCourses() {
    const courseItemsContainer = document.getElementById('tierCoursesItems');
    const coursesContainer = document.getElementById('tierCoursesContainer');
    
    if (!courseItemsContainer || !coursesContainer) {
        return;
    }
    
    // Collect all courses from all selected categories
    const allCourses = [];
    const categoryCheckboxes = document.querySelectorAll('#categoryCardsGrid .category-card-checkbox');
    
    categoryCheckboxes.forEach(function(checkbox) {
        if (checkbox.checked) {
            const categoryCard = checkbox.closest('.category-card');
            const coursesData = categoryCard ? categoryCard.getAttribute('data-courses') : '';
            let courses = [];
            try {
                courses = coursesData ? JSON.parse(coursesData) : [];
            } catch (error) {
                courses = [];
            }
            if (courses.length > 0) {
                allCourses.push(...courses);
            }
        }
    });
    
    if (allCourses.length === 0) {
        courseItemsContainer.innerHTML = '<p class="resource-category-courses-empty">Select a category to preview its courses.</p>';
        coursesContainer.style.display = 'none';
        return;
    }
    
    // Deduplicate courses by name (case-insensitive) but collect all course IDs for each name
    const uniqueCoursesMap = new Map(); // courseNameLower -> { course, allIds: [...] }
    allCourses.forEach(function(course) {
        const courseNameLower = course.name.toLowerCase().trim();
        if (!uniqueCoursesMap.has(courseNameLower)) {
            // First time seeing this course name - store the course and its ID
            uniqueCoursesMap.set(courseNameLower, {
                course: course,
                allIds: [course.id]
            });
        } else {
            // Already seen this course name - add this ID to the list
            const existing = uniqueCoursesMap.get(courseNameLower);
            if (!existing.allIds.includes(course.id)) {
                existing.allIds.push(course.id);
            }
            // If this course has a higher ID, prefer it as the representative course
            if (existing.course.id < course.id) {
                existing.course = course;
            }
        }
    });
    
    // Convert map to array of course objects with allIds attached
    const uniqueCourses = Array.from(uniqueCoursesMap.values()).map(function(item) {
        return {
            ...item.course,
            allCourseIds: item.allIds // Store all course IDs that have this name
        };
    });
    
    courseItemsContainer.innerHTML = '';
    coursesContainer.style.display = 'block';
    
    // Create a single grid for all unique courses (no category grouping)
    const coursesGrid = document.createElement('div');
    coursesGrid.className = 'category-cards-grid course-cards-grid';
    
    uniqueCourses.forEach(function(course) {
            const courseCard = document.createElement('div');
            courseCard.className = 'category-card course-card';
            courseCard.setAttribute('data-course-id', course.id);
            courseCard.setAttribute('data-course-name', course.name);
            // Store all course IDs with the same name for syncing
            if (course.allCourseIds && course.allCourseIds.length > 0) {
                courseCard.setAttribute('data-all-course-ids', JSON.stringify(course.allCourseIds));
            } else {
                courseCard.setAttribute('data-all-course-ids', JSON.stringify([course.id]));
            }
            
            // Determine icon and description based on course name
            let iconClass = 'fa-book';
            let description = '';
            const courseNameLower = course.name.toLowerCase();
            
            if (courseNameLower.includes('english') || courseNameLower.includes('language')) {
                iconClass = 'fa-book';
                description = 'Reading, writing, phonics, and language arts';
            } else if (courseNameLower.includes('math') || courseNameLower.includes('mathematics')) {
                iconClass = 'fa-calculator';
                description = 'Numbers, counting, shapes, and problem solving';
            } else if (courseNameLower.includes('science')) {
                iconClass = 'fa-flask';
                description = 'Nature, experiments, and exploring the world';
            }
            
            // Create icon
            const iconDiv = document.createElement('div');
            iconDiv.className = 'category-card-icon course-card-icon';
            const icon = document.createElement('i');
            icon.className = 'fa ' + iconClass;
            iconDiv.appendChild(icon);
            
            // Create content wrapper
            const contentDiv = document.createElement('div');
            contentDiv.className = 'category-card-content';
            
            const nameSpan = document.createElement('span');
            nameSpan.className = 'category-card-name';
            nameSpan.textContent = course.name;
            
            if (description) {
                const descSpan = document.createElement('span');
                descSpan.className = 'category-card-description';
                descSpan.textContent = description;
                contentDiv.appendChild(nameSpan);
                contentDiv.appendChild(descSpan);
            } else {
                contentDiv.appendChild(nameSpan);
            }
            
            // Create label
            const label = document.createElement('label');
            label.className = 'category-card-label';
            label.appendChild(iconDiv);
            label.appendChild(contentDiv);
            
            // Create checkbox
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'category-card-checkbox course-card-checkbox';
            checkbox.setAttribute('data-course-id', course.id);
            checkbox.id = 'tier-course-' + course.id;
            
            // Check if any course with this name is already selected in sidebar
            const allCourseIds = courseCard.getAttribute('data-all-course-ids');
            let idsToCheck = [course.id];
            if (allCourseIds) {
                try {
                    idsToCheck = JSON.parse(allCourseIds);
                } catch (e) {
                    idsToCheck = [course.id];
                }
            }
            
            // If any of the courses with this name are checked in sidebar, check this card
            let isChecked = false;
            idsToCheck.forEach(function(courseId) {
                const sidebarCheckbox = document.querySelector('#categoryFilters input[data-course-id="' + courseId + '"]');
                if (sidebarCheckbox && sidebarCheckbox.checked) {
                    isChecked = true;
                }
            });
            
            if (isChecked) {
                checkbox.checked = true;
                courseCard.classList.add('checked');
            }
            
            courseCard.appendChild(checkbox);
            courseCard.appendChild(label);
            
            // Add click handler for course card
            courseCard.addEventListener('click', function(e) {
                e.stopPropagation();
                checkbox.checked = !checkbox.checked;
                if (checkbox.checked) {
                    courseCard.classList.add('checked');
                } else {
                    courseCard.classList.remove('checked');
                }
                
                // Sync with sidebar course filter - check all checkboxes with the same course name
                // This ensures that if "English" exists in multiple categories with different IDs, all are synced
                const allCourseIds = courseCard.getAttribute('data-all-course-ids');
                let idsToSync = [course.id];
                if (allCourseIds) {
                    try {
                        idsToSync = JSON.parse(allCourseIds);
                    } catch (e) {
                        idsToSync = [course.id];
                    }
                }
                
                // Sync all checkboxes with any of the course IDs that have this name
                idsToSync.forEach(function(courseId) {
                    document.querySelectorAll('#categoryFilters input[data-course-id="' + courseId + '"]').forEach(function(sidebarCheckbox) {
                        sidebarCheckbox.checked = checkbox.checked;
                    });
                });
                
                // Trigger filter update
                if (typeof updateSectionsAndFoldersFilters === 'function') {
                    updateSectionsAndFoldersFilters();
                }
                if (typeof filterResources === 'function') {
                    filterResources();
                }
            });
            
            coursesGrid.appendChild(courseCard);
    });
    
    // Append the single grid to container (no category headers/separators)
    courseItemsContainer.appendChild(coursesGrid);
}

// Sync sidebar category changes to tier card categories
document.addEventListener('change', function(e) {
    if (e.target && e.target.matches('#categoryFilters input[type="checkbox"][data-category-id]')) {
        const categoryId = e.target.getAttribute('data-category-id');
        const tierCard = document.querySelector('#categoryCardsGrid .category-card[data-category-id="' + categoryId + '"]');
        if (tierCard) {
            if (e.target.checked) {
                tierCard.classList.add('checked');
                const tierCheckbox = tierCard.querySelector('.category-card-checkbox');
                if (tierCheckbox) {
                    tierCheckbox.checked = true;
                }
            } else {
                tierCard.classList.remove('checked');
                const tierCheckbox = tierCard.querySelector('.category-card-checkbox');
                if (tierCheckbox) {
                    tierCheckbox.checked = false;
                }
            }
        }
        // Re-render courses when sidebar categories change
        renderSelectedCourses();
    }
});
