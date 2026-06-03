jQuery(document).ready(function ($) {
    if ( typeof window.sfcoBuilder === 'undefined' || ! $('#sfco-fields-list').length ) {
        return;
    }

    var state = (window.sfcoBuilder.fields || []).map(normalize);
    var $list   = $('#sfco-fields-list');
    var $empty  = $('#sfco-empty-state');
    var $status = $('#sfco-builder-status');
    var tplHTML = $('#sfco-field-tpl').prop('content')
        ? document.getElementById('sfco-field-tpl').innerHTML
        : $('#sfco-field-tpl').html();

    function normalize(f) {
        return $.extend({
            key: '', type: 'text', label: '', placeholder: '', description: '',
            required: false, default: '', options: [], rows: 4,
            min: '', max: '', accept: '', html: ''
        }, f || {});
    }

    function defaultsFor(type) {
        var presets = {
            text:     { label: 'Single Line Text',  key: 'text_field' },
            email:    { label: 'Email',             key: 'email' },
            tel:      { label: 'Phone',             key: 'phone' },
            number:   { label: 'Number',            key: 'number_field' },
            textarea: { label: 'Paragraph',         key: 'message', rows: 5 },
            select:   { label: 'Dropdown',          key: 'dropdown', options: ['Option 1', 'Option 2'] },
            radio:    { label: 'Radio',             key: 'choice',   options: ['Yes', 'No'] },
            checkbox: { label: 'Checkboxes',        key: 'checkboxes', options: ['Option 1', 'Option 2'] },
            date:     { label: 'Date',              key: 'date_field' },
            file:     { label: 'File Upload',       key: 'file_upload', accept: '.pdf,.doc,.docx,image/*' },
            hidden:   { label: 'Hidden',            key: 'hidden_field' },
            html:     { label: 'HTML',              key: 'html_block', html: '<p>Custom HTML</p>' }
        };
        return normalize($.extend({ type: type }, presets[type] || { type: type }));
    }

    function slugify(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function render() {
        $list.empty();
        if ( ! state.length ) {
            $empty.show();
            return;
        }
        $empty.hide();
        state.forEach(function (f, idx) {
            var $card = $(tplHTML);
            $card.attr('data-index', idx);
            $card.find('.sfco-field-label').text(f.label || '(no label)');
            $card.find('.sfco-field-type').text(f.type);
            $card.find('.sfco-field-required').toggle(!!f.required);

            // Populate inputs.
            $card.find('[data-prop]').each(function () {
                var prop = $(this).data('prop');
                var val  = f[prop];
                if (this.type === 'checkbox') {
                    this.checked = !!val;
                } else if (prop === 'options') {
                    this.value = (Array.isArray(val) ? val : []).join('\n');
                } else {
                    this.value = (val == null ? '' : val);
                }
            });

            // Toggle which settings rows are visible based on field type.
            var showOptions = ['select','radio','checkbox'].indexOf(f.type) !== -1;
            var showRows    = f.type === 'textarea';
            var showMinMax  = f.type === 'number';
            var showAccept  = f.type === 'file';
            var showHtml    = f.type === 'html';
            $card.find('.sfco-row-options').toggle(showOptions);
            $card.find('.sfco-row-rows').toggle(showRows);
            $card.find('.sfco-row-minmax').toggle(showMinMax);
            $card.find('.sfco-row-accept').toggle(showAccept);
            $card.find('.sfco-row-html').toggle(showHtml);
            $card.find('.sfco-row-placeholder, .sfco-row-default, .sfco-row-required, .sfco-row-description').toggle(f.type !== 'html');

            $list.append($card);
        });
    }

    // Click a palette button → append a new field of that type.
    $('#sfco-palette').on('click', '.sfco-palette-btn', function () {
        var type = $(this).data('type');
        state.push(defaultsFor(type));
        render();
        $list.children().last().find('.sfco-field-body').show();
    });

    // Toggle a field card open/closed.
    $list.on('click', '.sfco-field-toggle', function (e) {
        e.preventDefault();
        $(this).closest('.sfco-field-card').find('.sfco-field-body').slideToggle(120);
    });

    // Delete a field.
    $list.on('click', '.sfco-field-delete', function () {
        var idx = $(this).closest('.sfco-field-card').data('index');
        if (!confirm('Delete this field?')) return;
        state.splice(idx, 1);
        render();
    });

    // Mirror input edits back into state + the card header preview.
    $list.on('input change', '[data-prop]', function () {
        var $card = $(this).closest('.sfco-field-card');
        var idx   = $card.data('index');
        var prop  = $(this).data('prop');
        var val;
        if (this.type === 'checkbox') {
            val = this.checked;
        } else if (prop === 'options') {
            val = this.value.split(/\r?\n/).map(function(s){return s.trim();}).filter(function(s){return s.length;});
        } else {
            val = this.value;
        }
        state[idx][prop] = val;
        if (prop === 'label') {
            $card.find('.sfco-field-label').text(val || '(no label)');
            // Auto-slug the key on first label edit if key looks like a default.
            var current = state[idx].key || '';
            if (!current || /^(text_field|email|phone|number_field|message|dropdown|choice|checkboxes|date_field|file_upload|hidden_field|html_block|field_\d+)$/.test(current)) {
                var newKey = slugify(val);
                if (newKey) {
                    state[idx].key = newKey;
                    $card.find('[data-prop="key"]').val(newKey);
                }
            }
        }
        if (prop === 'required') {
            $card.find('.sfco-field-required').toggle(!!val);
        }
    });

    // Sortable.
    if ($.fn.sortable) {
        $list.sortable({
            handle: '.sfco-handle',
            placeholder: 'sfco-sort-placeholder',
            forcePlaceholderSize: true,
            update: function () {
                var newOrder = [];
                $list.children().each(function () {
                    newOrder.push(state[$(this).data('index')]);
                });
                state = newOrder;
                render();
            }
        });
    }

    // Save.
    $('#sfco-builder-save').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving…');
        $status.text('').css('color', '');
        $.post(sfcoBuilder.ajaxUrl, {
            action:  'sfco_save_form_fields',
            nonce:   sfcoBuilder.nonce,
            form_id: sfcoBuilder.formId,
            fields:  JSON.stringify(state)
        }).done(function (resp) {
            if (resp && resp.success) {
                $status.text('Saved ' + resp.data.count + ' field' + (resp.data.count === 1 ? '' : 's')).css('color', '#2F8137');
            } else {
                $status.text('Save failed: ' + (resp && resp.data ? resp.data : 'unknown')).css('color', '#b32d2e');
            }
        }).fail(function (xhr) {
            $status.text('Request failed (' + xhr.status + ')').css('color', '#b32d2e');
        }).always(function () {
            $btn.prop('disabled', false).text('Save Fields');
            setTimeout(function(){ $status.text(''); }, 4000);
        });
    });

    render();
});
