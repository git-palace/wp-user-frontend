<div id="form-preview-stage">
    <h4 v-if="!form_fields.length" class="text-center">
        <?php _e( 'Add fields by dragging the fields from the right sidebar to this area.', 'wpuf' ) ?>
    </h4>

    <ul :class="['wpuf-form', 'sortable-list', 'form-label-' + label_type]">
        <li
            v-for="(field, index) in form_fields"
            :key="field.id"
            :class="[
                'field-items', 'wpuf-el', field.name, field.css, 'form-field-' + field.template,
                ('custom_hidden_field' === field.template) ? 'hidden-field' : '',
                parseInt(editing_form_id) === parseInt(field.id) ? 'current-editing' : ''
            ]"
            :data-index="index"
            data-source="stage"
        >
            <div v-if="!is_full_width(field.template)" class="wpuf-label">
                <label v-if="!is_invisible(field)" :for="'wpuf-' + field.name ? field.name : 'cls'">
                    {{ field.label }} <span v-if="field.required && 'yes' === field.required" class="required">*</span>
                </label>
            </div>

            <component v-if="is_template_available(field)" :is="'form-' + field.template" :field="field"></component>

            <div v-if="is_pro_feature(field.template)" class="stage-pro-alert">
                <label class="wpuf-pro-text-alert">
                    <a :href="pro_link" target="_blank"><strong>{{ get_field_name(field.template) }}</strong> <?php _e( 'is available in Pro Version', 'wpuf' ); ?></a>
                </label>
            </div>

            <div v-if="!is_failed_to_validate(field.template)" class="control-buttons">
                <p>
                    <i class="fa fa-arrows move"></i>
                    <i class="fa fa-pencil" @click="open_field_settings(field.id)"></i>
                    <i class="fa fa-clone" @click="clone_field(field.id, index)"></i>
                    <i class="fa fa-trash-o" @click="delete_field(index)"></i>
                </p>
            </div>
        </li>

        <li v-if="!form_fields.length" class="field-items empty-list-item"></li>

        <li class="wpuf-submit">
            <div class="wpuf-label">&nbsp;</div>

            <?php do_action( 'wpuf-form-builder-template-builder-stage-submit-area' ); ?>
        </li>
    </ul><!-- .wpuf-form -->

    <div v-if="hidden_fields.length" class="hidden-field-list">
        <h4><?php _e( 'Hidden Fields', 'wpuf' ); ?></h4>

        <ul class="wpuf-form">
            <li
                v-for="(field, index) in hidden_fields"
                :class="['field-items', parseInt(editing_form_id) === parseInt(field.id) ? 'current-editing' : '']"
            >
                <strong><?php _e('key', 'wpuf'); ?></strong>: {{ field.name }} | <strong><?php _e( 'value', 'wpuf' ); ?></strong>: {{ field.meta_value }}

                <div class="control-buttons">
                    <p>
                        <i class="fa fa-pencil" @click="open_field_settings(field.id)"></i>
                        <i class="fa fa-clone" @click="clone_field(field.id, index)"></i>
                        <i class="fa fa-trash-o" @click="delete_hidden_field(field.id)"></i>
                    </p>
                </div>
            </li>
        </ul>
    </div>

    <?php do_action( 'wpuf-form-builder-template-builder-stage-bottom-area' ); ?>
</div><!-- #form-preview-stage -->
