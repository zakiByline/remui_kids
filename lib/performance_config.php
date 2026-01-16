<?php
/**
 * Performance Configuration for School Admin Pages
 */

defined('MOODLE_INTERNAL') || die();

// Performance settings
define('REMUI_KIDS_CACHE_DURATION', 300); // 5 minutes
define('REMUI_KIDS_ENABLE_QUERY_CACHE', true);
define('REMUI_KIDS_DISABLE_DEBUG_LOGS', true); // Disable excessive logging in production
define('REMUI_KIDS_LAZY_LOAD_IMAGES', true);
define('REMUI_KIDS_PAGINATION_LIMIT', 50); // Items per page

/**
 * Check if debug logging is enabled
 */
function remui_kids_should_log() {
    global $CFG;
    return !REMUI_KIDS_DISABLE_DEBUG_LOGS || (isset($CFG->debugdisplay) && $CFG->debugdisplay);
}

/**
 * Conditional error logging
 */
function remui_kids_log($message) {
    if (remui_kids_should_log()) {
        error_log($message);
    }
}

/**
 * Performance-optimized CSS loader
 */
function remui_kids_load_optimized_css() {
    return <<<'CSS'
<style>
/* Optimized CSS - Minified and consolidated */
.school-manager-sidebar{display:flex!important;position:fixed!important;top:55px!important;left:0!important;width:280px!important;height:calc(100vh - 55px)!important;background:linear-gradient(180deg,#2C3E50 0%,#34495E 100%)!important;z-index:100000!important;overflow-y:auto!important;font-family:'Inter',sans-serif!important;box-shadow:2px 0 15px rgba(0,0,0,.15)!important;flex-direction:column!important}
.sidebar-header{background:#d4edda;padding:1.25rem 1rem;text-align:center;color:#2c3e50}
.header-icon{width:60px;height:60px;margin:0 auto 15px;background:#e9ecef;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#6c757d;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.header-badge{background:#007bff;color:#fff;padding:6px 18px;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block}
.nav-link{display:flex;align-items:center;padding:.875rem 1.5rem;color:#495057;text-decoration:none;transition:background .2s,color .2s;border-left:3px solid transparent;font-weight:500;font-size:.85rem}
.nav-link:hover{background:#f8f9fa;color:#007cba;border-left-color:#007cba}
.nav-item.active .nav-link{background:#e3f2fd;color:#007cba;border-left-color:#007cba;font-weight:600}
</style>
CSS;
}

/**
 * Lazy load images script
 */
function remui_kids_lazy_load_script() {
    if (!REMUI_KIDS_LAZY_LOAD_IMAGES) {
        return '';
    }
    
    return <<<'JS'
<script>
document.addEventListener("DOMContentLoaded",function(){var lazyImages=[].slice.call(document.querySelectorAll("img.lazy"));if("IntersectionObserver"in window){let lazyImageObserver=new IntersectionObserver(function(entries,observer){entries.forEach(function(entry){if(entry.isIntersecting){let lazyImage=entry.target;lazyImage.src=lazyImage.dataset.src;lazyImage.classList.remove("lazy");lazyImageObserver.unobserve(lazyImage)}})});lazyImages.forEach(function(lazyImage){lazyImageObserver.observe(lazyImage)})}else{lazyImages.forEach(function(lazyImage){lazyImage.src=lazyImage.dataset.src;lazyImage.classList.remove("lazy")})}});
</script>
JS;
}