/* Generated from Build/Sources/TypeScript — do not edit. */
var Ne=Object.defineProperty;var He=Object.getOwnPropertyDescriptor;var l=(r,i,e,t)=>{for(var n=t>1?void 0:t?He(i,e):i,o=r.length-1,s;o>=0;o--)(s=r[o])&&(n=(t?s(i,e,n):s(n))||n);return t&&n&&Ne(i,e,n),n};var ae="frontend-edit-loaded";function _(r){r.classList.add(ae)}import{css as fr,html as f,LitElement as mr,nothing as z}from"lit";import{customElement as hr,state as J}from"lit/decorators.js";import{repeat as yr}from"lit/directives/repeat.js";var ce=["address","email"];var V=Object.freeze({child:null,childUid:null});function P(r,i){return{child:r,childUid:i}}function X(r){return{child:r,childUid:null}}function Y(r){return r.child!==null&&r.childUid===null}function T(r){return r.child===null?"profile":`${r.child}:${r.childUid??"new"}`}function ue(r){return r.child??"profile"}var ze=[{name:"shortname",control:"line",maxLength:255},{name:"firstname",control:"line",maxLength:255},{name:"lastname",control:"line",maxLength:255},{name:"birthday",control:"date"},{name:"bio",control:"text",maxLength:5e3}],Je=[{name:"type",control:"choice",choices:["home","work","others"],initial:"others"},{name:"line1",control:"line",maxLength:255},{name:"line2",control:"line",maxLength:255}],Ge=[{name:"type",control:"choice",choices:["private","business","others"],initial:"others"},{name:"email",control:"line",maxLength:255}];function D(r){switch(r){case"address":return Je;case"email":return Ge;default:return ze}}function $(r){return D(r.child)}function Q(r){let i={};for(let e of r)i[e.name]=e.initial??"";return i}function I(r){if(!B(r))return null;let i=k(r.uid);return i===null?null:{uid:i,shortname:a(r.shortname),firstname:a(r.firstname),lastname:a(r.lastname),birthday:a(r.birthday),bio:a(r.bio),hidden:r.hidden===!0,image:_e(r.image),addresses:pe(r.addresses,Xe),emails:pe(r.emails,Ye)}}function _e(r){if(!B(r))return null;let i=k(r.uid);return i===null?null:{uid:i,fileUid:k(r.fileUid)??0,publicUrl:a(r.publicUrl),name:a(r.name),extension:a(r.extension),mimeType:a(r.mimeType),size:W(r.size)??0,title:a(r.title),alternative:a(r.alternative),width:W(r.width),height:W(r.height)}}function fe(r){let i=`${r.firstname} ${r.lastname}`.trim();return i===""?r.shortname:i}function Xe(r){if(!B(r))return null;let i=k(r.uid);return i===null?null:{uid:i,type:a(r.type),line1:a(r.line1),line2:a(r.line2),hidden:r.hidden===!0}}function Ye(r){if(!B(r))return null;let i=k(r.uid);return i===null?null:{uid:i,type:a(r.type),email:a(r.email),hidden:r.hidden===!0}}function Qe(r,i){return i.child===null?r:i.childUid===null?null:me(r,i.child,i.childUid)}function me(r,i,e){return K(r,i).find(t=>t.uid===e)??null}function K(r,i){return i==="address"?r.addresses:r.emails}function Z(r,i){return K(r,i).map(e=>e.uid)}function M(r,i,e){let t=Qe(r,i);if(t===null)return"";let n=t[e];return typeof n=="string"?n:""}function he(r,i){let e={};for(let t of $(i))e[t.name]=M(r,i,t.name);return e}function ye(r,i,e){var t;return((t=me(r,i,e))==null?void 0:t.hidden)??!1}function ge(r,i,e,t){let n=Z(r,i),o=n.indexOf(e);if(o===-1)return n;let s=o+t;if(s<0||s>=n.length)return n;let d=[...n];return d.splice(o,1),d.splice(s,0,e),d}function pe(r,i){if(!Array.isArray(r))return[];let e=[];for(let t of r){let n=i(t);n!==null&&e.push(n)}return e}function k(r){return typeof r=="number"&&Number.isInteger(r)&&r>0?r:null}function W(r){return typeof r=="number"&&Number.isFinite(r)?r:null}function a(r){return typeof r=="string"?r:""}function B(r){return typeof r=="object"&&r!==null&&!Array.isArray(r)}var L="image",be="image/jpeg,image/png,image/gif,image/webp";function Re(r){return r!==null&&r.publicUrl!==""}function ve(r,i,e){return r.alternative!==""?r.alternative:i.replace("%s",e)}function xe(r,i){return i===""||r.includes(i)?[...r]:[...r,i]}var j={mode:"field",fields:[],drafts:{},fieldErrors:{},generalErrors:[],busy:!1};function Te(){return new Map}function c(r,i){return r.get(T(i))??null}function U(r,i){var e;return((e=c(r,i))==null?void 0:e.busy)??!1}function F(r,i,e,t){let n=c(r,i);return n===null||!(e in n.drafts)?t:n.drafts[e]??t}function q(r,i,e){var t;return((t=c(r,i))==null?void 0:t.fieldErrors[e])??[]}function $e(r,i){var e;return((e=c(r,i))==null?void 0:e.generalErrors)??[]}function Ce(r,i,e,t){let n=c(r,i)??j;return n.fields.includes(e)?r:v(r,i,{...n,fields:[...n.fields,e],drafts:{...n.drafts,[e]:t},fieldErrors:ee(n.fieldErrors,e)})}function Ee(r,i,e){return v(r,i,{...j,mode:"record",fields:Object.keys(e),drafts:{...e}})}function we(r,i,e,t){let n=c(r,i)??j;return v(r,i,{...n,drafts:{...n.drafts,[e]:t}})}function re(r,i,e){let t=c(r,i);return t===null?r:v(r,i,{...t,fields:t.fields.filter(n=>n!==e),drafts:ee(t.drafts,e),fieldErrors:ee(t.fieldErrors,e)})}function A(r,i){let e=new Map(r);return e.delete(T(i)),e}function te(r,i,e){let t=c(r,i)??j;return v(r,i,{...t,busy:e})}function ie(r,i,e,t){let n=c(r,i)??j;return v(r,i,{...n,fieldErrors:{...e},generalErrors:[...t]})}function ne(r,i){let e=c(r,i);return e===null?r:v(r,i,{...e,fieldErrors:{},generalErrors:[]})}function v(r,i,e){let t=new Map(r),n=T(i);return e.fields.length===0&&Object.keys(e.drafts).length===0&&e.generalErrors.length===0&&Object.keys(e.fieldErrors).length===0&&!e.busy?(t.delete(n),t):(t.set(n,e),t)}function ee(r,i){let e={...r};return delete e[i],e}function Pe(r){if(typeof r!="object"||r===null||Array.isArray(r))return{};let i={};for(let[e,t]of Object.entries(r))typeof t=="string"&&t!==""&&(i[e]=t);return i}function y(r,i){return r[i]??i}function S(r,i){return`field.${r}.${i}`}function oe(r,i,e){return`choice.${r}.${i}.${e}`}function u(r){return`action.${r}`}function ke(r){return`section.${r}`}function se(r){return`state.${r}`}function N(r){if(r===null||r.trim()==="")return null;try{return JSON.parse(r)}catch{return null}}var We=["save","saveField","addChild","removeChild","reorderChildren","setChildVisibility","uploadImage","removeImage"];function Me(r){if(typeof r!="object"||r===null||Array.isArray(r))return null;let i=r,e={};for(let t of We){let n=i[t];if(typeof n!="string"||n==="")return null;e[t]=n}return e}function Le(r,i,e,t){return{...Be(r,i),field:e,value:t}}function je(r,i,e){return{...Be(r,i),data:{...e}}}function Fe(r,i,e){return{uid:r,child:i,data:{...e}}}function Ae(r,i,e){return{uid:r,child:i,childUid:e}}function Oe(r,i,e){return{uid:r,child:i,order:[...e]}}function Ve(r,i,e,t){return{uid:r,child:i,childUid:e,hidden:t}}var De="tx_modernextbasefrontendedit_ajax",Ze=`${De}[profile][image]`,er=`${De}[uid]`;function Ie(r,i){let e=new FormData;return e.append(er,String(r)),e.append(Ze,i,i.name),e}function Ke(r){return{uid:r}}function Be(r,i){return i.child===null?{uid:r}:i.childUid===null?{uid:r,child:i.child}:{uid:r,child:i.child,childUid:i.childUid}}var Ue=0;function qe(r,i){if(r===200){let e=I(tr(i));return e===null?{kind:"error",status:r,codes:le(i)}:{kind:"success",profile:e}}if(r===422){let e=rr(i);return Object.keys(e.fieldErrors).length===0&&e.generalErrors.length===0?{kind:"error",status:r,codes:le(i)}:{kind:"validation",...e}}return{kind:"error",status:r,codes:le(i)}}function rr(r){let i={},e=[];for(let t of Se(r)){let n=t.message;if(typeof n!="string"||n==="")continue;let o=t.field;if(typeof o!="string"||o===""){e.push(n);continue}(i[o]??=[]).push(n)}return{fieldErrors:i,generalErrors:e}}function le(r){let i=[];for(let e of Se(r))typeof e.code=="number"&&i.push(e.code);return i}function Se(r){return!de(r)||!Array.isArray(r.errors)?[]:r.errors.filter(de)}function tr(r){return de(r)?r.data:null}function de(r){return typeof r=="object"&&r!==null&&!Array.isArray(r)}var H=class{constructor(i,e,t=(n,o)=>fetch(n,o)){this.endpoints=i,this.requestToken=e,this.fetchImpl=t}async send(i,e){let t;try{t=await this.fetchImpl(this.endpoints[i],{method:"POST",credentials:"same-origin",headers:ir(e,this.requestToken),body:e instanceof FormData?e:JSON.stringify(e)})}catch{return{kind:"error",status:Ue,codes:[]}}return qe(t.status,await nr(t))}};function ir(r,i){let e={Accept:"application/json","X-TYPO3-RequestToken":i};return r instanceof FormData||(e["Content-Type"]="application/json"),e}async function nr(r){try{return await r.json()}catch{return null}}import{css as or,html as g,LitElement as sr,nothing as x}from"lit";import{customElement as lr,property as b,query as dr}from"lit/decorators.js";var p=class extends sr{constructor(){super(...arguments);this.definition={name:"",control:"line"};this.scope="profile";this.labels={};this.serverValue="";this.draftValue="";this.editing=!1;this.busy=!1;this.recordMode=!1;this.errors=[]}render(){let e=this.errors.length>0;return g`
            <div class="field">
                <span class="field-label" id="label">${y(this.labels,S(this.scope,this.definition.name))}</span>
                <div class="field-body">
                    ${this.editing?this.renderControl(e):this.renderValue()}
                    ${this.renderActions()}
                </div>
                ${e?this.renderErrors():x}
            </div>
        `}focusControl(){var e;(e=this.control)==null||e.focus()}updated(){if(!this.editing||this.definition.control!=="choice")return;let e=this.control;e instanceof HTMLSelectElement&&e.value!==this.draftValue&&(e.value=this.draftValue)}renderValue(){let e=this.displayValue();return g`<span class="field-value ${e===""?"is-empty":""}">${e}</span>`}displayValue(){return this.definition.control!=="choice"||this.serverValue===""?this.serverValue:y(this.labels,oe(this.scope,this.definition.name,this.serverValue))}renderControl(e){let t={invalid:e?"true":"false",describedBy:e?"errors":void 0};return this.definition.control==="choice"?g`
                <select
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${t.invalid}"
                    aria-describedby="${t.describedBy??x}"
                    ?disabled="${this.busy}"
                    @change="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                >
                    ${(this.definition.choices??[]).map(n=>g`
                        <option value="${n}" ?selected="${n===this.draftValue}">
                            ${y(this.labels,oe(this.scope,this.definition.name,n))}
                        </option>
                    `)}
                </select>
            `:this.definition.control==="text"?g`
                <textarea
                    class="field-control"
                    aria-labelledby="label"
                    aria-invalid="${t.invalid}"
                    aria-describedby="${t.describedBy??x}"
                    maxlength="${this.definition.maxLength??x}"
                    ?disabled="${this.busy}"
                    .value="${this.draftValue}"
                    @input="${this.onControlInput}"
                    @keydown="${this.onKeyDown}"
                ></textarea>
            `:g`
            <input
                class="field-control"
                type="${this.definition.control==="date"?"date":"text"}"
                aria-labelledby="label"
                aria-invalid="${t.invalid}"
                aria-describedby="${t.describedBy??x}"
                maxlength="${this.definition.maxLength??x}"
                ?disabled="${this.busy}"
                .value="${this.draftValue}"
                @input="${this.onControlInput}"
                @keydown="${this.onKeyDown}"
            />
        `}renderActions(){return this.recordMode?x:this.editing?g`
            <span class="field-actions">
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onApply}">
                    ${y(this.labels,u("apply"))}
                </button>
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onCancel}">
                    ${y(this.labels,u("cancel"))}
                </button>
            </span>
        `:g`
                <button type="button" aria-describedby="label" ?disabled="${this.busy}" @click="${this.onEdit}">
                    ${y(this.labels,u("edit"))}
                </button>
            `}renderErrors(){return g`
            <ul class="field-errors" id="errors" role="alert">
                ${this.errors.map(e=>g`<li>${e}</li>`)}
            </ul>
        `}onControlInput(e){let t=e.target;this.emit("field-input",{value:t.value})}onKeyDown(e){if(e.key==="Escape"){e.preventDefault(),this.emit("field-cancel");return}e.key==="Enter"&&this.definition.control!=="text"&&(e.preventDefault(),this.emit("field-apply"))}onEdit(){this.emit("field-edit")}onApply(){this.emit("field-apply")}onCancel(){this.emit("field-cancel")}emit(e,t={}){this.dispatchEvent(new CustomEvent(e,{detail:{field:this.definition.name,...t},bubbles:!0,composed:!0}))}};p.styles=or`
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
    `,l([b({attribute:!1})],p.prototype,"definition",2),l([b({type:String})],p.prototype,"scope",2),l([b({attribute:!1})],p.prototype,"labels",2),l([b({type:String})],p.prototype,"serverValue",2),l([b({type:String})],p.prototype,"draftValue",2),l([b({type:Boolean,reflect:!0})],p.prototype,"editing",2),l([b({type:Boolean,reflect:!0})],p.prototype,"busy",2),l([b({type:Boolean})],p.prototype,"recordMode",2),l([b({attribute:!1})],p.prototype,"errors",2),l([dr(".field-control")],p.prototype,"control",2),p=l([lr("modern-extbase-frontend-edit-field")],p);import{css as ar,html as C,LitElement as cr,nothing as O}from"lit";import{customElement as ur,property as E,query as pr}from"lit/decorators.js";var h=class extends cr{constructor(){super(...arguments);this.image=null;this.labels={};this.profileName="";this.busy=!1;this.errors=[];this.rejected=!1}render(){let e=xe(this.errors,this.rejected?this.text("error.imageNotStored"):"");return C`
            <div class="field">
                <span class="field-label" id="label">${this.text(S("profile",L))}</span>
                <div class="field-body">
                    ${this.renderImage()}
                    <span class="field-actions">
                        <input
                            class="field-control"
                            type="file"
                            accept="${be}"
                            aria-labelledby="label"
                            aria-invalid="${e.length>0?"true":"false"}"
                            aria-describedby="${e.length>0?"errors":O}"
                            ?disabled="${this.busy}"
                            @change="${this.onSelect}"
                        />
                        <button
                            type="button"
                            aria-describedby="label"
                            ?disabled="${this.busy||this.image===null}"
                            @click="${this.onRemove}"
                        >
                            ${this.text(u("remove"))}
                        </button>
                    </span>
                </div>
                ${e.length>0?this.renderErrors(e):O}
            </div>
        `}focusControl(){var e;(e=this.control)==null||e.focus()}renderImage(){let e=this.image;return Re(e)?C`
            <figure class="field-value">
                <img
                    src="${e.publicUrl}"
                    alt="${ve(e,this.text("profile.image.alt"),this.profileName)}"
                    width="${e.width??O}"
                    height="${e.height??O}"
                    loading="lazy"
                />
                ${e.title===""?O:C`<figcaption>${e.title}</figcaption>`}
            </figure>
        `:C`<span class="field-value is-empty"></span>`}renderErrors(e){return C`
            <ul class="field-errors" id="errors" role="alert">
                ${e.map(t=>C`<li>${t}</li>`)}
            </ul>
        `}onSelect(e){var o;let t=e.target,n=((o=t.files)==null?void 0:o.item(0))??null;t.value="",n!==null&&this.dispatchEvent(new CustomEvent("image-select",{detail:{file:n},bubbles:!0,composed:!0}))}onRemove(){this.dispatchEvent(new CustomEvent("image-remove",{bubbles:!0,composed:!0}))}text(e){return y(this.labels,e)}};h.styles=ar`
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
        }

        .field-value.is-empty::after {
            content: '—';
        }

        figure {
            margin: 0;
        }

        /*
         * The stored dimensions are written as attributes so the layout does not
         * jump while the image loads, and bounded here because a portrait
         * straight from a camera is wider than the surface.
         */
        img {
            display: block;
            max-width: 12rem;
            height: auto;
        }

        figcaption {
            font-size: 0.875em;
        }

        input {
            font: inherit;
        }

        button {
            font: inherit;
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
    `,l([E({attribute:!1})],h.prototype,"image",2),l([E({attribute:!1})],h.prototype,"labels",2),l([E({type:String})],h.prototype,"profileName",2),l([E({type:Boolean,reflect:!0})],h.prototype,"busy",2),l([E({attribute:!1})],h.prototype,"errors",2),l([E({type:Boolean})],h.prototype,"rejected",2),l([pr(".field-control")],h.prototype,"control",2),h=l([ur("modern-extbase-frontend-edit-image")],h);var R=class extends mr{constructor(){super(...arguments);this.profile=null;this.edits=Te();this.labels={};this.imageRejected=!1;this.client=null;this.pendingFocus=null}connectedCallback(){super.connectedCallback(),this.initialize()}render(){let e=this.profile;return e===null?f`<slot></slot>`:f`
            ${this.renderRecord(e,V)}
            ${ce.map(t=>this.renderChildren(e,t))}
        `}updated(){let e=this.pendingFocus;if(e===null)return;this.pendingFocus=null;let t=this.renderRoot.querySelector(`[data-focus="${e}"]`);t!==null&&t.updateComplete.then(()=>{t.focusControl()})}initialize(){if(this.client!==null)return;let e=I(N(this.getAttribute("data-profile"))),t=Me(N(this.getAttribute("data-endpoints"))),n=this.getAttribute("data-token")??"";e===null||t===null||n===""||(this.labels=Pe(N(this.getAttribute("data-labels"))),this.client=new H(t,n),this.profile=e)}renderRecord(e,t){let n=c(this.edits,t);return f`
            <div class="record">
                <div class="record-actions">
                    ${this.renderRecordActions(e,t,n)}
                    ${t.child===null&&e.hidden?f`<span class="state">${this.text(se("hidden"))}</span>`:z}
                </div>
                ${this.renderGeneralErrors(t)}
                ${t.child===null?this.renderImage(e,t,n):z}
                ${$(t).map(o=>this.renderField(e,t,o,n))}
            </div>
        `}renderImage(e,t,n){return f`
            <modern-extbase-frontend-edit-image
                data-focus="${w(t,L)}"
                .image="${e.image}"
                .labels="${this.labels}"
                .profileName="${fe(e)}"
                .busy="${(n==null?void 0:n.busy)??!1}"
                .errors="${q(this.edits,t,L)}"
                .rejected="${this.imageRejected}"
                @image-select="${o=>void this.uploadImage(o.detail.file)}"
                @image-remove="${()=>void this.clearImage()}"
            ></modern-extbase-frontend-edit-image>
        `}renderRecordActions(e,t,n){return(n==null?void 0:n.mode)==="record"?f`
                <button type="button" ?disabled="${n.busy}" @click="${()=>void this.submitRecord(t)}">
                    ${this.text(u("save"))}
                </button>
                <button type="button" ?disabled="${n.busy}" @click="${()=>this.cancelRecord(t)}">
                    ${this.text(u("cancel"))}
                </button>
            `:f`
            <button
                type="button"
                ?disabled="${(n==null?void 0:n.busy)??!1}"
                @click="${()=>this.beginRecord(e,t)}"
            >
                ${this.text(u("editRecord"))}
            </button>
        `}renderField(e,t,n,o){let s=n.name,d=M(e,t,s);return f`
            <modern-extbase-frontend-edit-field
                data-focus="${w(t,s)}"
                .definition="${n}"
                .scope="${ue(t)}"
                .labels="${this.labels}"
                .serverValue="${d}"
                .draftValue="${F(this.edits,t,s,d)}"
                .editing="${(o==null?void 0:o.fields.includes(s))??!1}"
                .busy="${(o==null?void 0:o.busy)??!1}"
                .recordMode="${(o==null?void 0:o.mode)==="record"}"
                .errors="${q(this.edits,t,s)}"
                @field-edit="${()=>this.beginField(e,t,s)}"
                @field-input="${m=>this.onInput(t,s,m.detail.value)}"
                @field-apply="${()=>void this.applyField(t,s)}"
                @field-cancel="${()=>this.cancelField(t,s)}"
            ></modern-extbase-frontend-edit-field>
        `}renderChildren(e,t){let n=K(e,t);return f`
            <section class="children">
                <h3>${this.text(ke(t))}</h3>
                <ol class="children-list">
                    ${yr(n,o=>o.uid,(o,s)=>this.renderChild(e,t,o,s,n.length))}
                </ol>
                ${this.renderNewChild(t)}
            </section>
        `}renderChild(e,t,n,o,s){let d=P(t,n.uid),m=U(this.edits,d),G=ye(e,t,n.uid);return f`
            <li class="child">
                ${this.renderRecord(e,d)}
                <div class="child-actions">
                    <button
                        type="button"
                        ?disabled="${m||o===0}"
                        @click="${()=>void this.moveChild(t,n.uid,-1)}"
                    >
                        ${this.text(u("moveUp"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${m||o===s-1}"
                        @click="${()=>void this.moveChild(t,n.uid,1)}"
                    >
                        ${this.text(u("moveDown"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${m}"
                        @click="${()=>void this.setChildVisibility(t,n.uid,!G)}"
                    >
                        ${this.text(u(G?"show":"hide"))}
                    </button>
                    <button
                        type="button"
                        ?disabled="${m}"
                        @click="${()=>void this.deleteChild(t,n.uid)}"
                    >
                        ${this.text(u("remove"))}
                    </button>
                    ${G?f`<span class="state">${this.text(se("hidden"))}</span>`:z}
                </div>
            </li>
        `}renderNewChild(e){let t=X(e),n=Q(D(e)),o=c(this.edits,t);return f`
            <div class="child child-new">
                ${this.renderGeneralErrors(t)}
                ${D(e).map(s=>{let d=s.name;return f`
                        <modern-extbase-frontend-edit-field
                            data-focus="${w(t,d)}"
                            .definition="${s}"
                            .scope="${e}"
                            .labels="${this.labels}"
                            .serverValue="${""}"
                            .draftValue="${F(this.edits,t,d,n[d]??"")}"
                            .editing="${!0}"
                            .busy="${(o==null?void 0:o.busy)??!1}"
                            .recordMode="${!0}"
                            .errors="${q(this.edits,t,d)}"
                            @field-input="${m=>this.onInput(t,d,m.detail.value)}"
                            @field-apply="${()=>void this.addChild(e)}"
                            @field-cancel="${()=>this.cancelRecord(t)}"
                        ></modern-extbase-frontend-edit-field>
                    `})}
                <div class="child-actions">
                    <button type="button" ?disabled="${(o==null?void 0:o.busy)??!1}" @click="${()=>void this.addChild(e)}">
                        ${this.text(u("add"))}
                    </button>
                </div>
            </div>
        `}renderGeneralErrors(e){let t=$e(this.edits,e);return t.length===0?z:f`
            <ul class="errors" role="alert">
                ${t.map(n=>f`<li>${n}</li>`)}
            </ul>
        `}beginField(e,t,n){this.edits=Ce(this.edits,t,n,M(e,t,n)),this.pendingFocus=w(t,n)}onInput(e,t,n){this.edits=we(this.edits,e,t,n)}cancelField(e,t){var n;if(((n=c(this.edits,e))==null?void 0:n.mode)==="record"){this.cancelRecord(e);return}this.edits=re(this.edits,e,t)}async applyField(e,t){if(Y(e)){await this.addChild(e.child);return}let n=c(this.edits,e);if(n===null||n.busy)return;if(n.mode==="record"){await this.submitRecord(e);return}let o=F(this.edits,e,t,"");await this.send(e,"saveField",s=>Le(s.uid,e,t,o),()=>{this.edits=re(this.edits,e,t)})}beginRecord(e,t){let n=he(e,t);this.edits=Ee(this.edits,t,n);let o=Object.keys(n).at(0);this.pendingFocus=o===void 0?null:w(t,o)}cancelRecord(e){this.edits=A(this.edits,e)}async submitRecord(e){let t=c(this.edits,e);if(t===null||t.busy)return;let n=this.draftValues(e);await this.send(e,"save",o=>je(o.uid,e,n),()=>{this.edits=A(this.edits,e)})}async addChild(e){let t=X(e);if(U(this.edits,t))return;let n=this.draftValues(t);await this.send(t,"addChild",o=>Fe(o.uid,e,n),()=>{this.edits=A(this.edits,t)})}async deleteChild(e,t){let n=P(e,t);await this.send(n,"removeChild",o=>Ae(o.uid,e,t),()=>{this.edits=A(this.edits,n)})}async moveChild(e,t,n){let o=this.profile;if(o===null)return;let s=ge(o,e,t,n);if(gr(s,Z(o,e)))return;let d=P(e,t);await this.send(d,"reorderChildren",m=>Oe(m.uid,e,s),()=>{this.pendingFocus=null})}async setChildVisibility(e,t,n){let o=P(e,t);await this.send(o,"setChildVisibility",s=>Ve(s.uid,e,t,n),()=>{this.pendingFocus=null})}async uploadImage(e){this.imageRejected=!1;let t=await this.send(V,"uploadImage",n=>Ie(n.uid,e),()=>{this.pendingFocus=null});this.imageRejected=t!==null&&t.kind!=="success"}async clearImage(){this.imageRejected=!1,await this.send(V,"removeImage",e=>Ke(e.uid),()=>{this.pendingFocus=null})}async send(e,t,n,o){let s=this.client,d=this.profile;if(s===null||d===null||U(this.edits,e))return null;this.edits=te(ne(this.edits,e),e,!0);let m=await s.send(t,n(d));return this.edits=te(this.edits,e,!1),this.applyResult(m,e,o),m}applyResult(e,t,n){if(e.kind==="success"){this.profile=e.profile,this.edits=ne(this.edits,t),n();return}if(e.kind==="validation"){this.edits=ie(this.edits,t,e.fieldErrors,e.generalErrors);let o=Object.keys(e.fieldErrors).at(0);this.pendingFocus=o===void 0?null:w(t,o);return}this.edits=ie(this.edits,t,{},[this.requestErrorText(e.status)])}draftValues(e){let t=this.profile,n=Q($(e)),o={};for(let s of $(e)){let d=t===null||Y(e)?n[s.name]??"":M(t,e,s.name);o[s.name]=F(this.edits,e,s.name,d)}return o}requestErrorText(e){let t=`error.request.${e}`;return this.labels[t]??this.text("error.request")}text(e){return y(this.labels,e)}};R.styles=fr`
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
    `,l([J()],R.prototype,"profile",2),l([J()],R.prototype,"edits",2),l([J()],R.prototype,"labels",2),l([J()],R.prototype,"imageRejected",2),R=l([hr("modern-extbase-frontend-edit-profile")],R);function w(r,i){return`${T(r)}|${i}`}function gr(r,i){return r.length===i.length&&r.every((e,t)=>e===i[t])}_(document.documentElement);export{ae as assetsLoadedClass,_ as markAssetsLoaded};
