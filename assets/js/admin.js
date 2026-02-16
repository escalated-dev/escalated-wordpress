(function($) {
    'use strict';

    var Escalated = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Bulk action checkboxes
            $(document).on('click', '#escalated-select-all', this.toggleSelectAll);
            $(document).on('click', '.escalated-bulk-action-btn', this.handleBulkAction);

            // Quick edit actions via AJAX
            $(document).on('change', '.escalated-quick-status', this.quickChangeStatus);
            $(document).on('change', '.escalated-quick-priority', this.quickChangePriority);
            $(document).on('change', '.escalated-quick-assign', this.quickAssign);

            // Reply form toggle
            $(document).on('click', '.escalated-reply-tab', this.switchReplyTab);

            // Tag management
            $(document).on('click', '.escalated-add-tag', this.addTag);
            $(document).on('click', '.escalated-remove-tag', this.removeTag);

            // Confirm dangerous actions
            $(document).on('click', '.escalated-confirm-action', this.confirmAction);

            // Repeatable field groups (for escalation rules, macros)
            $(document).on('click', '.escalated-add-condition', this.addConditionRow);
            $(document).on('click', '.escalated-remove-condition', this.removeConditionRow);
            $(document).on('click', '.escalated-add-action', this.addActionRow);
            $(document).on('click', '.escalated-remove-action', this.removeActionRow);

            // Copy API token
            $(document).on('click', '.escalated-copy-token', this.copyToken);
        },

        toggleSelectAll: function() {
            var checked = $(this).prop('checked');
            $('.escalated-ticket-checkbox').prop('checked', checked);
        },

        handleBulkAction: function(e) {
            e.preventDefault();
            var action = $('#escalated-bulk-action-select').val();
            if (!action) return;

            var ticketIds = [];
            $('.escalated-ticket-checkbox:checked').each(function() {
                ticketIds.push($(this).val());
            });

            if (ticketIds.length === 0) {
                alert('Please select at least one ticket.');
                return;
            }

            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_bulk_action',
                nonce: escalatedAdmin.nonce,
                bulk_action: action,
                ticket_ids: ticketIds
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Action failed.');
                }
            });
        },

        quickChangeStatus: function() {
            var ticketId = $(this).data('ticket-id');
            var status = $(this).val();
            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_admin_change_status',
                nonce: escalatedAdmin.nonce,
                ticket_id: ticketId,
                status: status
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        },

        quickChangePriority: function() {
            var ticketId = $(this).data('ticket-id');
            var priority = $(this).val();
            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_admin_change_priority',
                nonce: escalatedAdmin.nonce,
                ticket_id: ticketId,
                priority: priority
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        },

        quickAssign: function() {
            var ticketId = $(this).data('ticket-id');
            var agentId = $(this).val();
            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_admin_assign',
                nonce: escalatedAdmin.nonce,
                ticket_id: ticketId,
                agent_id: agentId
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        },

        switchReplyTab: function(e) {
            e.preventDefault();
            var target = $(this).data('tab');
            $('.escalated-reply-tab').removeClass('active');
            $(this).addClass('active');
            $('.escalated-reply-panel').hide();
            $('#escalated-panel-' + target).show();
            if (target === 'note') {
                $('input[name="is_internal_note"]').val('1');
            } else {
                $('input[name="is_internal_note"]').val('0');
            }
        },

        addTag: function(e) {
            e.preventDefault();
            var tagId = $('#escalated-tag-select').val();
            if (!tagId) return;
            var ticketId = $(this).data('ticket-id');
            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_admin_add_tag',
                nonce: escalatedAdmin.nonce,
                ticket_id: ticketId,
                tag_id: tagId
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        },

        removeTag: function(e) {
            e.preventDefault();
            var tagId = $(this).data('tag-id');
            var ticketId = $(this).data('ticket-id');
            $.post(escalatedAdmin.ajaxUrl, {
                action: 'escalated_admin_remove_tag',
                nonce: escalatedAdmin.nonce,
                ticket_id: ticketId,
                tag_id: tagId
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        },

        confirmAction: function(e) {
            if (!confirm('Are you sure you want to perform this action?')) {
                e.preventDefault();
            }
        },

        addConditionRow: function(e) {
            e.preventDefault();
            var template = $('#escalated-condition-template').html();
            var index = $('.escalated-condition-row').length;
            template = template.replace(/__INDEX__/g, index);
            $('#escalated-conditions-container').append(template);
        },

        removeConditionRow: function(e) {
            e.preventDefault();
            $(this).closest('.escalated-condition-row').remove();
        },

        addActionRow: function(e) {
            e.preventDefault();
            var template = $('#escalated-action-template').html();
            var index = $('.escalated-action-row').length;
            template = template.replace(/__INDEX__/g, index);
            $('#escalated-actions-container').append(template);
        },

        removeActionRow: function(e) {
            e.preventDefault();
            $(this).closest('.escalated-action-row').remove();
        },

        copyToken: function(e) {
            e.preventDefault();
            var token = $(this).data('token');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(token);
                $(this).text('Copied!');
                var btn = $(this);
                setTimeout(function() { btn.text('Copy'); }, 2000);
            }
        }
    };

    $(document).ready(function() {
        Escalated.init();
    });

})(jQuery);
