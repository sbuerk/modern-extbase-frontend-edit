/**
 * One field of one record: its value, its control, and the errors about it.
 *
 * The element is deliberately dumb. It holds no state of its own, decides
 * nothing about saving and knows neither the endpoints nor the profile — it
 * receives what to show and reports what happened, and the record element above
 * it owns the session, the requests and the truth. That is what keeps the
 * interesting logic in modules a test can call without a browser.
 *
 * Four events, all of them statements of intent rather than of outcome:
 *
 * | Event          | Meaning                                                  |
 * |----------------|----------------------------------------------------------|
 * | `field-edit`   | switch this field to a control                          |
 * | `field-input`  | the control's value changed, `detail.value` carries it   |
 * | `field-apply`  | apply — Enter, or the apply button                       |
 * | `field-cancel` | cancel — Escape, or the cancel button                    |
 *
 * `field-apply` and `field-cancel` are the same events in both editing modes.
 * A whole record edit hides the per field buttons and answers them by
 * submitting or cancelling the record, which is why the keyboard behaves the
 * same in both modes without this element knowing which one it is in.
 */
import { css, html, LitElement, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { customElement, property, query } from 'lit/decorators.js';
import { controls } from '@sbuerk/modern-extbase-frontend-edit/frontend/style/controls.js';
import { field } from '@sbuerk/modern-extbase-frontend-edit/frontend/style/field.js';
import type { FieldDefinition } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/fieldDefinitions.js';
import type { LabelMap } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';
import { actionLabelKey, choiceLabelKey, fieldLabelKey, label } from '@sbuerk/modern-extbase-frontend-edit/frontend/model/labels.js';

/**
 * The controls a field can be edited with, as far as focus and value reading
 * are concerned.
 */
type EditControl = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

@customElement('modern-extbase-frontend-edit-field')
export class EditFieldElement extends LitElement {
    /*
     * Everything this element draws is the shared field chrome, so its own block
     * is one declaration: the custom element is inline by default and has to be
     * told otherwise before any of the layout below it applies.
     */
    public static override readonly styles = [
        controls,
        field,
        css`
            :host {
                display: block;
            }
        `,
    ];

    /**
     * What kind of control this field is edited with. Never `null` in practice
     * — the record element renders one row per definition — but a default keeps
     * the element usable before its properties are set.
     */
    @property({ attribute: false })
    public definition: FieldDefinition = { name: '', control: 'line' };

    /**
     * `profile`, `address` or `email`: the first segment of every label key of
     * this field.
     */
    @property({ type: String })
    public scope = 'profile';

    @property({ attribute: false })
    public labels: LabelMap = {};

    /**
     * The last server known value, shown when the field is not being edited.
     */
    @property({ type: String })
    public serverValue = '';

    /**
     * What is in the control, which is not the same thing.
     */
    @property({ type: String })
    public draftValue = '';

    @property({ type: Boolean, reflect: true })
    public editing = false;

    @property({ type: Boolean, reflect: true })
    public busy = false;

    /**
     * Whether this field is part of a whole record edit, which owns the apply
     * and cancel affordances for all of its fields at once.
     */
    @property({ type: Boolean })
    public recordMode = false;

    @property({ attribute: false })
    public errors: readonly string[] = [];

    @query('.field-control')
    private readonly control!: EditControl | null;

    public override render(): TemplateResult {
        const hasErrors = this.errors.length > 0;

        return html`
            <div class="field">
                <span class="field-label" id="label">${label(this.labels, fieldLabelKey(this.scope, this.definition.name))}</span>
                <div class="field-body">
                    ${this.editing ? this.renderControl(hasErrors) : this.renderValue()}
                    ${this.renderActions()}
                </div>
                ${hasErrors ? this.renderErrors() : nothing}
            </div>
        `;
    }

    /**
     * Moves the focus into the control, if there is one.
     *
     * Focus is driven from the record element rather than from here, because
     * only it knows which of several fields a whole record edit should start
     * on, and because it is also what puts the focus back on the field the
     * server complained about after a rejected save.
     */
    public focusControl(): void {
        this.control?.focus();
    }

    /**
     * Keeps a select in sync with its draft.
     *
     * The other controls take their value through a `.value` property binding,
     * which a `<select>` cannot use: the binding is committed before the
     * `<option>` children exist, so the assignment finds nothing to select. The
     * `selected` attribute on the options covers the first render and stops
     * working as soon as the user has touched the control, which makes the
     * initial value the only thing it can be trusted for.
     */
    protected override updated(): void {
        if (!this.editing || this.definition.control !== 'choice') {
            return;
        }
        const control = this.control;
        if (control instanceof HTMLSelectElement && control.value !== this.draftValue) {
            control.value = this.draftValue;
        }
    }

    private renderValue(): TemplateResult {
        const value = this.displayValue();

        return html`<span class="field-value ${value === '' ? 'is-empty' : ''}">${value}</span>`;
    }

    /**
     * The stored value as it is shown when the field is not being edited.
     *
     * A select shows the label of its value rather than the value itself, the
     * same lookup the read only partials do through the database label file. A
     * date shows the stored `Y-m-d` string: the read view formats it with the
     * installation wide setting, which is an integrator decision this element
     * has no access to, and inventing a second format here would make the two
     * views disagree about what is stored.
     */
    private displayValue(): string {
        if (this.definition.control !== 'choice' || this.serverValue === '') {
            return this.serverValue;
        }

        return label(this.labels, choiceLabelKey(this.scope, this.definition.name, this.serverValue));
    }

    private renderControl(hasErrors: boolean): TemplateResult {
        const shared = {
            invalid: hasErrors ? 'true' : 'false',
            describedBy: hasErrors ? 'errors' : undefined,
        };

        if (this.definition.control === 'choice') {
            return html`
                <select
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${shared.invalid}"
                    aria-describedby="${shared.describedBy ?? nothing}"
                    ?disabled="${this.busy}"
                    @change="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                >
                    ${(this.definition.choices ?? []).map((choice: string): TemplateResult => html`
                        <option value="${choice}" ?selected="${choice === this.draftValue}">
                            ${label(this.labels, choiceLabelKey(this.scope, this.definition.name, choice))}
                        </option>
                    `)}
                </select>
            `;
        }

        if (this.definition.control === 'text') {
            return html`
                <textarea
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${shared.invalid}"
                    aria-describedby="${shared.describedBy ?? nothing}"
                    maxlength="${this.definition.maxLength ?? nothing}"
                    ?disabled="${this.busy}"
                    .value="${this.draftValue}"
                    @input="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                ></textarea>
            `;
        }

        return html`
            <input
                class="field-control"
                type="${this.definition.control === 'date' ? 'date' : 'text'}"
                aria-labelledby="label"
                aria-invalid="${shared.invalid}"
                aria-describedby="${shared.describedBy ?? nothing}"
                maxlength="${this.definition.maxLength ?? nothing}"
                ?disabled="${this.busy}"
                .value="${this.draftValue}"
                @input="${this.onControlInput}"
                @keydown="${this.onKeyDown}"
            />
        `;
    }

    /**
     * The per field affordances.
     *
     * None of them in a whole record edit: the record's own submit and cancel
     * are the only ones there, and a second pair meaning something narrower
     * next to them is how a user ends up saving one field while believing they
     * saved five.
     */
    private renderActions(): TemplateResult | typeof nothing {
        if (this.recordMode) {
            return nothing;
        }

        // Every button is described by the field label, so that a screen
        // reader announces "Edit, first name" rather than the same bare "Edit"
        // once per field.
        if (!this.editing) {
            return html`
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onEdit}">
                    ${label(this.labels, actionLabelKey('edit'))}
                </button>
            `;
        }

        return html`
            <span class="field-actions">
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onApply}">
                    ${label(this.labels, actionLabelKey('apply'))}
                </button>
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onCancel}">
                    ${label(this.labels, actionLabelKey('cancel'))}
                </button>
            </span>
        `;
    }

    private renderErrors(): TemplateResult {
        return html`
            <ul class="field-errors" id="errors" role="alert">
                ${this.errors.map((message: string): TemplateResult => html`<li>${message}</li>`)}
            </ul>
        `;
    }

    private onControlInput(event: Event): void {
        const control = event.target as EditControl;
        this.emit('field-input', { value: control.value });
    }

    /**
     * Enter applies, Escape cancels.
     *
     * Enter is not bound in a textarea, where it is a newline and where taking
     * it away would make a biography a single line. Escape is bound everywhere,
     * because a control a user cannot get out of with the keyboard is a trap.
     */
    private onKeyDown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.emit('field-cancel');

            return;
        }
        if (event.key === 'Enter' && this.definition.control !== 'text') {
            event.preventDefault();
            this.emit('field-apply');
        }
    }

    private onEdit(): void {
        this.emit('field-edit');
    }

    private onApply(): void {
        this.emit('field-apply');
    }

    private onCancel(): void {
        this.emit('field-cancel');
    }

    private emit(type: string, detail: Record<string, unknown> = {}): void {
        this.dispatchEvent(
            new CustomEvent(type, {
                detail: { field: this.definition.name, ...detail },
                bubbles: true,
                composed: true,
            }),
        );
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'modern-extbase-frontend-edit-field': EditFieldElement;
    }
}
