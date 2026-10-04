/* Refresh: "New ticket" and "Send an e-mail" pages, Freshdesk-style (New ⌄ menu, ?rf_mode=ticket|email).
   Both are FreeScout's own create form (conversations.create), rebuilt: one centered column of labelled fields, the
   Freshdesk-style editor, a footer bar, and the contact panel of the ticket page on the right.
   - Send an e-mail: FreeScout's own sending (the contact gets the e-mail, the ticket comes with it).
   - New ticket: the agent creates the ticket on behalf of the contact; the description is the CONTACT's message and
     nothing is sent. The native submit is stopped (conversation.can_submit filter, Ctrl+Enter included) and the form
     goes to /refresh/new-ticket instead (NewTicketController).
   Type, priority and tags (rf_type, rf_priority, rf_tags[]) are saved server-side in both modes.
   On the phone the layout is mobile.js's (app style); this file only does the invisible part there (window.rfNew).
   No draft on these pages (like Freshdesk): FreeScout saved an empty draft as soon as the page opened.
   Data: window.rfNewData (provider). Runs on DOMContentLoaded, after the provider's shell. ES5. */
(function () {
    if (!window.jQuery) {
        return;
    }
    var $ = window.jQuery;
    var rfT = function (s) { return window.rfT ? window.rfT(s) : s; };

    $(function () {
        var D = window.rfNewData;
        var f = $('#form-create');
        if (!D || !f.length) {
            return;
        }
        var ticket = D.mode === 'ticket';
        var phone = !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
        $('body').addClass('rf-new-page ' + (ticket ? 'rf-new-ticket' : 'rf-new-email'));

        // ============================================================ common part (desktop and phone)

        // no drafts
        window.saveDraft = function () { window.fs_processing_save_draft = false; };
        window.autosaveDraft = function () {};

        // a draft saved as a phone conversation goes back to e-mail
        if ($('#phone-conv-switch').hasClass('active')) {
            $('#email-conv-switch').trigger('click');
        }
        f.append('<input type="hidden" name="rf_new" value="1">');
        var another = $('<input type="hidden" name="rf_another" value="">').appendTo(f);
        var afterSend = f.find(':input[name="after_send"]');
        if (!afterSend.length) {
            afterSend = $('<input type="hidden" name="after_send">').appendTo(f);
        }
        afterSend.val(1); // FreeScout's "stay": its response points to the new ticket, which is opened

        // Native status / assignee selects: FreeScout copies them into the editor footer when it builds the editor
        // (initReplyForm), so they are only reachable then. footerReady(cb) runs cb once they exist.
        var footer = { status: $(), user: $() };
        var footerReady = function (cb) {
            var n = 0;
            var tick = function () {
                var s = f.find('.note-statusbar select[name="status"]').first();
                if (!s.length) {
                    if (n++ < 40) { setTimeout(tick, 150); }
                    return;
                }
                if (!footer.status.length) {
                    footer.status = s;
                    footer.user = f.find('.note-statusbar select[name="user_id"]').first();
                    s.val(ticket ? '1' : '3'); // Freshdesk defaults: Open for a ticket, Closed for an e-mail
                    if (D.me && footer.user.find('option[value="' + D.me + '"]').length) {
                        footer.user.val(String(D.me));
                    }
                }
                cb(footer);
            };
            tick();
        };

        // Editor: the reply editor's Freshdesk style (rf-ed), with its tools in the footer: Aa (formatting bar),
        // attachment, saved replies. Desktop and phone (mobile.css styles that footer like the reply's bottom bar).
        // Returns the footer the first time, null after.
        var skinEditor = function () {
            var ed = f.find('.conv-reply-body .note-editor').first();
            if (!ed.length || ed.hasClass('rf-ed')) {
                return null;
            }
            var sb = ed.find('.note-statusbar').first();
            ed.addClass('rf-ed rf-nw-ed');
            var sig = ed.find('#editor_signature');
            if (ticket) {
                sig.hide(); // the contact's message: no agent signature
            } else {
                ed.find('.note-editing-area').after(sig);
            }
            var tools = $('<div class="rf-ed-tools"></div>');
            tools.append($('<button type="button" class="rf-ed-ic"></button>').attr('title', rfT('Formatting options')).html('<i class="rf-i rf-i-fd-formatting"></i>').on('click', function () {
                ed.toggleClass(phone ? 'rf-m-fmt' : 'rf-ed-notb'); // phone: formatting bar hidden until Aa, like the reply
            }));
            var att = ed.find('.note-btn-attachment').first();
            if (att.length) {
                tools.append(att.addClass('rf-ed-ic').html('<i class="rf-i rf-i-fd-attach"></i>'));
            }
            var saved = ed.find('.dropdown-saved-replies').first().parent();
            if (saved.length) {
                saved.find('.dropdown-toggle').first().addClass('rf-ed-ic').html('<i class="rf-i rf-i-fd-canned"></i>').attr('title', rfT('Saved Replies'));
                saved.find('.dropdown-menu').first().removeClass('dropdown-menu-right');
                saved.addClass('dropup rf-ed-saved');
                tools.append(saved);
                // search field from 8 replies, same as the reply editor (provider): accents ignored, Enter = first match
                saved.on('shown.bs.dropdown', function () {
                    var menu = saved.children('.dropdown-saved-replies').first();
                    var items = menu.children('li').not('.rf-dd-search, .rf-dd-none');
                    if (items.length < 8) {
                        return;
                    }
                    var box = menu.children('.rf-dd-search');
                    if (!box.length) {
                        var fold = function (t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
                        var none = $('<li class="rf-dd-none"></li>').text(rfT('No results')).hide().appendTo(menu);
                        box = $('<li class="rf-dd-search"><input type="text" autocomplete="off"></li>').prependTo(menu);
                        box.on('click', function (e) { e.stopPropagation(); });
                        box.find('input').attr('placeholder', rfT('Search saved replies')).on('input', function () {
                            var q = fold($(this).val());
                            items.each(function () { $(this).toggle(!q || fold($(this).text()).indexOf(q) !== -1); });
                            none.toggle(!items.filter(':visible').length);
                        }).on('keydown', function (e) {
                            var first = items.filter(':visible').first().children('a');
                            if (e.key === 'Enter') { e.preventDefault(); first.trigger('click'); }
                            if (e.key === 'ArrowDown') { e.preventDefault(); first.trigger('focus'); }
                        });
                    }
                    box.find('input').val('').trigger('input').trigger('focus');
                });
            }
            sb.prepend(tools);
            return sb;
        };
        // defaults (status, agent) everywhere; on the phone the editor is skinned here (the layout is mobile.js's)
        footerReady(function () {
            if (phone) {
                skinEditor();
            }
        });

        // type / priority selects (saved server-side), shared with mobile.js
        var select = function (name, options, value) {
            var s = $('<select class="rf-select"></select>').attr('name', name);
            $.each(options, function (i, o) { s.append($('<option></option>').val(o[0]).text(o[1])); });
            s.val(String(value));
            return s;
        };
        var typeOptions = [['', '--']];
        $.each(D.types || [], function (i, t) { typeOptions.push([t, t]); });
        var prioOptions = [];
        $.each(D.priorities || [], function (i, p) { prioOptions.push([p.id, p.label]); });
        var typeSel = select('rf_type', typeOptions, '');
        var prioSel = select('rf_priority', prioOptions, 1);

        // New ticket: own creation instead of FreeScout's send (which would e-mail the contact)
        var setBusy = function () {};
        if (ticket && window.fsAddFilter) {
            var sending = false;
            fsAddFilter('conversation.can_submit', function (allowed) {
                if (!allowed || sending) {
                    return false;
                }
                sending = true;
                setBusy(true);
                var editor = $('#body');
                if ($.fn.summernote && editor.length) {
                    editor.val(editor.summernote('code'));
                }
                var fail = function (msg) {
                    sending = false;
                    setBusy(false);
                    if (window.showFloatingAlert) { showFloatingAlert('error', msg || rfT('Could not save')); }
                };
                $.ajax({ url: D.postUrl, type: 'POST', dataType: 'json', data: f.serialize() })
                    .done(function (r) {
                        if (r && r.status === 'success' && r.redirect_url) {
                            window.location.href = r.redirect_url;
                        } else {
                            fail(r && r.msg);
                        }
                    })
                    .fail(function () { fail(); });
                return false; // FreeScout sends nothing
            });
        }

        // Send an e-mail: "Send another" opens a new form after FreeScout's send
        if (!ticket && typeof window.fsAjax === 'function') {
            var nativeFsAjax = window.fsAjax;
            window.fsAjax = function (data, url, callback) {
                var args = Array.prototype.slice.call(arguments);
                if (typeof data === 'string' && /(^|&)action=send_reply(&|$)/.test(data) && /(^|&)rf_another=1(&|$)/.test(data) && typeof callback === 'function') {
                    args[2] = function (response) {
                        if (response && response.status === 'success') {
                            response.redirect_url = D.newUrl + '?rf_mode=email';
                        }
                        return callback.apply(this, arguments);
                    };
                }
                return nativeFsAjax.apply(this, args);
            };
        }

        // top bar title
        var title = ticket ? rfT('New ticket') : rfT('Send an e-mail');
        var setTitle = function () {
            $('.rf-head-title').first().text(title);
            document.title = title + ' - ' + (D.mailbox.name || '');
        };
        setTitle();
        setTimeout(setTitle, 0);

        // read by mobile.js
        window.rfNew = { ticket: ticket, title: title, typeSel: typeSel, prioSel: prioSel, another: another, footerReady: footerReady };

        if (phone) {
            return;
        }

        // ============================================================ desktop layout

        var field = function (label, required, content, cls) {
            var w = $('<div class="rf-nf"></div>').addClass(cls || '');
            var l = $('<label class="rf-nf-label"></label>').text(label);
            if (required) {
                l.append(' <span class="rf-nf-req">*</span>');
            }
            return w.append(l, $('<div class="rf-nf-input"></div>').append(content));
        };

        // priority: colored square in front, like the properties panel
        var prioWrap = $('<div class="rf-rp-prio-wrap"></div>').append('<span class="rf-rp-prio-sq"></span>', prioSel);
        var prioColor = function () {
            var p = $.grep(D.priorities || [], function (x) { return String(x.id) === String(prioSel.val()); })[0];
            prioWrap.find('.rf-rp-prio-sq').css('background', p ? p.color : '#94a3b8');
        };
        prioSel.on('change', prioColor);
        prioColor();

        // tags: select2 with the Tags module's suggestions (same as the properties panel)
        var tagSel = (D.tags && window.laroute && $.fn.select2) ? $('<select class="rf-tag-select" name="rf_tags[]" multiple></select>') : null;

        var wrap = $('<div class="rf-nw"></div>');
        if (!ticket) {
            wrap.append($('<p class="rf-nw-intro"></p>').text(rfT('When you click Send, the contact gets an e-mail and a ticket is created with it.')));
            var from = f.find('select[name="from_alias"]').first();
            if (from.length) {
                wrap.append(field(rfT('From'), false, from.addClass('rf-select').removeClass('form-control')));
            } else {
                wrap.append(field(rfT('From'), false, $('<div class="rf-nw-from"></div>').append(
                    $('<span></span>').text(D.mailbox.name + ' '), $('<span class="rf-nw-muted"></span>').text('(' + D.mailbox.email + ')'))));
            }
        }

        // Contact / To: FreeScout's recipient field (contact search; a new e-mail address is accepted)
        var contactField = field(ticket ? rfT('Contact') : rfT('To'), true, f.find('#field-to > .col-sm-9').first().children(), 'rf-nw-to');
        var ccField = field('Cc', false, f.find('#cc').closest('.col-sm-9').children(), 'rf-nw-cc').hide();
        var bccField = field(rfT('Bcc'), false, f.find('#bcc').closest('.col-sm-9').children(), 'rf-nw-cc').hide();
        var showRecipients = function (w) {
            w.show();
            if (window.initRecipientSelector) {
                initRecipientSelector(); // initializes the fields not done yet (FreeScout does it on its "Cc/Bcc" link)
            }
        };
        var links = $('<div class="rf-nw-links"></div>');
        var linkTo = function (text, cb) {
            return $('<a href="#"></a>').text(text).on('click', function (e) { e.preventDefault(); cb.call(this); });
        };
        var hideLink = function (a) {
            $(a).hide();
            links.find('.rf-nw-sep').hide();
        };
        // New ticket: "Add a new contact" (name, e-mail, phone) instead of the contact search
        var newContact = $('<div class="rf-nw-newcontact"></div>').hide();
        if (ticket) {
            newContact.append(
                field(rfT('Name'), false, $('<input type="text" class="rf-input" name="rf_contact_name" autocomplete="off">')),
                field(rfT('E-mail'), true, $('<input type="email" class="rf-input" name="rf_contact_email" autocomplete="off">')),
                field(rfT('Phone'), false, $('<input type="text" class="rf-input" name="rf_contact_phone" autocomplete="off">'))
            );
            links.append(linkTo(rfT('Add a new contact'), function () {
                var on = !newContact.is(':visible');
                newContact.toggle(on);
                contactField.toggle(!on);
                $(this).text(on ? rfT('Search a contact') : rfT('Add a new contact'));
                if (on) {
                    newContact.find('input').first().trigger('focus');
                } else {
                    newContact.find('input').val('');
                }
                loadPanel();
            }));
            links.append('<span class="rf-nw-sep">|</span>', linkTo(rfT('Add Cc'), function () { showRecipients(ccField); hideLink(this); }));
        } else {
            links.append(linkTo(rfT('Add Cc'), function () { showRecipients(ccField); $(this).hide(); links.find('.rf-nw-sep').hide(); }));
            links.append('<span class="rf-nw-sep">|</span>', linkTo(rfT('Add Bcc'), function () { showRecipients(bccField); $(this).hide(); links.find('.rf-nw-sep').hide(); }));
        }
        wrap.append(contactField, newContact, links, ccField, bccField);
        wrap.append(field(rfT('Subject'), true, f.find('#subject').removeClass('form-control').addClass('rf-input')));

        var body = f.find('.conv-reply-body').first();
        var bodyField = field(rfT('Description'), true, $('<div class="rf-nw-body"></div>').append(body, f.find('.thread-attachments').first()), 'rf-nw-desc');
        var typeField = field(rfT('Type'), false, typeSel);
        var statusField = field(rfT('Status'), true, $());
        var prioField = field(rfT('Priority'), true, prioWrap);
        var agentField = field(rfT('Agent'), false, $());
        var tagsField = tagSel ? field(rfT('Tags'), false, tagSel) : $();
        if (ticket) {
            // Freshdesk order: Contact, Subject, Type, Status, Priority, Agent, Description, Tags
            wrap.append(typeField, statusField, prioField, agentField, bodyField, tagsField);
        } else {
            // Freshdesk order: From, To, Subject, Description, Priority, Status, Tags, Type (assigned to the sender)
            wrap.append(bodyField, prioField, statusField, tagsField, typeField);
        }

        // footer: [ ] Create another · Cancel · Create ▾ (Create and set as closed) | [ ] Send another · Cancel · Send
        var foot = $('<div class="rf-nw-foot"></div>');
        var anotherBox = $('<label class="rf-nw-another"><input type="checkbox"> <span></span></label>');
        anotherBox.find('span').text(ticket ? rfT('Create another') : rfT('Send another'));
        anotherBox.find('input').on('change', function () { another.val(this.checked ? '1' : ''); });
        var cancel = $('<a class="rf-btn rf-nw-cancel"></a>').text(rfT('Cancel')).attr('href', D.backUrl || '#');
        var submit = function () {
            // new contact: its e-mail goes into the Contact field, which FreeScout's validation requires
            var ne = $.trim(newContact.find('[name="rf_contact_email"]').val() || '');
            if (newContact.is(':visible') && ne) {
                var to = f.find('#to');
                to.find('option').remove();
                to.append($('<option selected></option>').val(ne).text(ne)).trigger('change');
            }
            f.find('.btn-send-text').first().trigger('click'); // FreeScout's submit: validation, then send or our filter
        };
        var main = $('<button type="button" class="rf-btn-primary rf-nw-submit"></button>').text(ticket ? rfT('Create') : rfT('Send')).on('click', submit);
        var group = $('<div class="rf-nw-submit-group"></div>').append(main);
        if (ticket) {
            var dd = $('<div class="dropup rf-nw-dd"><button type="button" class="rf-btn-primary rf-nw-caret" data-toggle="dropdown"><i class="rf-i rf-i-fd-dropdown-arrow"></i></button><ul class="dropdown-menu dropdown-menu-right"></ul></div>');
            dd.find('ul').append($('<li></li>').append(linkTo(rfT('Create and set as closed'), function () {
                footer.status.val('3');
                submit();
            })));
            group.addClass('rf-nw-split').append(dd);
        }
        setBusy = function (on) {
            group.find('button').prop('disabled', on);
            main.text(on ? rfT('Creating…') : (ticket ? rfT('Create') : rfT('Send')));
        };
        foot.append(anotherBox, $('<div class="rf-nw-actions"></div>').append(cancel, group));
        wrap.append(foot);

        // the rebuilt column replaces the native rows, inside the form (everything is still serialized by FreeScout)
        f.children('.form-group, div').addClass('rf-nw-native');
        f.prepend(wrap);

        if (tagSel) {
            tagSel.select2({
                width: '100%', multiple: true, tags: true, minimumInputLength: 1, tokenSeparators: [','],
                containerCssClass: 'rf-tag-box', dropdownCssClass: 'rf-tag-dd',
                ajax: {
                    url: laroute.route('tags.ajax'), dataType: 'json', delay: 200,
                    data: function (params) { return { q: params.term, action: 'autocomplete', page: params.page || 1 }; }
                },
                createTag: function (params) { var t = $.trim(params.term); return t ? { id: t, text: t, newOption: true } : null; },
                templateResult: function (d) { return $('<span></span>').text(d.newOption ? rfT('Create “:text”').replace(':text', function () { return d.text; }) : d.text); },
                language: {
                    inputTooShort: function () { return rfT('Type at least 1 character…'); },
                    searching: function () { return rfT('Searching…'); },
                    noResults: function () { return rfT('No tags'); },
                    errorLoading: function () { return rfT('Search failed'); }
                }
            });
        }

        // status / agent moved into the form's fields, editor skinned; FreeScout's footer items (labels, selects left,
        // send group, draft state) stay hidden: the page footer sends
        footerReady(function () {
            statusField.find('.rf-nf-input').append(footer.status.addClass('rf-select').removeClass('form-control'));
            if (ticket) {
                agentField.find('.rf-nf-input').append(footer.user.addClass('rf-select').removeClass('form-control'));
            }
            var sb = skinEditor();
            if (sb) {
                sb.children().not('.rf-ed-tools').addClass('rf-nw-native');
            }
        });

        // contact panel on the right: the ticket page's (Contact details + Recent timeline), empty state first
        var side = $('<aside class="rf-nw-side"><div class="rf-rp-contact"></div></aside>');
        $('#conv-layout').append(side);
        var panelReq = null;
        var loadPanel = function () {
            var v = f.find('#to').val();
            var email = newContact.is(':visible') ? '' : ($.isArray(v) ? (v[0] || '') : (v || ''));
            if (panelReq) {
                panelReq.abort();
            }
            panelReq = $.get(D.panelUrl, { mailbox_id: D.mailbox.id, email: email }, function (html) {
                side.find('.rf-rp-contact').html(html);
            });
        };
        f.find('#to').on('change', loadPanel);
        loadPanel();
        side.on('click', '.rf-rp-sec-head', function (e) {
            if (!$(e.target).closest('a').length) {
                $(this).closest('.rf-rp-sec').toggleClass('collapsed');
            }
        });
        side.on('click', '.rf-copy', function () {
            if (navigator.clipboard) {
                navigator.clipboard.writeText($(this).attr('data-copy'));
            }
        });
    });
})();
