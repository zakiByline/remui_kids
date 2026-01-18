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
    
    // Collect categories with their courses
    const selectedCategories = [];
    const categoryCheckboxes = document.querySelectorAll('#categoryCardsGrid .category-card-checkbox');
    
    categoryCheckboxes.forEach(function(checkbox) {
        if (checkbox.checked) {
            const categoryCard = checkbox.closest('.category-card');
            const categoryName = categoryCard ? categoryCard.getAttribute('data-category-name') : '';
            const coursesData = categoryCard ? categoryCard.getAttribute('data-courses') : '';
            let courses = [];
            try {
                courses = coursesData ? JSON.parse(coursesData) : [];
            } catch (error) {
                courses = [];
            }
            if (courses.length > 0) {
                selectedCategories.push({ name: categoryName, courses: courses });
            }
        }
    });
    
    if (selectedCategories.length === 0) {
        courseItemsContainer.innerHTML = '<p class="resource-category-courses-empty">Select a category to preview its courses.</p>';
        coursesContainer.style.display = 'none';
        return;
    }
    
    courseItemsContainer.innerHTML = '';
    coursesContainer.style.display = 'block';
    
    // Create a section for each selected category
    selectedCategories.forEach(function(category) {
        // Category header
        const categoryHeader = document.createElement('div');
        categoryHeader.className = 'course-category-header';
        categoryHeader.textContent = category.name;
        courseItemsContainer.appendChild(categoryHeader);
        
        // Separator line
        const separator = document.createElement('div');
        separator.className = 'course-category-separator';
        courseItemsContainer.appendChild(separator);
        
        // Courses grid for this category
        const coursesGrid = document.createElement('div');
        coursesGrid.className = 'category-cards-grid course-cards-grid';
        
        category.courses.forEach(function(course) {
            const courseCard = document.createElement('div');
            courseCard.className = 'category-card course-card';
            courseCard.setAttribute('data-course-id', course.id);
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'category-card-checkbox course-card-checkbox';
            checkbox.setAttribute('data-course-id', course.id);
            checkbox.id = 'tier-course-' + course.id + '-' + category.name.replace(/\s+/g, '-');
            
            const label = document.createElement('label');
            label.className = 'category-card-label';
            label.setAttribute('for', checkbox.id);
            
            const nameSpan = document.createElement('span');
            nameSpan.className = 'category-card-name';
            nameSpan.textContent = course.name;
            
            label.appendChild(nameSpan);
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
                
                // Sync with sidebar course filter
                const sidebarCheckbox = document.querySelector('#categoryFilters input[data-course-id="' + course.id + '"]');
                if (sidebarCheckbox) {
                    sidebarCheckbox.checked = checkbox.checked;
                }
                
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
        
        courseItemsContainer.appendChild(coursesGrid);
    });
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
