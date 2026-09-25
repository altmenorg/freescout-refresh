/* Refresh: reply editor formatting toolbar, Freshdesk-style.
   File loaded with the page scripts ("javascripts" filter): it must run BEFORE initReplyForm(),
   which the view calls in the footer script, after the module's JS (too late for this filter).
   Freshdesk order: alignment, indent | [attachment] bold italic underline, Heading 1, Heading 2, color, lists,
   clear formatting, link, image, table, code.
   Warning: Summernote 0.8.9's native justifyLeft… / indent / outdent buttons crash outside the "paragraph" menu
   ($parent.append is not a function -> whole editor breaks): recreated here instead. Test any new entry
   on a disposable editor before deploying. */
(function () {
    // Refresh translations: dictionary of the user's language, put in <head> by the module (meta refresh-l10n).
    var rfT = window.rfT = window.rfT || function (s) {
        if (!window.rfL) {
            try { window.rfL = JSON.parse(document.querySelector('meta[name="refresh-l10n"]').getAttribute('content')); } catch (e) { window.rfL = {}; }
        }
        return window.rfL[s] || s;
    };
    if (typeof fsAddFilter === 'undefined' || typeof fs_conv_editor_buttons === 'undefined') {
        return;
    }

    var cmdButton = function (cmd, icon, title) {
        return function (context) {
            return $.summernote.ui.button({
                contents: '<i class="note-icon-' + icon + '"></i>',
                tooltip: title,
                container: 'body',
                click: function () { context.invoke('editor.' + cmd); }
            }).render();
        };
    };

    // Heading 1 / Heading 2: toggles the current block (click again = back to paragraph)
    var headingButton = function (tag, title) {
        return function (context) {
            return $.summernote.ui.button({
                contents: '<i class="note-icon-mes-' + tag + '"></i>',
                tooltip: title,
                container: 'body',
                click: function () {
                    var sel = window.getSelection ? window.getSelection() : null;
                    var node = sel && sel.anchorNode ? sel.anchorNode : null;
                    var h = node ? $(node).closest(tag) : $();
                    var inTag = h.length && $.contains(context.layoutInfo.editable[0], h[0]);
                    context.invoke('editor.formatBlock', inTag ? 'P' : tag.toUpperCase());
                }
            }).render();
        };
    };

    fs_conv_editor_buttons.rfJustifyLeft = cmdButton('justifyLeft', 'align-left', rfT('Align left'));
    fs_conv_editor_buttons.rfJustifyCenter = cmdButton('justifyCenter', 'align-center', rfT('Align center'));
    fs_conv_editor_buttons.rfJustifyRight = cmdButton('justifyRight', 'align-right', rfT('Align right'));
    fs_conv_editor_buttons.rfJustifyFull = cmdButton('justifyFull', 'align-justify', rfT('Justify'));
    fs_conv_editor_buttons.rfOutdent = cmdButton('outdent', 'align-outdent', rfT('Decrease indent'));
    fs_conv_editor_buttons.rfIndent = cmdButton('indent', 'align-indent', rfT('Increase indent'));
    fs_conv_editor_buttons.mesh1 = headingButton('h1', rfT('Heading 1'));
    fs_conv_editor_buttons.mesh2 = headingButton('h2', rfT('Heading 2'));

    fsAddFilter('conversation.editor_toolbar', function (toolbar) {
        var out = [['rf-align', ['rfJustifyLeft', 'rfJustifyCenter', 'rfJustifyRight', 'rfJustifyFull', 'rfOutdent', 'rfIndent']]];
        for (var i = 0; i < toolbar.length; i++) {
            if (toolbar[i][0] !== 'style') {
                out.push(toolbar[i]);
                continue;
            }
            var btns = [];
            for (var j = 0; j < toolbar[i][1].length; j++) {
                var b = toolbar[i][1][j];
                btns.push(b);
                if (b === 'underline') { btns.push('mesh1', 'mesh2', 'color'); }
                if (b === 'picture') { btns.push('table'); }
            }
            out.push(['style', btns]);
        }
        return out;
    });
})();
