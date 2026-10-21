(function($) {
    'use strict';

    var EscalatedFrontend = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('submit', '.escalated-create-form', this.handleCreateTicket);
            $(document).on('submit', '.escalated-reply-form', this.handleReply);
            $(document).on('submit', '.escalated-guest-create-form', this.handleGuestCreate);
            $(document).on('click', '.escalated-guest-resend', function() {
                EscalatedFrontend.requestGuestCode($(this).closest('form'));
            });
            // A code is tied to the address it was sent to.
            $(document).on('input', '.escalated-guest-create-form [name="guest_email"]', function() {
                var form = $(this).closest('form');
                form.find('input[name="verification_id"]').val('');
                form.find('[name="verification_code"]').val('').prop('required', false);
                form.find('.escalated-guest-verification').prop('hidden', true);
            });
            $(document).on('submit', '.escalated-guest-reply-form', this.handleGuestReply);
            $(document).on('click', '.escalated-close-ticket-btn', this.handleCloseTicket);
            $(document).on('submit', '.escalated-rating-form', this.handleRating);
            $(document).on('click', '.escalated-star', this.handleStarClick);
        },

        handleCreateTicket: function(e) {
            e.preventDefault();
            var form = $(this);
            var formData = new FormData(this);
            formData.append('action', 'escalated_create_ticket');
            formData.append('nonce', escalatedFrontend.nonce);

            EscalatedFrontend.setLoading(form, true);

            $.ajax({
                url: escalatedFrontend.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        EscalatedFrontend.showNotice(form, 'success', response.data.message);
                        form[0].reset();
                        if (response.data.reference) {
                            setTimeout(function() {
                                window.location.href = window.location.href.split('?')[0] + '?ticket=' + response.data.reference;
                            }, 1500);
                        }
                    } else {
                        EscalatedFrontend.showNotice(form, 'error', response.data.message);
                    }
                },
                error: function() {
                    EscalatedFrontend.showNotice(form, 'error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    EscalatedFrontend.setLoading(form, false);
                }
            });
        },

        handleReply: function(e) {
            e.preventDefault();
            var form = $(this);
            var formData = new FormData(this);
            formData.append('action', 'escalated_reply_ticket');
            formData.append('nonce', escalatedFrontend.nonce);

            EscalatedFrontend.setLoading(form, true);

            $.ajax({
                url: escalatedFrontend.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        EscalatedFrontend.showNotice(form, 'error', response.data.message);
                    }
                },
                error: function() {
                    EscalatedFrontend.showNotice(form, 'error', 'An error occurred.');
                },
                complete: function() {
                    EscalatedFrontend.setLoading(form, false);
                }
            });
        },

        handleGuestCreate: function(e) {
            e.preventDefault();
            var form = $(this);
            var idField = form.find('input[name="verification_id"]');

            // Step 1: prove the mailbox. Ask for a code, then show its field.
            if (!idField.val()) {
                EscalatedFrontend.requestGuestCode(form);
                return;
            }

            var formData = new FormData(this);
            formData.append('action', 'escalated_guest_create');
            formData.append('nonce', escalatedFrontend.nonce);
            formData.set('name', form.find('[name="guest_name"]').val() || '');
            formData.set('email', form.find('[name="guest_email"]').val() || '');

            EscalatedFrontend.setLoading(form, true);

            $.ajax({
                url: escalatedFrontend.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        form.hide();
                        var panel = $('<div class="escalated-success-panel"></div>');
                        panel.append($('<h3></h3>').text(response.data.message));
                        panel.append($('<p></p>').text('Reference: ').append($('<strong></strong>').text(response.data.reference)));
                        if (response.data.view_url) {
                            panel.append($('<p></p>').append($('<a class="escalated-btn escalated-btn--primary"></a>').attr('href', response.data.view_url).text('View Your Ticket')));
                        }
                        panel.append($('<p class="escalated-notice"></p>').text('This link expires. To get a new one later, look your ticket up with its reference and your email.'));
                        form.after(panel);
                    } else {
                        EscalatedFrontend.showNotice(form, 'error', $('<div>').text(response.data.message).html());
                    }
                },
                error: function(xhr) {
                    var data = xhr.responseJSON && xhr.responseJSON.data;
                    EscalatedFrontend.showNotice(form, 'error', $('<div>').text((data && data.message) || 'An error occurred.').html());
                },
                complete: function() {
                    EscalatedFrontend.setLoading(form, false);
                }
            });
        },

        requestGuestCode: function(form) {
            var email = form.find('[name="guest_email"]').val();
            EscalatedFrontend.setLoading(form, true);
            $.ajax({
                url: escalatedFrontend.guestVerificationUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ email: email, purpose: 'ticket' }),
                success: function(data) {
                    form.find('input[name="verification_id"]').val(data.verification_id);
                    form.find('.escalated-guest-verification').prop('hidden', false);
                    form.find('[name="verification_code"]').val('').prop('required', true).trigger('focus');
                    EscalatedFrontend.showNotice(form, 'success', 'Check your email for the 8-digit code, then submit again.');
                },
                error: function(xhr) {
                    var data = xhr.responseJSON || {};
                    EscalatedFrontend.showNotice(form, 'error', $('<div>').text(data.message || 'Could not send a verification code.').html());
                },
                complete: function() {
                    EscalatedFrontend.setLoading(form, false);
                }
            });
        },

        handleGuestReply: function(e) {
            e.preventDefault();
            var form = $(this);
            var formData = new FormData(this);
            formData.append('action', 'escalated_guest_reply');
            formData.append('nonce', escalatedFrontend.nonce);

            EscalatedFrontend.setLoading(form, true);

            $.ajax({
                url: escalatedFrontend.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        EscalatedFrontend.showNotice(form, 'error', response.data.message);
                    }
                },
                complete: function() {
                    EscalatedFrontend.setLoading(form, false);
                }
            });
        },

        handleCloseTicket: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to close this ticket?')) return;

            var ticketId = $(this).data('ticket-id');
            $.post(escalatedFrontend.ajaxUrl, {
                action: 'escalated_close_ticket',
                nonce: escalatedFrontend.nonce,
                ticket_id: ticketId
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            });
        },

        handleRating: function(e) {
            e.preventDefault();
            var form = $(this);
            $.post(escalatedFrontend.ajaxUrl, {
                action: 'escalated_rate_ticket',
                nonce: escalatedFrontend.nonce,
                ticket_id: form.find('[name="ticket_id"]').val(),
                rating: form.find('[name="rating"]').val(),
                comment: form.find('[name="comment"]').val()
            }, function(response) {
                if (response.success) {
                    EscalatedFrontend.showNotice(form, 'success', response.data.message);
                    form.find('button[type="submit"]').prop('disabled', true);
                } else {
                    EscalatedFrontend.showNotice(form, 'error', response.data.message);
                }
            });
        },

        handleStarClick: function() {
            var rating = $(this).data('value');
            $(this).closest('.escalated-stars').find('.escalated-star').removeClass('active');
            $(this).closest('.escalated-stars').find('.escalated-star').each(function() {
                if ($(this).data('value') <= rating) {
                    $(this).addClass('active');
                }
            });
            $(this).closest('form').find('[name="rating"]').val(rating);
        },

        setLoading: function(form, loading) {
            var btn = form.find('button[type="submit"]');
            if (loading) {
                btn.prop('disabled', true).addClass('escalated-loading');
            } else {
                btn.prop('disabled', false).removeClass('escalated-loading');
            }
        },

        showNotice: function(context, type, message) {
            context.find('.escalated-form-notice').remove();
            var cls = type === 'success' ? 'escalated-notice--success' : 'escalated-notice--error';
            var notice = $('<div class="escalated-form-notice ' + cls + '">' + message + '</div>');
            context.prepend(notice);
            setTimeout(function() { notice.fadeOut(); }, 5000);
        }
    };

    $(document).ready(function() {
        EscalatedFrontend.init();
    });

})(jQuery);
