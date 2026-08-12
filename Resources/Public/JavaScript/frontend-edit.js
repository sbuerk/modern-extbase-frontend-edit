/* Generated from Build/Sources/TypeScript — do not edit. */
var Me=Object.defineProperty;var Le=Object.getOwnPropertyDescriptor;var l=(r,i,e,t)=>{for(var n=t>1?void 0:t?Le(i,e):i,o=r.length-1,d;o>=0;o--)(d=r[o])&&(n=(t?d(i,e,n):d(n))||n);return t&&n&&Me(i,e,n),n};var re="frontend-edit-loaded";function D(r){r.classList.add(re)}import{css as ze,html as u,LitElement as Je,nothing as _}from"lit";import{customElement as Ge,state as ee}from"lit/decorators.js";import{repeat as Xe}from"lit/directives/repeat.js";var te=["address","email"];var ie=Object.freeze({child:null,childUid:null});function $(r,i){return{child:r,childUid:i}}function K(r){return{child:r,childUid:null}}function I(r){return r.child!==null&&r.childUid===null}function T(r){return r.child===null?"profile":`${r.child}:${r.childUid??"new"}`}function ne(r){return r.child??"profile"}var Ae=[{name:"shortname",control:"line",maxLength:255},{name:"firstname",control:"line",maxLength:255},{name:"lastname",control:"line",maxLength:255},{name:"birthday",control:"date"},{name:"bio",control:"text",maxLength:5e3}],je=[{name:"type",control:"choice",choices:["home","work","others"],initial:"others"},{name:"line1",control:"line",maxLength:255},{name:"line2",control:"line",maxLength:255}],Fe=[{name:"type",control:"choice",choices:["private","business","others"],initial:"others"},{name:"email",control:"line",maxLength:255}];function M(r){switch(r){case"address":return je;case"email":return Fe;default:return Ae}}function x(r){return M(r.child)}function S(r){let i={};for(let e of r)i[e.name]=e.initial??"";return i}function L(r){if(!U(r))return null;let i=B(r.uid);return i===null?null:{uid:i,shortname:h(r.shortname),firstname:h(r.firstname),lastname:h(r.lastname),birthday:h(r.birthday),bio:h(r.bio),hidden:r.hidden===!0,addresses:oe(r.addresses,Oe),emails:oe(r.emails,Ve)}}function Oe(r){if(!U(r))return null;let i=B(r.uid);return i===null?null:{uid:i,type:h(r.type),line1:h(r.line1),line2:h(r.line2),hidden:r.hidden===!0}}function Ve(r){if(!U(r))return null;let i=B(r.uid);return i===null?null:{uid:i,type:h(r.type),email:h(r.email),hidden:r.hidden===!0}}function De(r,i){return i.child===null?r:i.childUid===null?null:de(r,i.child,i.childUid)}function de(r,i,e){return A(r,i).find(t=>t.uid===e)??null}function A(r,i){return i==="address"?r.addresses:r.emails}function q(r,i){return A(r,i).map(e=>e.uid)}function C(r,i,e){let t=De(r,i);if(t===null)return"";let n=t[e];return typeof n=="string"?n:""}function se(r,i){let e={};for(let t of x(i))e[t.name]=C(r,i,t.name);return e}function le(r,i,e){var t;return((t=de(r,i,e))==null?void 0:t.hidden)??!1}function ae(r,i,e,t){let n=q(r,i),o=n.indexOf(e);if(o===-1)return n;let d=o+t;if(d<0||d>=n.length)return n;let s=[...n];return s.splice(o,1),s.splice(d,0,e),s}function oe(r,i){if(!Array.isArray(r))return[];let e=[];for(let t of r){let n=i(t);n!==null&&e.push(n)}return e}function B(r){return typeof r=="number"&&Number.isInteger(r)&&r>0?r:null}function h(r){return typeof r=="string"?r:""}function U(r){return typeof r=="object"&&r!==null&&!Array.isArray(r)}var E={mode:"field",fields:[],drafts:{},fieldErrors:{},generalErrors:[],busy:!1};function ce(){return new Map}function a(r,i){return r.get(T(i))??null}function j(r,i){var e;return((e=a(r,i))==null?void 0:e.busy)??!1}function k(r,i,e,t){let n=a(r,i);return n===null||!(e in n.drafts)?t:n.drafts[e]??t}function H(r,i,e){var t;return((t=a(r,i))==null?void 0:t.fieldErrors[e])??[]}function ue(r,i){var e;return((e=a(r,i))==null?void 0:e.generalErrors)??[]}function pe(r,i,e,t){let n=a(r,i)??E;return n.fields.includes(e)?r:R(r,i,{...n,fields:[...n.fields,e],drafts:{...n.drafts,[e]:t},fieldErrors:N(n.fieldErrors,e)})}function fe(r,i,e){return R(r,i,{...E,mode:"record",fields:Object.keys(e),drafts:{...e}})}function he(r,i,e,t){let n=a(r,i)??E;return R(r,i,{...n,drafts:{...n.drafts,[e]:t}})}function z(r,i,e){let t=a(r,i);return t===null?r:R(r,i,{...t,fields:t.fields.filter(n=>n!==e),drafts:N(t.drafts,e),fieldErrors:N(t.fieldErrors,e)})}function w(r,i){let e=new Map(r);return e.delete(T(i)),e}function J(r,i,e){let t=a(r,i)??E;return R(r,i,{...t,busy:e})}function G(r,i,e,t){let n=a(r,i)??E;return R(r,i,{...n,fieldErrors:{...e},generalErrors:[...t]})}function X(r,i){let e=a(r,i);return e===null?r:R(r,i,{...e,fieldErrors:{},generalErrors:[]})}function R(r,i,e){let t=new Map(r),n=T(i);return e.fields.length===0&&Object.keys(e.drafts).length===0&&e.generalErrors.length===0&&Object.keys(e.fieldErrors).length===0&&!e.busy?(t.delete(n),t):(t.set(n,e),t)}function N(r,i){let e={...r};return delete e[i],e}function ye(r){if(typeof r!="object"||r===null||Array.isArray(r))return{};let i={};for(let[e,t]of Object.entries(r))typeof t=="string"&&t!==""&&(i[e]=t);return i}function m(r,i){return r[i]??i}function me(r,i){return`field.${r}.${i}`}function Y(r,i,e){return`choice.${r}.${i}.${e}`}function p(r){return`action.${r}`}function be(r){return`section.${r}`}function Q(r){return`state.${r}`}function F(r){if(r===null||r.trim()==="")return null;try{return JSON.parse(r)}catch{return null}}var Ke=["save","saveField","addChild","removeChild","reorderChildren","setChildVisibility"];function ge(r){if(typeof r!="object"||r===null||Array.isArray(r))return null;let i=r,e={};for(let t of Ke){let n=i[t];if(typeof n!="string"||n==="")return null;e[t]=n}return e}function Re(r,i,e,t){return{...Ee(r,i),field:e,value:t}}function ve(r,i,e){return{...Ee(r,i),data:{...e}}}function Te(r,i,e){return{uid:r,child:i,data:{...e}}}function xe(r,i,e){return{uid:r,child:i,childUid:e}}function $e(r,i,e){return{uid:r,child:i,order:[...e]}}function Ce(r,i,e,t){return{uid:r,child:i,childUid:e,hidden:t}}function Ee(r,i){return i.child===null?{uid:r}:i.childUid===null?{uid:r,child:i.child}:{uid:r,child:i.child,childUid:i.childUid}}var ke=0;function we(r,i){if(r===200){let e=L(Se(i));return e===null?{kind:"error",status:r,codes:W(i)}:{kind:"success",profile:e}}if(r===422){let e=Ie(i);return Object.keys(e.fieldErrors).length===0&&e.generalErrors.length===0?{kind:"error",status:r,codes:W(i)}:{kind:"validation",...e}}return{kind:"error",status:r,codes:W(i)}}function Ie(r){let i={},e=[];for(let t of Pe(r)){let n=t.message;if(typeof n!="string"||n==="")continue;let o=t.field;if(typeof o!="string"||o===""){e.push(n);continue}(i[o]??=[]).push(n)}return{fieldErrors:i,generalErrors:e}}function W(r){let i=[];for(let e of Pe(r))typeof e.code=="number"&&i.push(e.code);return i}function Pe(r){return!Z(r)||!Array.isArray(r.errors)?[]:r.errors.filter(Z)}function Se(r){return Z(r)?r.data:null}function Z(r){return typeof r=="object"&&r!==null&&!Array.isArray(r)}var O=class{constructor(i,e,t=(n,o)=>fetch(n,o)){this.endpoints=i,this.requestToken=e,this.fetchImpl=t}async send(i,e){let t;try{t=await this.fetchImpl(this.endpoints[i],{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json",Accept:"application/json","X-TYPO3-RequestToken":this.requestToken},body:JSON.stringify(e)})}catch{return{kind:"error",status:ke,codes:[]}}return we(t.status,await qe(t))}};async function qe(r){try{return await r.json()}catch{return null}}import{css as Be,html as y,LitElement as Ue,nothing as v}from"lit";import{customElement as Ne,property as b,query as He}from"lit/decorators.js";var c=class extends Ue{constructor(){super(...arguments);this.definition={name:"",control:"line"};this.scope="profile";this.labels={};this.serverValue="";this.draftValue="";this.editing=!1;this.busy=!1;this.recordMode=!1;this.errors=[]}render(){let e=this.errors.length>0;return y`
            <div class="field">
                <span class="field-label" id="label">${m(this.labels,me(this.scope,this.definition.name))}</span>
                <div class="field-body">
                    ${this.editing?this.renderControl(e):this.renderValue()}
                    ${this.renderActions()}
                </div>
                ${e?this.renderErrors():v}
            </div>
        `}focusControl(){var e;(e=this.control)==null||e.focus()}updated(){if(!this.editing||this.definition.control!=="choice")return;let e=this.control;e instanceof HTMLSelectElement&&e.value!==this.draftValue&&(e.value=this.draftValue)}renderValue(){let e=this.displayValue();return y`<span class="field-value ${e===""?"is-empty":""}">${e}</span>`}displayValue(){return this.definition.control!=="choice"||this.serverValue===""?this.serverValue:m(this.labels,Y(this.scope,this.definition.name,this.serverValue))}renderControl(e){let t={invalid:e?"true":"false",describedBy:e?"errors":void 0};return this.definition.control==="choice"?y`
                <select
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${t.invalid}"
                    aria-describedby="${t.describedBy??v}"
                    ?disabled="${this.busy}"
                    @change="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                >
                    ${(this.definition.choices??[]).map(n=>y`
                        <option value="${n}" ?selected="${n===this.draftValue}">
                            ${m(this.labels,Y(this.scope,this.definition.name,n))}
                        </option>
                    `)}
                </select>
            `:this.definition.control==="text"?y`
                <textarea
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${t.invalid}"
                    aria-describedby="${t.describedBy??v}"
                    maxlength="${this.definition.maxLength??v}"
                    ?disabled="${this.busy}"
                    .value="${this.draftValue}"
                    @input="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                ></textarea>
            `:y`
            <input
                class="field-control"
                type="${this.definition.control==="date"?"date":"text"}"
                aria-labelledby="label"
                aria-invalid="${t.invalid}"
                aria-describedby="${t.describedBy??v}"
                maxlength="${this.definition.maxLength??v}"
                ?disabled="${this.busy}"
                .value="${this.draftValue}"
                @input="${this.onControlInput}"
                @keydown="${this.onKeyDown}"
            />
        `}renderActions(){return this.recordMode?v:this.editing?y`
            <span class="field-actions">
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onApply}">
                    ${m(this.labels,p("apply"))}
                </button>
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onCancel}">
                    ${m(this.labels,p("cancel"))}
                </button>
            </span>
        `:y`
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onEdit}">
                    ${m(this.labels,p("edit"))}
                </button>
            `}renderErrors(){return y`
            <ul class="field-errors" id="errors" role="alert">
                ${this.errors.map(e=>y`<li>${e}</li>`)}
            </ul>
        `}onControlInput(e){let t=e.target;this.emit("field-input",{value:t.value})}onKeyDown(e){if(e.key==="Escape"){e.preventDefault(),this.emit("field-cancel");return}e.key==="Enter"&&this.definition.control!=="text"&&(e.preventDefault(),this.emit("field-apply"))}onEdit(){this.emit("field-edit")}onApply(){this.emit("field-apply")}onCancel(){this.emit("field-cancel")}emit(e,t={}){this.dispatchEvent(new CustomEvent(e,{detail:{field:this.definition.name,...t},bubbles:!0,composed:!0}))}};c.styles=Be`
        :host {
            display: block;
        }

        .field {
            display: grid;
            gap: 0.25rem;
            padding: 0.25rem 0;
        }

        .field-label {
            font-weight: 600;
        }

        .field-body {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .field-value {
            flex: 1 1 12rem;
            min-width: 0;
            white-space: pre-wrap;
        }

        .field-value.is-empty::after {
            content: '—';
        }

        input,
        select,
        textarea {
            flex: 1 1 12rem;
            min-width: 0;
            font: inherit;
            padding: 0.25rem;
        }

        textarea {
            min-height: 6rem;
            resize: vertical;
        }

        .field-errors {
            margin: 0;
            padding: 0;
            list-style: none;
            color: #a4141a;
        }

        [aria-invalid='true'] {
            outline: 2px solid #a4141a;
        }

        :host([busy]) {
            opacity: 0.6;
        }
    `,l([b({attribute:!1})],c.prototype,"definition",2),l([b({type:String})],c.prototype,"scope",2),l([b({attribute:!1})],c.prototype,"labels",2),l([b({type:String})],c.prototype,"serverValue",2),l([b({type:String})],c.prototype,"draftValue",2),l([b({type:Boolean,reflect:!0})],c.prototype,"editing",2),l([b({type:Boolean,reflect:!0})],c.prototype,"busy",2),l([b({type:Boolean})],c.prototype,"recordMode",2),l([b({attribute:!1})],c.prototype,"errors",2),l([He(".field-control")],c.prototype,"control",2),c=l([Ne("modern-extbase-frontend-edit-field")],c);var g=class extends Je{constructor(){super(...arguments);this.profile=null;this.edits=ce();this.labels={};this.client=null;this.pendingFocus=null}connectedCallback(){super.connectedCallback(),this.initialize()}render(){let e=this.profile;return e===null?u`<slot></slot>`:u`
            ${this.renderRecord(e,ie)}
            ${te.map(t=>this.renderChildren(e,t))}
        `}updated(){let e=this.pendingFocus;if(e===null)return;this.pendingFocus=null;let t=this.renderRoot.querySelector(`[data-focus="${e}"]`);t!==null&&t.updateComplete.then(()=>{t.focusControl()})}initialize(){if(this.client!==null)return;let e=L(F(this.getAttribute("data-profile"))),t=ge(F(this.getAttribute("data-endpoints"))),n=this.getAttribute("data-token")??"";e===null||t===null||n===""||(this.labels=ye(F(this.getAttribute("data-labels"))),this.client=new O(t,n),this.profile=e)}renderRecord(e,t){let n=a(this.edits,t);return u`
            <div class="record">
                <div class="record-actions">
                    ${this.renderRecordActions(e,t,n)}
                    ${t.child===null&&e.hidden?u`<span class="state">${this.text(Q("hidden"))}</span>`:_}
                </div>
                ${this.renderGeneralErrors(t)}
                ${x(t).map(o=>this.renderField(e,t,o,n))}
            </div>
        `}renderRecordActions(e,t,n){return(n==null?void 0:n.mode)==="record"?u`
                <button type="button" ?disabled="${n.busy}" @click="${()=>void this.submitRecord(t)}">
                    ${this.text(p("save"))}
                </button>
                <button type="button" ?disabled="${n.busy}" @click="${()=>this.cancelRecord(t)}">
                    ${this.text(p("cancel"))}
                </button>
            `:u`
            <button
                type="button"
                ?disabled="${(n==null?void 0:n.busy)??!1}"
                @click="${()=>this.beginRecord(e,t)}"
            >
                ${this.text(p("editRecord"))}
            </button>
        `}renderField(e,t,n,o){let d=n.name,s=C(e,t,d);return u`
            <modern-extbase-frontend-edit-field
                data-focus="${P(t,d)}"
                .definition="${n}"
                .scope="${ne(t)}"
                .labels="${this.labels}"
                .serverValue="${s}"
                .draftValue="${k(this.edits,t,d,s)}"
                .editing="${(o==null?void 0:o.fields.includes(d))??!1}"
                .busy="${(o==null?void 0:o.busy)??!1}"
                .recordMode="${(o==null?void 0:o.mode)==="record"}"
                .errors="${H(this.edits,t,d)}"
                @field-edit="${()=>this.beginField(e,t,d)}"
                @field-input="${f=>this.onInput(t,d,f.detail.value)}"
                @field-apply="${()=>void this.applyField(t,d)}"
                @field-cancel="${()=>this.cancelField(t,d)}"
            ></modern-extbase-frontend-edit-field>
        `}renderChildren(e,t){let n=A(e,t);return u`
            <section class="children">
                <h3>${this.text(be(t))}</h3>
                <ol class="children-list">
                    ${Xe(n,o=>o.uid,(o,d)=>this.renderChild(e,t,o,d,n.length))}
                </ol>
                ${this.renderNewChild(t)}
            </section>
        `}renderChild(e,t,n,o,d){let s=$(t,n.uid),f=j(this.edits,s),V=le(e,t,n.uid);return u`
            <li class="child">
                ${this.renderRecord(e,s)}
                <div class="child-actions">
                    <button
                        type="button"
                        ?disabled="${f||o===0}"
                        @click="${()=>void this.moveChild(t,n.uid,-1)}"
                    >
                        ${this.text(p("moveUp"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${f||o===d-1}"
                        @click="${()=>void this.moveChild(t,n.uid,1)}"
                    >
                        ${this.text(p("moveDown"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${f}"
                        @click="${()=>void this.setChildVisibility(t,n.uid,!V)}"
                    >
                        ${this.text(p(V?"show":"hide"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${f}"
                        @click="${()=>void this.deleteChild(t,n.uid)}"
                    >
                        ${this.text(p("remove"))}
                    </button>
                    ${V?u`<span class="state">${this.text(Q("hidden"))}</span>`:_}
                </div>
            </li>
        `}renderNewChild(e){let t=K(e),n=S(M(e)),o=a(this.edits,t);return u`
            <div class="child child-new">
                ${this.renderGeneralErrors(t)}
                ${M(e).map(d=>{let s=d.name;return u`
                        <modern-extbase-frontend-edit-field
                            data-focus="${P(t,s)}"
                            .definition="${d}"
                            .scope="${e}"
                            .labels="${this.labels}"
                            .serverValue="${""}"
                            .draftValue="${k(this.edits,t,s,n[s]??"")}"
                            .editing="${!0}"
                            .busy="${(o==null?void 0:o.busy)??!1}"
                            .recordMode="${!0}"
                            .errors="${H(this.edits,t,s)}"
                            @field-input="${f=>this.onInput(t,s,f.detail.value)}"
                            @field-apply="${()=>void this.addChild(e)}"
                            @field-cancel="${()=>this.cancelRecord(t)}"
                        ></modern-extbase-frontend-edit-field>
                    `})}
                <div class="child-actions">
                    <button type="button" ?disabled="${(o==null?void 0:o.busy)??!1}" @click="${()=>void this.addChild(e)}">
                        ${this.text(p("add"))}
                    </button>
                </div>
            </div>
        `}renderGeneralErrors(e){let t=ue(this.edits,e);return t.length===0?_:u`
            <ul class="errors" role="alert">
                ${t.map(n=>u`<li>${n}</li>`)}
            </ul>
        `}beginField(e,t,n){this.edits=pe(this.edits,t,n,C(e,t,n)),this.pendingFocus=P(t,n)}onInput(e,t,n){this.edits=he(this.edits,e,t,n)}cancelField(e,t){var n;if(((n=a(this.edits,e))==null?void 0:n.mode)==="record"){this.cancelRecord(e);return}this.edits=z(this.edits,e,t)}async applyField(e,t){if(I(e)){await this.addChild(e.child);return}let n=a(this.edits,e);if(n===null||n.busy)return;if(n.mode==="record"){await this.submitRecord(e);return}let o=k(this.edits,e,t,"");await this.send(e,"saveField",d=>Re(d.uid,e,t,o),()=>{this.edits=z(this.edits,e,t)})}beginRecord(e,t){let n=se(e,t);this.edits=fe(this.edits,t,n);let o=Object.keys(n).at(0);this.pendingFocus=o===void 0?null:P(t,o)}cancelRecord(e){this.edits=w(this.edits,e)}async submitRecord(e){let t=a(this.edits,e);if(t===null||t.busy)return;let n=this.draftValues(e);await this.send(e,"save",o=>ve(o.uid,e,n),()=>{this.edits=w(this.edits,e)})}async addChild(e){let t=K(e);if(j(this.edits,t))return;let n=this.draftValues(t);await this.send(t,"addChild",o=>Te(o.uid,e,n),()=>{this.edits=w(this.edits,t)})}async deleteChild(e,t){let n=$(e,t);await this.send(n,"removeChild",o=>xe(o.uid,e,t),()=>{this.edits=w(this.edits,n)})}async moveChild(e,t,n){let o=this.profile;if(o===null)return;let d=ae(o,e,t,n);if(Ye(d,q(o,e)))return;let s=$(e,t);await this.send(s,"reorderChildren",f=>$e(f.uid,e,d),()=>{this.pendingFocus=null})}async setChildVisibility(e,t,n){let o=$(e,t);await this.send(o,"setChildVisibility",d=>Ce(d.uid,e,t,n),()=>{this.pendingFocus=null})}async send(e,t,n,o){let d=this.client,s=this.profile;if(d===null||s===null||j(this.edits,e))return;this.edits=J(X(this.edits,e),e,!0);let f=await d.send(t,n(s));this.edits=J(this.edits,e,!1),this.applyResult(f,e,o)}applyResult(e,t,n){if(e.kind==="success"){this.profile=e.profile,this.edits=X(this.edits,t),n();return}if(e.kind==="validation"){this.edits=G(this.edits,t,e.fieldErrors,e.generalErrors);let o=Object.keys(e.fieldErrors).at(0);this.pendingFocus=o===void 0?null:P(t,o);return}this.edits=G(this.edits,t,{},[this.requestErrorText(e.status)])}draftValues(e){let t=this.profile,n=S(x(e)),o={};for(let d of x(e)){let s=t===null||I(e)?n[d.name]??"":C(t,e,d.name);o[d.name]=k(this.edits,e,d.name,s)}return o}requestErrorText(e){let t=`error.request.${e}`;return this.labels[t]??this.text("error.request")}text(e){return m(this.labels,e)}};g.styles=ze`
        :host {
            display: block;
        }

        .record {
            display: grid;
            gap: 0.5rem;
            padding: 0.5rem 0;
        }

        .record-actions,
        .child-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .children {
            border-top: 1px solid currentColor;
            margin-top: 1rem;
            padding-top: 0.5rem;
        }

        .children-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.75rem;
        }

        .child {
            border-inline-start: 3px solid currentColor;
            padding-inline-start: 0.75rem;
        }

        .child-new {
            border-inline-start-style: dashed;
        }

        .state {
            font-size: 0.875em;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .errors {
            margin: 0;
            padding: 0;
            list-style: none;
            color: #a4141a;
        }

        button {
            font: inherit;
        }
    `,l([ee()],g.prototype,"profile",2),l([ee()],g.prototype,"edits",2),l([ee()],g.prototype,"labels",2),g=l([Ge("modern-extbase-frontend-edit-profile")],g);function P(r,i){return`${T(r)}|${i}`}function Ye(r,i){return r.length===i.length&&r.every((e,t)=>e===i[t])}D(document.documentElement);export{re as assetsLoadedClass,D as markAssetsLoaded};
