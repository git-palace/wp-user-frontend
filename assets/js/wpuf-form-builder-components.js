;(function($) {
'use strict';

Vue.component('builder-stage', {
    template: '#tmpl-wpuf-builder-stage',

    mixins: wpuf_form_builder_mixins(wpuf_mixins.builder_stage),

    computed: {
        form_fields: function () {
            return this.$store.state.form_fields;
        },

        field_settings: function () {
            return this.$store.state.field_settings;
        },

        hidden_fields: function () {
            return this.$store.state.form_fields.filter(function (item) {
                return 'hidden' === item.input_type;
            });
        },

        editing_form_id: function () {
            return this.$store.state.editing_field_id;
        },

        pro_link: function () {
            return wpuf_form_builder.pro_link;
        }
    },

    mounted: function () {
        var self = this;

        // bind jquery ui sortable
        $('#form-preview-stage .wpuf-form.sortable-list').sortable({
            placeholder: 'form-preview-stage-dropzone',
            items: '.field-items',
            handle: '.control-buttons .move',
            scroll: true,
            update: function (e, ui) {
                var item    = ui.item[0],
                    data    = item.dataset,
                    source  = data.source,
                    toIndex = parseInt($(ui.item).index()),
                    payload = {
                        toIndex: toIndex
                    };

                if ('panel' === source) {
                    // prepare the payload to add new form element
                    var field_template  = ui.item[0].dataset.formField,
                        field           = $.extend(true, {}, self.field_settings[field_template].field_props);

                    // add a random integer id
                    field.id = self.get_random_id();

                    payload.field = field;

                    // add new form element
                    self.$store.commit('add_form_field_element', payload);

                    // remove button from stage
                    $(this).find('.button.ui-draggable.ui-draggable-handle').remove();

                } else if ('stage' === source) {
                    payload.fromIndex = parseInt(data.index);

                    self.$store.commit('swap_form_field_elements', payload);
                }

            }
        });
    },

    methods: {
        open_field_settings: function(field_id) {
            this.$store.commit('open_field_settings', field_id);
        },

        clone_field: function(field_id, index) {
            var payload = {
                field_id: field_id,
                index: index,
                new_id: this.get_random_id()
            };

            this.$store.commit('clone_form_field_element', payload);
        },

        delete_field: function(index) {
            var self = this;

            self.warn({
                text: self.i18n.delete_field_warn_msg,
                confirmButtonText: self.i18n.yes_delete_it,
                cancelButtonText: self.i18n.no_cancel_it,
            }, function (is_confirm) {
                if (is_confirm) {
                    self.$store.commit('delete_form_field_element', index);
                }
            });
        },

        delete_hidden_field: function (field_id) {
            var i = 0;

            for (i = 0; i < this.form_fields.length; i++) {
                if (parseInt(field_id) === parseInt(this.form_fields[i].id)) {
                    this.delete_field(i);
                }
            }
        },

        is_pro_feature: function (template) {
            return (this.field_settings[template] && this.field_settings[template].pro_feature) ? true : false;
        },

        is_template_available: function (template) {
            if (this.field_settings[template]) {
                if (this.is_pro_feature(template)) {
                    return false;
                }

                return true;
            }

            return false;
        },

        is_full_width: function (template) {
            if (this.field_settings[template] && this.field_settings[template].is_full_width) {
                return true;
            }

            return false;
        }
    }
});

Vue.component('field-checkbox', {
    template: '#tmpl-wpuf-field-checkbox',

    mixins: [
        wpuf_mixins.option_field_mixin
    ],

    computed: {
        value: {
            get: function () {
                var value = this.editing_form_field[this.option_field.name];

                if (this.option_field.is_single_opt) {
                    var option = Object.keys(this.option_field.options)[0];

                    if (value === option) {
                        return true;

                    } else {
                        return false;
                    }
                }

                return this.editing_form_field[this.option_field.name];
            },

            set: function (value) {
                if (this.option_field.is_single_opt) {
                    value = value ? Object.keys(this.option_field.options)[0] : '';
                }


                this.$store.commit('update_editing_form_field', {
                    editing_field_id: this.editing_form_field.id,
                    field_name: this.option_field.name,
                    value: value
                });
            }
        }
    }
});

/**
 * Common settings component for option based fields
 * like select, multiselect, checkbox, radio
 */
Vue.component('field-option-data', {
    template: '#tmpl-wpuf-field-option-data',

    mixins: [
        wpuf_mixins.option_field_mixin
    ],

    data: function () {
        return {
            show_value: false,
            options: [],
            selected: [],

        };
    },

    computed: {
        field_options: function () {
            return this.editing_form_field.options;
        },

        field_selected: function () {
            return this.editing_form_field.selected;
        }
    },

    mounted: function () {
        var self = this;

        this.set_options();

        $(this.$el).find('.option-field-option-chooser').sortable({
            items: '.option-field-option',
            handle: '.sort-handler',
            update: function (e, ui) {
                var item        = ui.item[0],
                    data        = item.dataset,
                    toIndex     = parseInt($(ui.item).index()),
                    fromIndex   = parseInt(data.index);

                self.options.swap(fromIndex, toIndex);
            }
        }).disableSelection();
    },

    methods: {
        set_options: function () {
            var self = this;
            var field_options = $.extend(true, {}, this.editing_form_field.options);

            _.each(field_options, function (label, value) {
                self.options.push({label: label, value: value, id: self.get_random_id()});
            });

            if (this.option_field.is_multiple && !_.isArray(this.field_selected)) {
                this.selected = [this.field_selected];
            } else {
                this.selected = this.field_selected;
            }
        },

        // in case of select or radio buttons, user should deselect default value
        clear_selection: function (e, label) {
            if (label === this.selected) {
                this.selected = '';
                $(e.target).prop('checked', false);
            }
        },

        add_option: function () {
            var count   = this.options.length,
                new_opt = this.i18n.option + ' - ' + (count + 1);

            this.options.push({
                label: new_opt , value: new_opt, id: this.get_random_id()
            });
        },

        delete_option: function (index) {
            if (this.options.length === 1) {
                this.warn({
                    text: this.i18n.last_choice_warn_msg,
                    showCancelButton: false,
                    confirmButtonColor: "#46b450",
                });

                return;
            }

            this.options.splice(index, 1);
        }
    },

    watch: {
        options: {
            deep: true,
            handler: function (new_opts) {
                var options = {},
                    i = 0;

                for (i = 0; i < new_opts.length; i++) {
                    // if (!new_opts[i].value.trim()) {
                    //     new_opts[i].value = 'val_' + this.get_random_id();
                    // }

                    options[new_opts[i].value] = new_opts[i].label;
                }

                this.update_value('options', options);
            }
        },

        selected: function (new_val) {
            this.update_value('selected', new_val);
        }
    }
});

/**
 * Sidebar field options panel
 */
Vue.component('field-options', {
    template: '#tmpl-wpuf-field-options',

    data: function() {
        return {
            show_basic_settings: true,
            show_advanced_settings: false
        };
    },

    computed: {
        editing_field_id: function () {
            this.show_basic_settings = true;
            this.show_advanced_settings = false;

            return parseInt(this.$store.state.editing_field_id);
        },

        editing_form_field: function () {
            var self = this;
            return _.find(this.$store.state.form_fields, function (item) {
                return parseInt(item.id) === parseInt(self.editing_field_id);
            });
        },

        settings: function() {
            var settings = this.$store.state.field_settings[this.editing_form_field.template].settings;

            return _.sortBy(settings, function (item) {
                return parseInt(item.priority);
            });
        },

        basic_settings: function () {
            return this.settings.filter(function (item) {
                return 'basic' === item.section;
            });
        },

        advanced_settings: function () {
            return this.settings.filter(function (item) {
                return 'advanced' === item.section;
            });
        },

        form_field_type_title: function() {
            return this.$store.state.field_settings[this.editing_form_field.template].title;
        }
    }
});

Vue.component('field-radio', {
    template: '#tmpl-wpuf-field-radio',

    mixins: [
        wpuf_mixins.option_field_mixin
    ],

    computed: {
        value: {
            get: function () {
                return this.editing_form_field[this.option_field.name];
            },

            set: function (value) {
                this.$store.commit('update_editing_form_field', {
                    editing_field_id: this.editing_form_field.id,
                    field_name: this.option_field.name,
                    value: value
                });
            }
        }
    }
});

Vue.component('field-text', {
    template: '#tmpl-wpuf-field-text',

    mixins: [
        wpuf_mixins.option_field_mixin
    ],

    computed: {
        value: {
            get: function () {
                return this.editing_form_field[this.option_field.name];
            },

            set: function (value) {
                this.update_value(this.option_field.name, value);
            }
        }
    },

    methods: {
        on_focusout: function (e) {
            wpuf_form_builder.event_hub.$emit('field-text-focusout', e, this);
        }
    }
});

Vue.component('field-text-meta', {
    template: '#tmpl-wpuf-field-text-meta',

    mixins: [
        wpuf_mixins.option_field_mixin
    ],

    computed: {
        value: {
            get: function () {
                return this.editing_form_field[this.option_field.name];
            },

            set: function (value) {
                this.update_value(this.option_field.name, value);
            }
        }
    },

    created: function () {
        if ('yes' === this.editing_form_field.is_meta) {
            wpuf_form_builder.event_hub.$on('field-text-focusout', this.meta_key_autocomplete);
        }
    },

    methods: {
        on_focusout: function (e) {
            wpuf_form_builder.event_hub.$emit('field-text-focusout', e, this);
        },

        meta_key_autocomplete: function (e, label_vm) {
            if (
                'label' === label_vm.option_field.name &&
                !this.value.trim() &&
                parseInt(this.editing_form_field.id) === parseInt(label_vm.editing_form_field.id)
            ) {
                this.value = label_vm.value.replace(/\W/g, '_').toLowerCase();
            }
        }
    }
});

/**
 * Field template: Checkbox
 */
Vue.component('form-checkbox_field', {
    template: '#tmpl-wpuf-form-checkbox_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Hidden
 */
Vue.component('form-custom_hidden_field', {
    template: '#tmpl-wpuf-form-custom_hidden_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Dropdown/Select
 */
Vue.component('form-dropdown_field', {
    template: '#tmpl-wpuf-form-dropdown_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Email
 */
Vue.component('form-email_address', {
    template: '#tmpl-wpuf-form-email_address',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Sidebar form fields panel
 */
Vue.component('form-fields', {
    template: '#tmpl-wpuf-form-fields',

    mixins: wpuf_form_builder_mixins(wpuf_mixins.form_fields),

    computed: {
        panel_sections: function () {
            return this.$store.state.panel_sections;
        },

        field_settings: function () {
            return this.$store.state.field_settings;
        },
    },

    mounted: function () {
        // bind jquery ui draggable
        $(this.$el).find('.panel-form-field-buttons .button').draggable({
            connectToSortable: '#form-preview-stage .wpuf-form',
            helper: 'clone',
            revert: 'invalid',
            cancel: '.button-pro-feature',
        }).disableSelection();
    },

    methods: {
        panel_toggle: function (index) {
            this.$store.commit('panel_toggle', index);
        },

        add_form_field: function (field_template) {
            var payload = {
                toIndex: this.$store.state.form_fields.length,
            };

            var field = $.extend(true, {}, this.$store.state.field_settings[field_template].field_props);

            field.id = this.get_random_id();

            payload.field = field;

            // add new form element
            this.$store.commit('add_form_field_element', payload);
        },

        is_pro_feature: function (field) {
            return this.field_settings[field].pro_feature;
        },

        alert_pro_feature: function (field) {
            var title = this.field_settings[field].title;

            swal({
                title: '<i class="fa fa-lock"></i> ' + title + ' <br>' + this.i18n.is_a_pro_feature,
                text: this.i18n.pro_feature_msg,
                type: '',
                html: true,
                showCancelButton: true,
                cancelButtonText: this.i18n.close,
                confirmButtonColor: '#46b450',
                confirmButtonText: this.i18n.upgrade_to_pro
            }, function (is_confirm) {
                if (is_confirm) {
                    window.open(wpuf_form_builder.pro_link, '_blank');
                }
            });
        },

        is_failed_to_validate: function (field) {
            var validator = this.field_settings[field].validator;

            if (validator && validator.callback && !this[validator.callback]()) {
                return true;
            }

            return false;
        },

        alert_invalidate_msg: function (field) {
            var validator = this.field_settings[field].validator;

            if (validator && validator.msg) {
                this.warn({
                    title: validator.msg_title || '',
                    text: validator.msg,
                    html: true,
                    type: 'warning',
                    showCancelButton: false,
                    confirmButtonColor: '#46b450',
                    confirmButtonText: this.i18n.ok
                });
            }
        }
    }
});

/**
 * Field template: Image Upload
 */
Vue.component('form-image_upload', {
    template: '#tmpl-wpuf-form-image_upload',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Multi-Select
 */
Vue.component('form-multiple_select', {
    template: '#tmpl-wpuf-form-multiple_select',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Radio
 */
Vue.component('form-radio_field', {
    template: '#tmpl-wpuf-form-radio_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Text
 */
Vue.component('form-text_field', {
    template: '#tmpl-wpuf-form-text_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

Vue.component('form-textarea_field', {
    template: '#tmpl-wpuf-form-textarea_field',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

/**
 * Field template: Website URL
 */
Vue.component('form-website_url', {
    template: '#tmpl-wpuf-form-website_url',

    mixins: [
        wpuf_mixins.form_field_mixin
    ]
});

Vue.component('help-text', {
    template: '#tmpl-wpuf-help-text',

    props: {
        text: {
            type: String,
            default: ''
        }
    },

    mounted: function () {

    }
});

})(jQuery);
