define(['jquery', 'core/templates'], function($, Templates) {
    'use strict';

    const SELECTORS = {
        workspace: '[data-region="doubts-workspace"]',
        listPane: '[data-region="doubt-list"]',
        detailPane: '[data-region="doubt-detail"]',
        filters: '[data-filter]'
    };

    const ACTIONS = {
        reset: '[data-action="reset-filters"]',
        assignMe: '[data-action="assign-me"]',
        unassign: '[data-action="unassign"]',
        changeStatus: '[data-action="change-status"]',
        addFiles: '[data-action="add-files"]',
        clearFiles: '[data-action="clear-files"]'
    };

    let config;
    let root;
    let searchTimer;
    const state = {
        filters: {},
        selectedId: null,
        files: []
    };

const logError = function(error) {
    if (window.console && window.console.error) {
        window.console.error('Doubt management error:', error);
    }
};

    /**
     * Initialise module.
     * @param {Object} initialConfig
     */
    const init = function(initialConfig) {
        config = initialConfig || {};
        root = $(SELECTORS.workspace);

        if (!root.length) {
            return;
        }

        state.filters = $.extend({}, config.initialFilters || {});
        state.selectedId = config.initialDetailId || null;
        state.files = [];

        bindEvents();
        applyAttachmentPreviews();
    };

    const bindEvents = function() {
        root.on('click', ACTIONS.reset, function() {
            resetFilters();
        });

        root.on('change', SELECTORS.filters, function() {
            const filter = $(this).data('filter');
            const value = $(this).val();
            if (filter === 'search') {
                state.filters[filter] = value;
            } else {
                updateFilter(filter, value);
                fetchList();
            }
        });

        root.on('keyup', '[data-filter="search"]', function() {
            const value = $(this).val();
            state.filters.search = value;
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(fetchList, 350);
        });

        root.on('click', '.doubt-list-item', function() {
            const id = $(this).data('doubtid');
            if (!id || id === state.selectedId) {
                return;
            }
            state.selectedId = id;
            highlightSelection();
            fetchDetail(id);
        });

        root.on('submit', '[data-region="reply-form"]', function(e) {
            e.preventDefault();
            submitReply($(this));
        });

        root.on('click', ACTIONS.assignMe, function() {
            if (!state.selectedId) {
                return;
            }
            assign(state.selectedId, config.userid);
        });

        root.on('click', ACTIONS.unassign, function() {
            if (!state.selectedId) {
                return;
            }
            assign(state.selectedId, 0);
        });

        root.on('change', ACTIONS.changeStatus, function() {
            if (!state.selectedId) {
                return;
            }
            const status = $(this).val();
            if (!status) {
                return;
            }
            changeStatus(state.selectedId, status);
        });

        root.on('click', ACTIONS.addFiles, function() {
            const input = currentDetail().find('[data-region="file-input"]');
            if (input.length) {
                input.trigger('click');
            }
        });

        root.on('click', ACTIONS.clearFiles, function() {
            clearFiles();
        });

        root.on('change', '[data-region="file-input"]', function() {
            const files = Array.from(this.files || []);
            state.files = files;
            updateAttachmentsPreview();
        });
    };

    const updateFilter = function(filter, value) {
        if (!value) {
            delete state.filters[filter];
            return;
        }
        state.filters[filter] = value;
    };

    const resetFilters = function() {
        state.filters = {};
        root.find(SELECTORS.filters).each(function() {
            const element = $(this);
            if (element.is('select')) {
                element.val('');
            } else if (element.is('[data-filter="search"]')) {
                element.val('');
            }
        });
        fetchList();
    };

    const fetchList = function() {
        callAjax('list', {
            page: 0,
            perpage: config.perpage || 20,
            filters: state.filters
        }).done(function(response) {
            if (!response || !response.success) {
                return;
            }
            const data = response.data || {};
            renderList(data);
            updateSummary(data.summary);
        });
    };

    const renderList = function(data) {
        const records = Array.isArray(data.records) ? data.records : [];
        let hasSelection = false;
        records.forEach(function(record) {
            if (!record) {
                return;
            }
            record.iscurrent = state.selectedId && record.id === state.selectedId;
            if (record.iscurrent) {
                hasSelection = true;
            }
        });

        const context = {
            records: records,
            unassignedlabel: config.strings.unassigned,
            noresults: config.strings.noResults
        };

        const listNode = root.find(SELECTORS.listPane).get(0);
        Templates.render('theme_remui_kids/teacher_doubts_list', context)
            .then(function(html, js) {
                Templates.replaceNodeContents(listNode, html, js);
            }).then(function() {
                if (!records.length) {
                    state.selectedId = null;
                    renderDetailPlaceholder();
                } else if (!hasSelection) {
                    state.selectedId = records[0].id;
                    highlightSelection();
                    fetchDetail(state.selectedId);
                }
            })
            .fail(logError);
    };

    const fetchDetail = function(id) {
        callAjax('detail', {doubtid: id}).done(function(response) {
            if (!response || !response.success) {
                return;
            }
            renderDetail(response.data || {});
        });
    };

    const submitReply = function(form) {
        if (!state.selectedId) {
            return;
        }

        const message = form.find('textarea[name="message"]').val().trim();
        const visibility = form.find('input[name="visibility"]:checked').val() || 'public';
        const resolution = form.find('input[name="resolution"]').is(':checked') ? 1 : 0;

        if (!message && !state.files.length) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'reply');
        formData.append('sesskey', config.sesskey);
        formData.append('doubtid', state.selectedId);
        formData.append('message', message);
        formData.append('visibility', visibility);
        formData.append('resolution', resolution);
        formData.append('format', 1);

        state.files.forEach(function(file) {
            formData.append('attachments[]', file, file.name);
        });

        $.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function(response) {
            if (!response || !response.success) {
                return;
            }
            renderDetail(response.data || {});
            form.get(0).reset();
            state.files = [];
            updateAttachmentsPreview();
            fetchList();
        }).fail(handleAjaxFailure);
    };

    const assign = function(doubtid, assigneeid) {
        callAjax('assign', {
            doubtid: doubtid,
            assigneeid: assigneeid
        }).done(function(response) {
            if (!response || !response.success) {
                return;
            }
            renderDetail(response.data || {});
            fetchList();
        });
    };

    const changeStatus = function(doubtid, status) {
        callAjax('status', {
            doubtid: doubtid,
            status: status
        }).done(function(response) {
            if (!response || !response.success) {
                return;
            }
            renderDetail(response.data || {});
            fetchList();
        });
    };

    const renderDetail = function(detail) {
        if (!detail || !detail.doubt) {
            renderDetailPlaceholder();
            return;
        }

        const context = $.extend(true, {}, detail, config.strings);
        const detailNode = root.find(SELECTORS.detailPane).get(0);

        Templates.render('theme_remui_kids/teacher_doubt_detail', context)
            .then(function(html, js) {
                Templates.replaceNodeContents(detailNode, html, js);
            })
            .then(function() {
                state.files = [];
                highlightSelection();
                applyAttachmentPreviews();
            })
            .fail(logError);
    };

    const renderDetailPlaceholder = function() {
        const detailNode = root.find(SELECTORS.detailPane);
        detailNode.html('<div class="doubt-empty"><i class="fa fa-question-circle"></i><p>' + config.strings.selectPrompt + '</p></div>');
    };

    const updateSummary = function(summary) {
        if (!summary) {
            return;
        }
        Object.keys(summary).forEach(function(key) {
            const card = root.find('[data-summary="' + key + '"] [data-summary-value]');
            if (card.length) {
                card.text(summary[key]);
            }
        });
    };

    const highlightSelection = function() {
        root.find('.doubt-list-item').removeClass('active');
        if (state.selectedId) {
            root.find('.doubt-list-item[data-doubtid="' + state.selectedId + '"]').addClass('active');
        }
    };

    const currentDetail = function() {
        return root.find(SELECTORS.detailPane);
    };

    const applyAttachmentPreviews = function() {
        const container = currentDetail();
        container.find('.message-attachments .attachment-item').each(function() {
            const item = $(this);
            if (item.hasClass('attachment-image')) {
                return;
            }
            const mimetype = (item.data('mimetype') || '').toString().toLowerCase();
            if (mimetype.indexOf('image/') !== 0) {
                return;
            }
            const link = item.find('a').first();
            if (!link.length) {
                return;
            }
            const href = link.attr('href');
            const filename = (link.data('filename') || link.text() || '').trim();
            const img = $('<img/>', {
                src: href,
                alt: filename
            });
            link.empty().append(img);
            item.addClass('attachment-image');
        });
    };

    const updateAttachmentsPreview = function() {
        const preview = currentDetail().find('[data-region="attachments-preview"]');
        if (!preview.length) {
            return;
        }
        preview.empty();
        if (!state.files.length) {
            return;
        }
        state.files.forEach(function(file) {
            $('<span/>', {
                text: file.name,
                class: 'attachment-chip'
            }).appendTo(preview);
        });
    };

    const clearFiles = function() {
        state.files = [];
        const input = currentDetail().find('[data-region="file-input"]');
        if (input.length) {
            input.val('');
        }
        updateAttachmentsPreview();
    };

    const callAjax = function(action, data) {
        const payload = $.extend({
            action: action,
            sesskey: config.sesskey
        }, data || {});

        return $.ajax({
            url: config.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: payload
        }).fail(handleAjaxFailure);
    };

    const handleAjaxFailure = function(jqXHR) {
        let message = jqXHR.statusText || '';
        if (jqXHR.responseJSON) {
            if (jqXHR.responseJSON.debugmessage) {
                message = jqXHR.responseJSON.debugmessage;
            } else if (jqXHR.responseJSON.error) {
                message = jqXHR.responseJSON.error;
            }
        }
        if (!message) {
            return;
        }
        logError(message);
    };

    return {
        init: init
    };
});

