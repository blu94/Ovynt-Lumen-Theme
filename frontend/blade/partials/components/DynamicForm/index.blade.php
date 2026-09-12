<div id="{{ $uid }}" class="lumen-form" v-cloak>
    @if($intro !== '')
        <p class="lumen-form__desc">{{ $intro }}</p>
    @endif

    <p v-if="loading" class="lumen-form__state">@{{ labels.loading }}</p>

    <p v-else-if="loadError" class="lumen-form__state lumen-form__state--error" role="alert">@{{ loadError }}</p>

    <form v-else-if="schema" class="lumen-form__body" @submit.prevent="submitForm" novalidate>
        <h3 v-if="showTitle && schema.title" class="lumen-form__title">@{{ t(schema.title) }}</h3>
        <p v-if="showTitle && schema.description" class="lumen-form__desc">@{{ t(schema.description) }}</p>

        <div v-for="field in inputs" :key="field.id" class="lumen-form__field">
            <template v-if="['text', 'email', 'number', 'date', 'url', 'tel'].includes(field.type)">
                <label class="lumen-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <input :type="field.type" class="lumen-form__input" :id="fieldId(field)"
                       :required="isRequired(field)" :readonly="isLocked(field)"
                       v-model="formData[field.id]">
                <p v-if="t(field.description)" class="lumen-form__hint">@{{ t(field.description) }}</p>
            </template>

            <template v-else-if="field.type === 'textarea'">
                <label class="lumen-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <textarea class="lumen-form__input" :id="fieldId(field)" rows="4"
                          :required="isRequired(field)" :readonly="isLocked(field)"
                          v-model="formData[field.id]"></textarea>
                <p v-if="t(field.description)" class="lumen-form__hint">@{{ t(field.description) }}</p>
            </template>

            <template v-else-if="field.type === 'select'">
                <label class="lumen-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <select class="lumen-form__input" :id="fieldId(field)" :required="isRequired(field)" v-model="formData[field.id]">
                    <option value="" disabled>@{{ labels.choose }}</option>
                    <option v-for="opt in options(field)" :key="opt" :value="opt">@{{ opt }}</option>
                </select>
                <p v-if="t(field.description)" class="lumen-form__hint">@{{ t(field.description) }}</p>
            </template>

            <template v-else-if="field.type === 'checkbox'">
                <span class="lumen-form__label">@{{ t(field.title) }}</span>
                <div class="lumen-form__choices">
                    <label v-for="opt in options(field)" :key="opt" class="lumen-form__choice">
                        <input type="checkbox" :value="opt" v-model="checks[field.id]">
                        <span>@{{ opt }}</span>
                    </label>
                </div>
                <p v-if="t(field.description)" class="lumen-form__hint">@{{ t(field.description) }}</p>
            </template>

            <template v-else-if="field.type === 'link'">
                <a :href="(field.data && field.data.options) || '#'" class="lumen-form__link" target="_blank" rel="noopener">@{{ t(field.title) }}</a>
            </template>

            <template v-else-if="field.type === 'submit'">
                <button type="submit" class="lumen-btn lumen-form__submit" :disabled="submitting">
                    @{{ submitting ? labels.submitting : (t(field.title) || labels.submit) }}
                </button>
            </template>
        </div>

        {{-- A form built without a submit field still needs a way to send. --}}
        <div v-if="!hasSubmitField" class="lumen-form__field">
            <button type="submit" class="lumen-btn lumen-form__submit" :disabled="submitting">
                @{{ submitting ? labels.submitting : labels.submit }}
            </button>
        </div>

        <p v-if="success" class="lumen-form__state lumen-form__state--success" role="status">@{{ success }}</p>
        <p v-if="submitError" class="lumen-form__state lumen-form__state--error" role="alert">@{{ submitError }}</p>
    </form>
</div>

<script>
(function () {
    const { createApp, ref, reactive, computed, onMounted } = Vue;
    const payload = @json($payload);

    createApp({
        setup() {
            const schema      = ref(null);
            const formData    = reactive({});
            const checks      = reactive({});
            const loading     = ref(true);
            const loadError   = ref(null);
            const submitError = ref(null);
            const success     = ref(null);
            const submitting  = ref(false);
            const labels      = payload.labels;
            const showTitle   = payload.showTitle !== false;
            const prefill     = payload.prefill || {};
            const locked      = payload.locked || [];

            const t = (val) => {
                if (!val) return '';
                if (typeof val === 'string') return val;
                return val[payload.locale] || val.en || Object.values(val)[0] || '';
            };

            // The same rule core applies to a submitted key it cannot match by id: the
            // label, lowercased, with runs of anything else collapsed to one underscore.
            const slugKey = (label) => String(label || '').toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

            const fieldKey   = (field) => slugKey(t(field.title));
            const fieldId    = (field) => (field.data && field.data.elementId) || (payload.slug + '-field-' + field.id);
            const isRequired = (field) => !!(field.data && field.data.required);
            const isLocked   = (field) => locked.includes(fieldKey(field));

            const options = (field) => {
                const raw = field.data && field.data.options;
                if (!raw) return [];
                return String(raw).split(',').map(s => s.trim()).filter(Boolean);
            };

            // The fields relation carries every meta row the form owns; only inputs are drawn.
            const inputs = computed(() =>
                ((schema.value && schema.value.fields) || []).filter(f => f.type !== 'SEO_DATA'));

            const hasSubmitField = computed(() => inputs.value.some(f => f.type === 'submit'));

            const resetData = () => {
                inputs.value.forEach((f) => {
                    // A button and a hyperlink carry no answer; posting them files an empty
                    // value under their id on every lead.
                    if (f.type === 'submit' || f.type === 'link') return;
                    if (f.type === 'checkbox') {
                        checks[f.id] = [];
                        return;
                    }
                    const key = fieldKey(f);
                    formData[f.id] = Object.prototype.hasOwnProperty.call(prefill, key) ? prefill[key] : '';
                });
            };

            const load = async () => {
                if (!window.ThemeApi || !window.ThemeApi.forms) {
                    loadError.value = labels.loadFailed;
                    loading.value = false;
                    return;
                }
                try {
                    schema.value = await window.ThemeApi.forms.get(payload.slug);
                    resetData();
                } catch (err) {
                    loadError.value = labels.loadFailed;
                } finally {
                    loading.value = false;
                }
            };

            const submitForm = async () => {
                submitting.value = true;
                submitError.value = null;
                success.value = null;

                // Core keeps scalar values only, so a checked set travels as one string.
                const body = Object.assign({}, formData);
                Object.keys(checks).forEach((id) => { body[id] = (checks[id] || []).join(', '); });

                try {
                    const res = await window.ThemeApi.forms.submit(payload.slug, body);
                    success.value = (res && res.message) || labels.success;
                    resetData();
                    window.setTimeout(() => { success.value = null; }, 6000);
                } catch (err) {
                    const errors = err && err.data && err.data.errors;
                    const first  = errors && Object.values(errors).flat()[0];
                    submitError.value = first || (err && err.data && err.data.message) || labels.failed;
                } finally {
                    submitting.value = false;
                }
            };

            onMounted(load);

            return { schema, inputs, formData, checks, loading, loadError, submitError, success, submitting, labels, showTitle, t, fieldId, isRequired, isLocked, options, hasSubmitField, submitForm };
        },
    }).mount('#{{ $uid }}');
})();
</script>
